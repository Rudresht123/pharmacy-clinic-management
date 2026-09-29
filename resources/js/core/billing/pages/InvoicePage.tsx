import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { formatDate } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    INVOICE_STATUS_LABELS,
    PAYMENT_METHOD_LABELS,
    PAYMENT_STATUS_LABELS,
    useCancelInvoice,
    useFinalizeInvoice,
    useInvoice,
    useRefundPayment,
} from '../api';
import { PaymentDialog } from '../components/PaymentDialog';

/** Where a line came from, for the badge beside it. */
const SOURCE_LABELS: Record<string, { label: string; tone: string }> = {
    consultation: { label: 'Consultation', tone: 'indigo' },
    lab_test: { label: 'Laboratory', tone: 'teal' },
    pharmacy_sale_item: { label: 'Pharmacy', tone: 'rose' },
    procedure: { label: 'Procedure', tone: 'amber' },
    service: { label: 'Service', tone: 'secondary' },
    custom: { label: 'Other', tone: 'secondary' },
};

const money = (value: number) => `₹${value.toFixed(2)}`;

/**
 * One invoice, in full.
 *
 * Lines, running total and the payments taken against it — all here on the
 * one screen because that is what a counter reads while somebody pays. The
 * "Take payment" modal is a form, not another route, so the invoice stays
 * on screen and the outstanding figure updates as soon as the payment
 * lands.
 */
