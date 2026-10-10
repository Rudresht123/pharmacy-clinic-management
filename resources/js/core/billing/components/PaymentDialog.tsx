import { useEffect, useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { resolveErrorMessage } from '@/shared/api/http';
import { newIdempotencyKey } from '@/shared/utils/idempotency';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    PAYMENT_METHOD_LABELS,
    useBillingSettings,
    useRecordPayment,
    type Invoice,
    type InvoicePayment,
} from '../api';

interface TenderRow {
    method: string;
    amount: string;
    reference: string;
}

/** At most this many tenders in one collection — the server's own limit. */
const MAX_TENDERS = 5;

/**
 * Taking money against a bill, and printing the receipt for it.
 *
 * ONE RECEIPT PER PAYMENT, not per invoice — a bill settled in two tenders
 * produces two receipts, each proving what was actually handed over. The
 * receipt is offered immediately after the payment lands, because the
 * patient is standing there.
 *
 * SPLIT TENDERS. ₹300 cash and ₹200 UPI is one collection with two tenders,
 * sent together — which is what lets a clinic that takes bills in full still
 * take that bill.
 *
 * THE ORGANISATION'S RULE IS SHOWN, NOT DISCOVERED. Under "pay in full" the
 * amounts must come to the whole balance, and the dialog says so before the
 * server has to refuse. The server enforces it either way.
 *
 * ONE KEY PER DIALOG. Made when the dialog opens for a bill and kept until it
 * closes, so a double-tapped "Record payment" — or a retry after a dropped
 * connection — records the money once.
 *
 * Shared by the collection list, the invoice screen and anything else that
 * collects on an invoice, so none of them can drift into taking payment
 * differently.
 */
