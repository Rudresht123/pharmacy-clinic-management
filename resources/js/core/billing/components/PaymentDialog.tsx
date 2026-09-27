import { useEffect, useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { resolveErrorMessage } from '@/shared/api/http';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    PAYMENT_METHOD_LABELS,
    useBillingSettings,
    useRecordPayment,
    type Invoice,
} from '../api';

/**
 * Taking money against a bill, and printing the receipt for it.
 *
 * ONE RECEIPT PER PAYMENT, not per invoice — a bill settled in two tenders
 * produces two receipts, each proving what was actually handed over. The
 * receipt is offered immediately after the payment lands, because the
 * patient is standing there.
 *
 * Shared by the collection list and the invoice screen so the two cannot
 * drift into taking payment differently.
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
    const record = useRecordPayment();
    const generate = useGenerateDocument();

    const methods = settings?.payment_methods ?? ['cash', 'upi', 'card'];

    const [method, setMethod] = useState(methods[0] ?? 'cash');
    const [amount, setAmount] = useState('');
    const [reference, setReference] = useState('');
    const [error, setError] = useState<string | null>(null);

    /* Re-arm for whichever bill was opened, at its own outstanding figure. */
    useEffect(() => {
        if (invoice) {
            setAmount(invoice.outstanding.toFixed(2));
            setMethod(methods[0] ?? 'cash');
            setReference('');
            setError(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [invoice?.id]);

    if (!invoice) return null;

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setError(null);

        try {
            const updated = await record.mutateAsync({
                invoiceId: invoice!.id,
                payload: {
                    method,
                    amount: parseFloat(amount),
                    reference: reference || undefined,
                },
            });

            onPaid?.(updated);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    async function printReceipt() {
        setError(null);

        try {
            const document_ = await generate.mutateAsync({
                document_type: 'clinic_invoice',
                subject_id: invoice!.id,
            });

            await openDocument(document_);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
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
                    <dt className="col-6 fw-bold text-danger">Outstanding</dt>
                    <dd className="col-6 text-end tabular-nums fw-bold text-danger">
                        ₹{invoice.outstanding.toFixed(2)}
                    </dd>
                </dl>

                <div className="row g-3">
                    <div className="col-6">
                        <label className="form-label fs-13">Method</label>
                        <select
                            className="form-select"
                            value={method}
                            onChange={(event) => setMethod(event.target.value)}
                        >
                            {methods.map((m) => (
                                <option key={m} value={m}>
                                    {PAYMENT_METHOD_LABELS[m] ?? m}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="col-6">
                        <label className="form-label fs-13">Amount</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            max={invoice.outstanding}
                            className="form-control"
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                            required
                            autoFocus
                        />
                    </div>

                    <div className="col-12">
                        <label className="form-label fs-13">Reference (optional)</label>
                        <input
                            type="text"
                            className="form-control"
                            placeholder="UPI txn id, last 4 of card, cheque number"
                            value={reference}
                            onChange={(event) => setReference(event.target.value)}
                            maxLength={60}
                        />
                    </div>
                </div>

                <div className="mt-4 d-flex justify-content-between gap-2">
                    <Button
                        variant="light"
                        icon="ti ti-printer"
                        type="button"
                        onClick={() => void printReceipt()}
                        disabled={generate.isPending}
                    >
                        Print bill
                    </Button>

                    <div className="d-flex gap-2">
                        <Button variant="light" onClick={onClose} type="button">
                            Cancel
                        </Button>
                        <Button type="submit" icon="ti ti-check" disabled={record.isPending}>
                            {record.isPending ? 'Recording…' : 'Record payment'}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
}