export default function InvoicePage() {
    const params = useParams<{ id: string }>();
    const navigate = useNavigate();
    const id = params.id ? Number(params.id) : undefined;

    const { can } = useTenantAuth();
    const canCollect = can('billing.collect_payment');
    const canCancel = can('billing.cancel');
    const canRefund = can('billing.refund');
    const canPrint = can('documents.generate');

    const { data: invoice, isLoading } = useInvoice(id);

    const [payingOpen, setPayingOpen] = useState(false);
    const [cancellingOpen, setCancellingOpen] = useState(false);
    const [refunding, setRefunding] = useState<number>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const cancel = useCancelInvoice();
    const refund = useRefundPayment();
    const finalize = useFinalizeInvoice();
    const generate = useGenerateDocument();

    async function closeBill() {
        if (!invoice) return;

        setRefusal(null);

        try {
            await finalize.mutateAsync(invoice.id);
            setPayingOpen(true);
        } catch (failure) {
            setRefusal(resolveErrorMessage(failure));
        }
    }

    async function printInvoice() {
        if (!invoice) return;

        setRefusal(null);

        try {
            const document_ = await generate.mutateAsync({
                document_type: 'clinic_invoice',
                subject_id: invoice.id,
            });

            await openDocument(document_);
        } catch (failure) {
            setRefusal(resolveErrorMessage(failure));
        }
    }

    if (isLoading || !invoice) {
        return <LoadingBlock label="Loading invoice…" />;
    }

    const tone =
        invoice.status === 'cancelled'
            ? 'danger'
            : invoice.status === 'paid'
              ? 'success'
              : 'info';

    async function confirmCancel(reason: string) {
        if (!invoice) return;

        setRefusal(null);

        try {
            await cancel.mutateAsync({ id: invoice.id, reason });
            setCancellingOpen(false);
        } catch (failure) {
            setRefusal(resolveErrorMessage(failure));
        }
    }

    return (
        <>
            <PageHeader
                title={invoice.invoice_number}
                subtitle={`${invoice.customer_name ?? 'Walk-in'} · ${formatDate(invoice.invoice_date)}`}
                icon="ti ti-receipt"
                tone="teal"
                crumbs={[
                    { label: 'Billing', to: '/billing' },
                    { label: 'Invoices', to: '/billing/invoices' },
                    { label: invoice.invoice_number },
                ]}
                actions={
                    <div className="d-flex gap-2">
                        <Button
                            variant="light"
                            icon="ti ti-arrow-left"
                            onClick={() => navigate('/billing/invoices')}
                            type="button"
                        >
                            Back
                        </Button>
                        {canPrint && !invoice.is_draft && invoice.status !== 'cancelled' && (
                            <Button
                                variant="light"
                                icon="ti ti-printer"
                                onClick={() => void printInvoice()}
                                disabled={generate.isPending}
                                type="button"
                            >
                                {generate.isPending ? 'Preparing…' : 'Print'}
                            </Button>
                        )}

                        {/* A draft is closed before it can be paid — the whole
                            point of consolidating is that the patient is asked
                            once, after the last charge has landed. */}
                        {invoice.is_draft && can('billing.create') && (
                            <Button
                                icon="ti ti-lock-check"
                                onClick={() => void closeBill()}
                                disabled={finalize.isPending}
                                type="button"
                            >
                                {finalize.isPending ? 'Closing…' : 'Close bill'}
                            </Button>
                        )}

                        {!invoice.is_draft &&
                            canCollect &&
                            invoice.outstanding > 0 &&
                            invoice.status !== 'cancelled' && (
                                <Button icon="ti ti-cash" onClick={() => setPayingOpen(true)} type="button">
                                    Take payment
                                </Button>
                            )}
                    </div>
                }
            />

            {refusal && !payingOpen && !cancellingOpen && refunding === undefined && (
                <div className="alert alert-danger py-2 mb-3">{refusal}</div>
            )}

            <div className="row g-3">
                <div className="col-lg-8">
                    <Card>
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span className={`badge bg-${tone}-subtle text-${tone} me-2`}>
                                    {INVOICE_STATUS_LABELS[invoice.status] ?? invoice.status}
                                </span>
                                <span className="badge bg-secondary-subtle text-secondary">
                                    Trigger: {invoice.trigger}
                                </span>
                            </div>
                            {invoice.created_by_name && (
                                <span className="dr-sub">Raised by {invoice.created_by_name}</span>
                            )}
                        </div>

                        {invoice.status === 'cancelled' && invoice.cancellation_reason && (
                            <div className="alert alert-danger py-2 mb-3">
                                <b>Cancelled:</b> {invoice.cancellation_reason}
                            </div>
                        )}

                        {invoice.is_draft && (
                            <div className="alert alert-warning py-2 mb-3">
                                <b>Still collecting charges.</b> This visit may yet produce more —
                                a lab result, a dispensing. Close the bill when the patient is
                                ready to pay, and anything that landed in the meantime is swept
                                in first.
                            </div>
                        )}

                        <div className="pf-table-wrap">
                            <table className="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Line</th>
                                        <th className="text-end">Qty</th>
                                        <th className="text-end">Price</th>
                                        <th className="text-end">Discount</th>
                                        <th className="text-end">Tax</th>
                                        <th className="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(invoice.items ?? []).map((item) => (
                                        <tr key={item.id}>
                                            <td>
                                                {item.description}
                                                <span className="dr-sub d-block">
                                                    <span
                                                        className={`badge bg-${SOURCE_LABELS[item.source_type]?.tone ?? 'secondary'}-subtle text-${SOURCE_LABELS[item.source_type]?.tone ?? 'secondary'}`}
                                                    >
                                                        {SOURCE_LABELS[item.source_type]?.label ??
                                                            item.source_type.replace(/_/g, ' ')}
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="text-end tabular-nums">{item.quantity}</td>
                                            <td className="text-end tabular-nums">{money(item.unit_price)}</td>
                                            <td className="text-end tabular-nums">
                                                {item.discount_amount > 0 ? money(item.discount_amount) : '—'}
                                            </td>
                                            <td className="text-end tabular-nums">
                                                {item.tax_amount > 0 ? (
                                                    <>
                                                        {money(item.tax_amount)}
                                                        <span className="dr-sub d-block">{item.tax_percent}%</span>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="text-end tabular-nums">{money(item.line_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <dl className="row mt-3 mb-0 fs-13">
                            <dt className="col-8 text-end">Subtotal</dt>
                            <dd className="col-4 text-end tabular-nums">{money(invoice.subtotal)}</dd>
                            {invoice.discount_amount > 0 && (
                                <>
                                    <dt className="col-8 text-end">Discount</dt>
                                    <dd className="col-4 text-end tabular-nums">−{money(invoice.discount_amount)}</dd>
                                </>
                            )}
                            {invoice.tax_amount > 0 && (
                                <>
                                    <dt className="col-8 text-end">Tax</dt>
                                    <dd className="col-4 text-end tabular-nums">{money(invoice.tax_amount)}</dd>
                                </>
                            )}
                            <dt className="col-8 text-end fw-bold">Total</dt>
                            <dd className="col-4 text-end tabular-nums fw-bold">{money(invoice.total_amount)}</dd>
                            <dt className="col-8 text-end">Paid</dt>
                            <dd className="col-4 text-end tabular-nums">{money(invoice.paid_amount)}</dd>
                            <dt className="col-8 text-end text-danger">Outstanding</dt>
                            <dd className="col-4 text-end tabular-nums text-danger fw-bold">
                                {money(invoice.outstanding)}
                            </dd>
                        </dl>

                        {canCancel && invoice.status !== 'cancelled' && invoice.paid_amount === 0 && (
                            <div className="mt-3 text-end">
                                <Button
                                    variant="danger"
                                    size="sm"
                                    icon="ti ti-ban"
                                    onClick={() => setCancellingOpen(true)}
                                >
                                    Cancel this invoice
                                </Button>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="col-lg-4">
                    <Card>
                        <h6 className="mb-3">Payments</h6>
                        {(invoice.payments ?? []).length === 0 ? (
                            <p className="text-muted fs-13 mb-0">No payment recorded yet.</p>
                        ) : (
                            <ul className="list-unstyled mb-0 fs-13">
                                {(invoice.payments ?? []).map((payment) => (
                                    <li key={payment.id} className="d-flex justify-content-between align-items-start py-2 border-bottom">
                                        <div>
                                            <b className={payment.is_refund ? 'text-danger' : ''}>
                                                {payment.is_refund && '↩ '}
                                                {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}
                                            </b>
                                            <span className="dr-sub d-block tabular-nums">
                                                {payment.receipt_number}
                                            </span>
                                            <span className="dr-sub d-block">
                                                {formatDate(payment.paid_at)}
                                                {payment.reference && ` · ${payment.reference}`}
                                            </span>
                                            {payment.notes && (
                                                <span className="dr-sub d-block">{payment.notes}</span>
                                            )}
                                        </div>
                                        <div className="text-end">
                                            <span className={`tabular-nums fw-bold ${payment.is_refund ? 'text-danger' : ''}`}>
                                                {payment.is_refund ? '−' : ''}{money(payment.amount)}
                                            </span>
                                            {canRefund && !payment.is_refund && (
                                                <div>
                                                    <button
                                                        type="button"
                                                        className="btn btn-link btn-sm px-0"
                                                        onClick={() => setRefunding(payment.id)}
                                                    >
                                                        Refund
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-3">Payment status</h6>
                        <p className="fs-13 mb-0">
                            <span className={`badge bg-${tone}-subtle text-${tone} me-2`}>
                                {PAYMENT_STATUS_LABELS[invoice.payment_status] ?? invoice.payment_status}
                            </span>
                            {invoice.outstanding > 0 ? (
                                <>
                                    <b>{money(invoice.outstanding)}</b> outstanding of{' '}
                                    <b>{money(invoice.total_amount)}</b>.
                                </>
                            ) : (
                                <>Settled in full.</>
                            )}
                        </p>
                    </Card>
                </div>
            </div>

            <PaymentDialog
                invoice={payingOpen ? invoice : undefined}
                onClose={() => setPayingOpen(false)}
                onPaid={() => setPayingOpen(false)}
            />

            <ReasonDialog
                open={cancellingOpen}
                title={`Cancel ${invoice.invoice_number}`}
                subtitle="The invoice stays on the record, marked cancelled. Any payment against it has to be refunded first."
                submitLabel="Cancel invoice"
                danger
                submitting={cancel.isPending}
                error={refusal}
                onClose={() => setCancellingOpen(false)}
                onSubmit={(reason) => void confirmCancel(reason)}
            />

            <RefundModal
                open={refunding !== undefined}
                onClose={() => setRefunding(undefined)}
                payment={invoice.payments?.find((p) => p.id === refunding)}
                submitting={refund.isPending}
                error={refusal}
                onSubmit={async (amount, reason) => {
                    if (!refunding) return;
                    setRefusal(null);
                    try {
                        await refund.mutateAsync({
                            invoiceId: invoice.id,
                            paymentId: refunding,
                            amount,
                            reason,
                        });
                        setRefunding(undefined);
                    } catch (failure) {
                        setRefusal(resolveErrorMessage(failure));
                    }
                }}
            />
        </>
    );
}
function RefundModal({
    open,
    onClose,
    payment,
    submitting,
    error,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    payment: { id: number; amount: number; method: string } | undefined;
    submitting: boolean;
    error: string | null;
    onSubmit: (amount: number, reason: string | null) => void;
}) {
    const [amount, setAmount] = useState(payment?.amount.toFixed(2) ?? '0');
    const [reason, setReason] = useState('');

    if (!payment) return null;

    return (
        <Modal open={open} onClose={onClose} title="Refund payment" size="md">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit(parseFloat(amount), reason || null);
                }}
                key={payment.id}
            >
                {error && <div className="alert alert-danger py-2 mb-3">{error}</div>}
                <p className="fs-13 text-muted">
                    Refunding <b>₹{payment.amount.toFixed(2)}</b> paid by{' '}
                    {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}.
                </p>

                <div className="mb-3">
                    <label className="form-label fs-13">Amount to refund</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0.01"
                        max={payment.amount}
                        className="form-control"
                        value={amount}
                        onChange={(event) => setAmount(event.target.value)}
                        required
                    />
                </div>

                <div className="mb-3">
                    <label className="form-label fs-13">Reason</label>
                    <textarea
                        className="form-control"
                        rows={2}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        maxLength={500}
                    />
                </div>

                <div className="d-flex justify-content-end gap-2">
                    <Button variant="light" onClick={onClose} type="button">
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" icon="ti ti-arrow-back-up" disabled={submitting}>
                        {submitting ? 'Refunding…' : 'Refund'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