export function PaymentDialog({
    invoice,
    onClose,
    onPaid,
}: {
    invoice: Invoice | undefined;
    onClose: () => void;
    onPaid?: (invoice: Invoice) => void;
}) {
    const { data: settings } = useBillingSettings();
    const { can } = useTenantAuth();
    const record = useRecordPayment();
    const generate = useGenerateDocument();

    const methods = settings?.payment_methods ?? ['cash', 'upi', 'card'];
    const inFull = settings?.payment_behaviour === 'full';

    const [tenders, setTenders] = useState<TenderRow[]>([]);
    const [key, setKey] = useState(newIdempotencyKey);
    const [error, setError] = useState<string | null>(null);

    // The payments just taken, and the bill as they left it.
    const [recorded, setRecorded] = useState<{ invoice: Invoice; payments: InvoicePayment[] } | null>(null);

    /* Re-arm for whichever bill was opened, at its own outstanding figure. */
    useEffect(() => {
        if (invoice) {
            setTenders([{ method: methods[0] ?? 'cash', amount: invoice.outstanding.toFixed(2), reference: '' }]);
            setKey(newIdempotencyKey());
            setError(null);
            setRecorded(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [invoice?.id]);

    if (!invoice) return null;

    const total = Math.round(tenders.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0) * 100) / 100;
    const left = Math.round((invoice.outstanding - total) * 100) / 100;

    const problem =
        total <= 0
            ? 'Enter how much was paid.'
            : left < -0.001
              ? `That is ₹${Math.abs(left).toFixed(2)} more than is owed.`
              : inFull && left > 0.001
                ? `This clinic takes bills in full — ₹${left.toFixed(2)} is still to be entered.`
                : null;

    const set = (index: number, patch: Partial<TenderRow>) =>
        setTenders((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    function split() {
        setTenders((rows) => [
            ...rows,
            {
                method: methods.find((m) => !rows.some((row) => row.method === m)) ?? methods[0] ?? 'cash',
                amount: left > 0 ? left.toFixed(2) : '',
                reference: '',
            },
        ]);
    }

    /*
     * However the dialog is left once money has been taken — Done, the cross,
     * Escape — the caller hears that the bill was paid, so the list behind it
     * refreshes exactly as it did when the dialog closed on its own.
     */
    function finish() {
        if (recorded) {
            onPaid?.(recorded.invoice);
        } else {
            onClose();
        }
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setError(null);

        if (problem) {
            setError(problem);

            return;
        }

        const known = new Set((invoice!.payments ?? []).map((row) => row.id));

        try {
            const updated = await record.mutateAsync({
                invoiceId: invoice!.id,
                key,
                tenders: tenders
                    .filter((row) => (parseFloat(row.amount) || 0) > 0)
                    .map((row) => ({
                        method: row.method,
                        amount: parseFloat(row.amount),
                        reference: row.reference || null,
                    })),
            });

            // The payments on the bill that were not there before are the ones just taken.
            const taken = (updated.payments ?? []).filter((row) => !row.is_refund && !known.has(row.id));

            if (taken.length > 0) {
                setRecorded({ invoice: updated, payments: taken });
            } else {
                onPaid?.(updated);
            }
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    /** The bill, or the receipt for one payment just taken. */
    async function print(documentType: 'clinic_invoice' | 'clinic_receipt', subjectId: number) {
        setError(null);

        try {
            const document_ = await generate.mutateAsync({
                document_type: documentType,
                subject_id: subjectId,
            });

            await openDocument(document_);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    if (recorded) {
        return (
            <Modal open onClose={finish} title={`Payment recorded — ${invoice.invoice_number}`} size="md">
                {error && <div className="alert alert-danger py-2 mb-3">{error}</div>}

                {recorded.payments.map((payment) => (
                    <dl key={payment.id} className="row fs-13 mb-2">
                        <dt className="col-6">Received</dt>
                        <dd className="col-6 text-end tabular-nums fw-bold">₹{payment.amount.toFixed(2)}</dd>
                        <dt className="col-6">Mode</dt>
                        <dd className="col-6 text-end">{PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}</dd>
                        <dt className="col-6">Receipt no.</dt>
                        <dd className="col-6 text-end tabular-nums">
                            {payment.receipt_number}
                            {can('documents.generate') && (
                                <button
                                    type="button"
                                    className="btn btn-link btn-sm p-0 ms-2 align-baseline"
                                    onClick={() => void print('clinic_receipt', payment.id)}
                                    disabled={generate.isPending}
                                >
                                    Print
                                </button>
                            )}
                        </dd>
                    </dl>
                ))}

                <dl className="row fs-13 mb-3 border-top pt-2">
                    <dt className="col-6">Still owed</dt>
                    <dd className="col-6 text-end tabular-nums">₹{recorded.invoice.outstanding.toFixed(2)}</dd>
                </dl>

                <div className="mt-4 d-flex justify-content-end gap-2">
                    <Button type="button" icon="ti ti-check" onClick={finish}>
                        Done
                    </Button>
                </div>
            </Modal>
        );
    }

    return (
        <Modal open onClose={onClose} title={`Take payment — ${invoice.invoice_number}`} size="md">
            <form onSubmit={submit}>
                {error && <div className="alert alert-danger py-2 mb-3">{error}</div>}

                <dl className="row fs-13 mb-3">
                    <dt className="col-6">Patient</dt>
                    <dd className="col-6 text-end">{invoice.customer_name ?? 'Walk-in'}</dd>
                    <dt className="col-6">Bill total</dt>
                    <dd className="col-6 text-end tabular-nums">₹{invoice.total_amount.toFixed(2)}</dd>
                    <dt className="col-6">Already paid</dt>
                    <dd className="col-6 text-end tabular-nums">₹{invoice.paid_amount.toFixed(2)}</dd>
                    {(invoice.refunded_amount ?? 0) > 0 && (
                        <>
                            <dt className="col-6">Refunded</dt>
                            <dd className="col-6 text-end tabular-nums">₹{(invoice.refunded_amount ?? 0).toFixed(2)}</dd>
                        </>
                    )}
                    <dt className="col-6 fw-bold text-danger">Outstanding</dt>
                    <dd className="col-6 text-end tabular-nums fw-bold text-danger">
                        ₹{invoice.outstanding.toFixed(2)}
                    </dd>
                </dl>

                {inFull && (
                    <p className="fs-13 text-muted mb-2">
                        <i className="ti ti-info-circle me-1" />
                        This clinic takes bills in full. Split across methods if the patient pays in more than one
                        way.
                    </p>
                )}

                {tenders.map((row, index) => (
                    <div key={index} className="row g-2 mb-2 align-items-end">
                        <div className="col-4">
                            {index === 0 && <label className="form-label fs-13">Method</label>}
                            <select
                                className="form-select"
                                value={row.method}
                                onChange={(event) => set(index, { method: event.target.value })}
                                aria-label={`Method ${index + 1}`}
                            >
                                {methods.map((m) => (
                                    <option key={m} value={m}>
                                        {PAYMENT_METHOD_LABELS[m] ?? m}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="col-3">
                            {index === 0 && <label className="form-label fs-13">Amount</label>}
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                max={invoice.outstanding}
                                className="form-control"
                                value={row.amount}
                                onChange={(event) => set(index, { amount: event.target.value })}
                                aria-label={`Amount ${index + 1}`}
                                required
                                autoFocus={index === 0}
                            />
                        </div>

                        <div className={tenders.length > 1 ? 'col-4' : 'col-5'}>
                            {index === 0 && <label className="form-label fs-13">Reference (optional)</label>}
                            <input
                                type="text"
                                className="form-control"
                                placeholder="UPI txn id, card last 4, cheque no."
                                value={row.reference}
                                onChange={(event) => set(index, { reference: event.target.value })}
                                aria-label={`Reference ${index + 1}`}
                                maxLength={60}
                            />
                        </div>

                        {tenders.length > 1 && (
                            <div className="col-1 text-end">
                                <button
                                    type="button"
                                    className="btn btn-light btn-sm"
                                    aria-label={`Remove tender ${index + 1}`}
                                    onClick={() => setTenders((rows) => rows.filter((_, i) => i !== index))}
                                >
                                    <i className="ti ti-x" />
                                </button>
                            </div>
                        )}
                    </div>
                ))}

                <div className="d-flex justify-content-between align-items-center fs-13 mt-1">
                    {tenders.length < MAX_TENDERS && methods.length > 1 ? (
                        <button type="button" className="btn btn-link btn-sm p-0" onClick={split}>
                            <i className="ti ti-plus me-1" />
                            Split across another method
                        </button>
                    ) : (
                        <span />
                    )}
                    <span className={problem ? 'text-danger' : 'text-muted'}>
                        Taking ₹{total.toFixed(2)}
                        {left > 0.001 && ` · ₹${left.toFixed(2)} left owing`}
                    </span>
                </div>

                <div className="mt-4 d-flex justify-content-between gap-2">
                    <Button
                        variant="light"
                        icon="ti ti-printer"
                        type="button"
                        onClick={() => void print('clinic_invoice', invoice.id)}
                        disabled={generate.isPending}
                    >
                        Print bill
                    </Button>

                    <div className="d-flex gap-2">
                        <Button variant="light" onClick={onClose} type="button">
                            Cancel
                        </Button>
                        <Button type="submit" icon="ti ti-check" disabled={record.isPending || Boolean(problem)}>
                            {record.isPending ? 'Recording…' : 'Record payment'}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}
