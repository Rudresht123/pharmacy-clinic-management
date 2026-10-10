import { useEffect, useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { resolveErrorMessage } from '@/shared/api/http';
import { newIdempotencyKey } from '@/shared/utils/idempotency';
import { usePharmacySettings } from '../api';
import { useCollectSalePayment, useReturnSaleItems, type Sale } from '../sales';
import { PAYMENT_METHOD_LABELS } from '../types';

const money = (value: number) => `₹${value.toFixed(2)}`;

/** The counter's accepted tenders — never credit, which is a policy, not a method. */
function useTenders(): string[] {
    const { data: settings } = usePharmacySettings();

    return (settings?.accepted_payment_methods ?? ['cash', 'upi', 'card']).filter((method) => method !== 'credit');
}

/**
 * Take the rest of a bill left owing — at the pharmacy counter.
 *
 * One tender; the pharmacy's credit setting decides whether part of what is
 * owed may be taken, and the server says so if not. One key per opening, so
 * a double-tap takes the money once.
 */
export function CollectSaleDialog({ sale, onClose }: { sale: Sale | undefined; onClose: () => void }) {
    const tenders = useTenders();
    const collect = useCollectSalePayment();

    const [method, setMethod] = useState('cash');
    const [amount, setAmount] = useState('');
    const [reference, setReference] = useState('');
    const [key, setKey] = useState(newIdempotencyKey);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (sale) {
            setMethod(tenders[0] ?? 'cash');
            setAmount(sale.amount_due.toFixed(2));
            setReference('');
            setKey(newIdempotencyKey());
            setError(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sale?.id]);

    if (!sale) return null;

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setError(null);

        try {
            await collect.mutateAsync({
                id: sale!.id,
                key,
                tenders: [{ method, amount: parseFloat(amount), reference: reference || null }],
            });
            onClose();
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    return (
        <Modal open onClose={onClose} title={`Take payment — ${sale.sale_number}`} size="md">
            <form onSubmit={submit}>
                {error && <div className="alert alert-danger py-2 mb-3">{error}</div>}

                <dl className="row fs-13 mb-3">
                    <dt className="col-6">Bill total</dt>
                    <dd className="col-6 text-end tabular-nums">{money(sale.total_amount)}</dd>
                    {(sale.returned_amount ?? 0) > 0 && (
                        <>
                            <dt className="col-6">Returned</dt>
                            <dd className="col-6 text-end tabular-nums">−{money(sale.returned_amount ?? 0)}</dd>
                        </>
                    )}
                    <dt className="col-6">Already paid</dt>
                    <dd className="col-6 text-end tabular-nums">{money(sale.paid_amount)}</dd>
                    <dt className="col-6 fw-bold text-danger">Owed</dt>
                    <dd className="col-6 text-end tabular-nums fw-bold text-danger">{money(sale.amount_due)}</dd>
                </dl>

                <div className="row g-2">
                    <div className="col-5">
                        <label className="form-label fs-13">Method</label>
                        <select className="form-select" value={method} onChange={(e) => setMethod(e.target.value)}>
                            {tenders.map((value) => (
                                <option key={value} value={value}>
                                    {PAYMENT_METHOD_LABELS[value] ?? value}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="col-7">
                        <label className="form-label fs-13">Amount</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            max={sale.amount_due}
                            className="form-control"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            required
                            autoFocus
                        />
                    </div>
                    <div className="col-12">
                        <label className="form-label fs-13">Reference (optional)</label>
                        <input
                            type="text"
                            className="form-control"
                            value={reference}
                            maxLength={60}
                            onChange={(e) => setReference(e.target.value)}
                            placeholder="UPI txn id, card last 4, cheque no."
                        />
                    </div>
                </div>

                <div className="mt-4 d-flex justify-content-end gap-2">
                    <Button variant="light" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" icon="ti ti-check" disabled={collect.isPending}>
                        {collect.isPending ? 'Taking…' : 'Take payment'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

/**
 * Goods brought back against a bill.
 *
 * Only what is left on each line can come back. The server values it as the
 * bill valued it, puts it back on its batch, and hands back only what had
 * been paid over the bill's new total — a credit sale just owes less.
 */
export function ReturnSaleDialog({ sale, onClose }: { sale: Sale | undefined; onClose: () => void }) {
    const tenders = useTenders();
    const returnItems = useReturnSaleItems();

    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [reason, setReason] = useState('');
    const [refundMethod, setRefundMethod] = useState('');
    const [key, setKey] = useState(newIdempotencyKey);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (sale) {
            setQuantities({});
            setReason('');
            setRefundMethod('');
            setKey(newIdempotencyKey());
            setError(null);
        }
    }, [sale?.id]);

    if (!sale) return null;

    const lines = (sale.items ?? []).map((item) => ({
        item,
        left: item.quantity - (item.returned_quantity ?? 0),
        asked: Math.max(0, Math.floor(Number(quantities[item.id] ?? 0)) || 0),
    }));

    // What it comes to, as the bill valued each line — the server's sum is the one kept.
    const value = lines.reduce((sum, line) => sum + (line.item.line_total * line.asked) / line.item.quantity, 0);
    const over = lines.some((line) => line.asked > line.left);
    const nothing = lines.every((line) => line.asked === 0);

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setError(null);

        try {
            await returnItems.mutateAsync({
                id: sale!.id,
                key,
                reason: reason.trim(),
                refundMethod: refundMethod || null,
                items: lines
                    .filter((line) => line.asked > 0)
                    .map((line) => ({ pharmacy_sale_item_id: line.item.id, quantity: line.asked })),
            });
            onClose();
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    return (
        <Modal open onClose={onClose} title={`Take a return — ${sale.sale_number}`} size="lg">
            <form onSubmit={submit}>
                {error && <div className="alert alert-danger py-2 mb-3">{error}</div>}

                <div className="pf-table-wrap">
                    <table className="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th className="text-end">Sold</th>
                                <th className="text-end">Already back</th>
                                <th className="text-end" style={{ width: 130 }}>
                                    Coming back
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map(({ item, left, asked }) => (
                                <tr key={item.id}>
                                    <td>
                                        {item.item_name}
                                        <span className="dr-sub d-block">Batch {item.batch_number}</span>
                                    </td>
                                    <td className="text-end tabular-nums">{item.quantity}</td>
                                    <td className="text-end tabular-nums">{item.returned_quantity ?? 0}</td>
                                    <td className="text-end">
                                        <input
                                            type="number"
                                            min={0}
                                            max={left}
                                            step={1}
                                            className={`form-control form-control-sm text-end${asked > left ? ' is-invalid' : ''}`}
                                            value={quantities[item.id] ?? ''}
                                            placeholder="0"
                                            disabled={left === 0}
                                            aria-label={`${item.item_name} coming back`}
                                            onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="row g-2 mt-3">
                    <div className="col-md-8">
                        <label className="form-label fs-13">Reason</label>
                        <input
                            type="text"
                            className="form-control"
                            value={reason}
                            maxLength={500}
                            required
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Dose changed by the doctor"
                        />
                    </div>
                    <div className="col-md-4">
                        <label className="form-label fs-13">Refund by</label>
                        <select className="form-select" value={refundMethod} onChange={(e) => setRefundMethod(e.target.value)}>
                            <option value="">The way it was paid</option>
                            {tenders.map((value) => (
                                <option key={value} value={value}>
                                    {PAYMENT_METHOD_LABELS[value] ?? value}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <p className="fs-13 text-muted mt-3 mb-0">
                    Coming back: about <b>{money(value)}</b>. The stock goes back on its batch. Money is handed back
                    only for what was paid over the bill&apos;s new total; on a bill still owed, the debt goes down
                    instead.
                </p>

                <div className="mt-4 d-flex justify-content-end gap-2">
                    <Button variant="light" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="danger"
                        icon="ti ti-arrow-back-up"
                        disabled={returnItems.isPending || nothing || over || reason.trim() === ''}
                    >
                        {returnItems.isPending ? 'Recording…' : 'Record return'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
