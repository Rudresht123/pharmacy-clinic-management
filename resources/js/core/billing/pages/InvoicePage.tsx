import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { formatDate } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { newIdempotencyKey } from '@/shared/utils/idempotency';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    INVOICE_KIND_LABELS,
    INVOICE_STATUS_LABELS,
    PAYMENT_METHOD_LABELS,
    PAYMENT_STATUS_LABELS,
    useCancelInvoice,
    useFinalizeInvoice,
    useInvoice,
    useRefundPayment,
} from '../api';
import { PaymentDialog } from '../components/PaymentDialog';

/** Where a line came from, for the tag under it. */
const SOURCE_LABELS: Record<string, { label: string; tone: string }> = {
    consultation: { label: 'Consultation', tone: 'blue' },
    lab_test: { label: 'Laboratory', tone: 'teal' },
    pharmacy_sale_item: { label: 'Pharmacy', tone: 'red' },
    procedure: { label: 'Procedure', tone: 'amber' },
    service: { label: 'Service', tone: 'slate' },
    custom: { label: 'Other', tone: 'slate' },
};

/** What the bill is called, and its mark, by which department's book it is in. */
const KIND_META: Record<string, { title: string; icon: string }> = {
    visit: { title: 'Visit Invoice', icon: 'ti ti-stethoscope' },
    lab: { title: 'Lab Invoice', icon: 'ti ti-flask' },
    registration: { title: 'Registration Invoice', icon: 'ti ti-user-plus' },
    manual: { title: 'Invoice', icon: 'ti ti-receipt' },
};

const STATUS_META: Record<string, { tone: string; icon: string }> = {
    draft: { tone: 'slate', icon: 'ti ti-pencil' },
    pending: { tone: 'amber', icon: 'ti ti-clock-hour-4' },
    partially_paid: { tone: 'blue', icon: 'ti ti-progress' },
    paid: { tone: 'green', icon: 'ti ti-circle-check' },
    cancelled: { tone: 'red', icon: 'ti ti-ban' },
    refunded: { tone: 'violet', icon: 'ti ti-arrow-back-up' },
    partially_refunded: { tone: 'violet', icon: 'ti ti-arrow-back-up' },
};

/** When the bill was drawn, in words. */
const TRIGGER_LABELS: Record<string, string> = {
    consultation: 'Consultation',
    checkin: 'Check-in',
    manual: 'Manual',
    lab_order: 'Lab order',
    laboratory: 'Lab sign-off',
    pharmacy: 'Pharmacy',
};

const PAYMENT_TONES: Record<string, string> = { paid: 'green', partial: 'amber', unpaid: 'red' };

const money = (value: number) => `₹${value.toFixed(2)}`;

/** "20 Oct 2026, 11:45 AM" */
function raisedAt(value: string | null | undefined): string {
    if (!value) return formatDate(value);

    const time = new Date(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

    return `${formatDate(value)}, ${time}`;
}

/**
 * One invoice, in full.
 *
 * The bill on the left — who raised it, its lines, what it adds up to and
 * what can be done with it — and on the right what has been paid against it
 * and what the visit was. All on one screen because that is what a counter
 * reads while somebody pays. "Take payment" is a form, not another route, so
 * the bill stays in view and the outstanding figure updates as soon as the
 * payment lands.
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
    // One key per refund dialog: a double-tapped "Refund" gives money back once.
    const [refundKey, setRefundKey] = useState(newIdempotencyKey);
    const [refusal, setRefusal] = useState<string | null>(null);

    function startRefund(paymentId: number) {
        setRefundKey(newIdempotencyKey());
        setRefusal(null);
        setRefunding(paymentId);
    }

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

    /** The bill itself, or the slip for one payment or refund against it — opened, or saved. */
    async function print(
        documentType: 'clinic_invoice' | 'clinic_receipt' | 'clinic_refund',
        subjectId: number,
        download = false,
    ) {
        setRefusal(null);

        try {
            const document_ = await generate.mutateAsync({
                document_type: documentType,
                subject_id: subjectId,
            });

            await openDocument(document_, download);
        } catch (failure) {
            setRefusal(resolveErrorMessage(failure));
        }
    }

    if (isLoading || !invoice) {
        return <LoadingBlock label="Loading invoice…" />;
    }

    const kind = KIND_META[invoice.kind] ?? KIND_META.manual;
    const status = STATUS_META[invoice.status] ?? STATUS_META.pending;
    const paymentTone = PAYMENT_TONES[invoice.payment_status] ?? 'slate';
    const refunded = invoice.refunded_amount ?? 0;
    const cancelled = invoice.status === 'cancelled';

    // A doctor who raised their own visit's bill reads with their department under the name.
    const raiserIsDoctor = Boolean(invoice.doctor_name) && invoice.created_by_name === invoice.doctor_name;

    const showDocuments = canPrint && !invoice.is_draft && !cancelled;
    const showCancel = canCancel && !cancelled && invoice.paid_amount === 0;

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

                        {!invoice.is_draft && canCollect && invoice.outstanding > 0 && !cancelled && (
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

            <div className="ivp-layout">
                {/* ------------------------------------------------ the bill */}
                <section className="ivp-card">
                    <header className="ivp-head">
                        <div className="ivp-title">
                            <span className="ivp-title-icon" aria-hidden="true">
                                <i className={kind.icon} />
                            </span>

                            <div>
                                <h2>{kind.title}</h2>
                                <div className="ivp-meta">
                                    <span className="tabular-nums">{invoice.invoice_number}</span>
                                    <span className="ivp-meta-sep" aria-hidden="true" />
                                    <span>{raisedAt(invoice.created_at ?? invoice.invoice_date)}</span>
                                </div>
                            </div>
                        </div>

                        {invoice.created_by_name && (
                            <div className="ivp-raised">
                                <span>Raised by</span>
                                <span className="ivp-who">
                                    <span className="ivp-avatar" aria-hidden="true">
                                        <i className="ti ti-user" />
                                    </span>
                                    <span>
                                        <b>{invoice.created_by_name}</b>
                                        {raiserIsDoctor && (
                                            <small>{invoice.doctor_specialisation || 'Doctor'}</small>
                                        )}
                                    </span>
                                </span>
                            </div>
                        )}
                    </header>

                    <div className="ivp-body">
                        <div className="ivp-pills">
                            <span className={`ivp-pill is-${status.tone}`}>
                                <i className={status.icon} aria-hidden="true" />
                                {INVOICE_STATUS_LABELS[invoice.status] ?? invoice.status}
                            </span>
                            {/* Which department's book this bill is in. */}
                            <span className="ivp-pill is-indigo">
                                {INVOICE_KIND_LABELS[invoice.kind] ?? invoice.kind}
                            </span>
                            <span className="ivp-pill is-green">
                                <i className="ti ti-bolt" aria-hidden="true" />
                                Trigger: {TRIGGER_LABELS[invoice.trigger] ?? invoice.trigger.replace(/_/g, ' ')}
                            </span>
                        </div>

                        {cancelled && invoice.cancellation_reason && (
                            <p className="ivp-note is-red">
                                <b>Cancelled:</b> {invoice.cancellation_reason}
                            </p>
                        )}

                        {invoice.is_draft && (
                            <p className="ivp-note is-amber">
                                <b>Not closed yet.</b> This bill was opened before each department billed for
                                itself. Close it to take payment — the OPD&apos;s own charges are added first.
                                Lab tests and medicines are billed by the lab and the pharmacy on their own bills.
                            </p>
                        )}

                        <div className="ivp-table-frame">
                            <table className="ivp-table">
                                <thead>
                                    <tr>
                                        <th>S.No.</th>
                                        <th>Item / Service</th>
                                        <th className="ivp-mid">Qty</th>
                                        <th className="ivp-num">Unit Price</th>
                                        <th className="ivp-num">Discount</th>
                                        <th className="ivp-num">Tax</th>
                                        <th className="ivp-num">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(invoice.items ?? []).map((item, index) => {
                                        const source = SOURCE_LABELS[item.source_type];
                                        // "General Medicine consultation" — what the line was, when it can be said.
                                        const detail =
                                            item.source_type === 'consultation' && invoice.doctor_specialisation
                                                ? `${invoice.doctor_specialisation} consultation`
                                                : null;

                                        return (
                                            <tr key={item.id}>
                                                <td className="ivp-sno">{index + 1}</td>
                                                <td className="ivp-item">
                                                    <b>{item.description}</b>
                                                    {detail && <small>{detail}</small>}
                                                    <span className={`ivp-tag is-${source?.tone ?? 'slate'}`}>
                                                        {source?.label ?? item.source_type.replace(/_/g, ' ')}
                                                    </span>
                                                </td>
                                                <td className="ivp-mid">{item.quantity}</td>
                                                <td className="ivp-num">{money(item.unit_price)}</td>
                                                <td className="ivp-num">
                                                    {item.discount_amount > 0 ? money(item.discount_amount) : '—'}
                                                </td>
                                                <td className="ivp-num">
                                                    {item.tax_amount > 0 ? (
                                                        <>
                                                            {money(item.tax_amount)}
                                                            <span className="ivp-sub">{item.tax_percent}%</span>
                                                        </>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="ivp-num ivp-amount">{money(item.line_total)}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="ivp-totals">
                            <div className="ivp-total-row">
                                <span>Subtotal</span>
                                <span>{money(invoice.subtotal)}</span>
                            </div>
                            {invoice.discount_amount > 0 && (
                                <div className="ivp-total-row">
                                    <span>Discount</span>
                                    <span>−{money(invoice.discount_amount)}</span>
                                </div>
                            )}
                            {invoice.tax_amount > 0 && (
                                <div className="ivp-total-row">
                                    <span>Tax</span>
                                    <span>{money(invoice.tax_amount)}</span>
                                </div>
                            )}
                            <div className="ivp-total-row is-strong">
                                <span>Total</span>
                                <span>{money(invoice.total_amount)}</span>
                            </div>
                            <div className="ivp-total-row">
                                <span>Paid</span>
                                <span>{money(invoice.paid_amount)}</span>
                            </div>
                            {refunded > 0 && (
                                // Handed back — and credited to the bill, so never owed again.
                                <div className="ivp-total-row">
                                    <span>Refunded</span>
                                    <span>{money(refunded)}</span>
                                </div>
                            )}
                            <div className={`ivp-total-row ${invoice.outstanding > 0 ? 'is-due' : 'is-settled'}`}>
                                <span>Outstanding</span>
                                <span>{money(invoice.outstanding)}</span>
                            </div>
                        </div>
                    </div>

                    {(showDocuments || showCancel) && (
                        <footer className="ivp-foot">
                            {showDocuments && (
                                <>
                                    <button
                                        type="button"
                                        className="ivp-btn"
                                        onClick={() => void print('clinic_invoice', invoice.id)}
                                        disabled={generate.isPending}
                                    >
                                        <i className="ti ti-printer" aria-hidden="true" />
                                        {generate.isPending ? 'Preparing…' : 'Print Invoice'}
                                    </button>

                                    {/* Not wired yet: delivering documents by WhatsApp or
                                        email is the open step of document automation. */}
                                    <button
                                        type="button"
                                        className="ivp-btn"
                                        disabled
                                        title="Sending bills to patients by WhatsApp or email is not set up yet."
                                    >
                                        <i className="ti ti-mail" aria-hidden="true" />
                                        Send to Patient
                                    </button>

                                    <button
                                        type="button"
                                        className="ivp-btn"
                                        onClick={() => void print('clinic_invoice', invoice.id, true)}
                                        disabled={generate.isPending}
                                    >
                                        <i className="ti ti-download" aria-hidden="true" />
                                        Download PDF
                                    </button>

                                    {showCancel && <span className="ivp-foot-sep" aria-hidden="true" />}
                                </>
                            )}

                            {showCancel && (
                                <button
                                    type="button"
                                    className="ivp-btn is-danger ivp-foot-end"
                                    onClick={() => setCancellingOpen(true)}
                                >
                                    <i className="ti ti-ban" aria-hidden="true" />
                                    Cancel this invoice
                                </button>
                            )}
                        </footer>
                    )}
                </section>

                {/* ------------------------------------------------ the side */}
                <aside className="ivp-side">
                    <section className="ivp-card">
                        <h3 className="ivp-side-head mb-0">
                            <i className="ti ti-wallet" aria-hidden="true" />
                            Payment Information
                        </h3>

                        <div className="ivp-side-body">
                            <div className="ivp-stats">
                                <div className="ivp-stat">
                                    <i className="ti ti-file-invoice" aria-hidden="true" />
                                    <div>
                                        <small>Total Amount</small>
                                        <b>{money(invoice.total_amount)}</b>
                                    </div>
                                </div>

                                <div className="ivp-stat is-green">
                                    <i className="ti ti-wallet" aria-hidden="true" />
                                    <div>
                                        <small>Amount Paid</small>
                                        <b>{money(invoice.paid_amount)}</b>
                                    </div>
                                </div>

                                {refunded > 0 && (
                                    <div className="ivp-stat is-wide">
                                        <i className="ti ti-arrow-back-up" aria-hidden="true" />
                                        <div>
                                            <small>Refunded</small>
                                            <b>{money(refunded)}</b>
                                        </div>
                                    </div>
                                )}

                                <div className={`ivp-stat ${invoice.outstanding > 0 ? 'is-due' : 'is-clear'}`}>
                                    <i
                                        className={invoice.outstanding > 0 ? 'ti ti-credit-card' : 'ti ti-circle-check'}
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <small>Outstanding Amount</small>
                                        <b>{money(invoice.outstanding)}</b>
                                    </div>
                                </div>
                            </div>

                            <div className="ivp-section">
                                <h4 className="ivp-section-title">
                                    <i className="ti ti-clock" aria-hidden="true" />
                                    Payment Status
                                </h4>

                                <p className="ivp-status-line">
                                    <span className={`ivp-pill is-${paymentTone}`}>
                                        {PAYMENT_STATUS_LABELS[invoice.payment_status] ?? invoice.payment_status}
                                    </span>
                                    <span>
                                        {invoice.outstanding > 0 ? (
                                            <>
                                                <b className="is-red-text">{money(invoice.outstanding)}</b> outstanding
                                                of <b>{money(invoice.total_amount)}</b>.
                                            </>
                                        ) : refunded > 0 ? (
                                            <>
                                                Nothing owed — <b>{money(refunded)}</b> was handed back and credited
                                                to the bill.
                                            </>
                                        ) : (
                                            <>Settled in full.</>
                                        )}
                                    </span>
                                </p>
                            </div>

                            {(invoice.payments ?? []).length > 0 && (
                                <div className="ivp-section">
                                    <h4 className="ivp-section-title">
                                        <i className="ti ti-receipt-2" aria-hidden="true" />
                                        Payments
                                    </h4>

                                    <ul className="ivp-payments">
                                        {(invoice.payments ?? []).map((payment) => (
                                            <li key={payment.id} className={payment.is_refund ? 'is-refund' : undefined}>
                                                <div>
                                                    <b>
                                                        {payment.is_refund && '↩ '}
                                                        {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}
                                                    </b>
                                                    <span className="ivp-sub tabular-nums">{payment.receipt_number}</span>
                                                    <span className="ivp-sub">
                                                        {formatDate(payment.paid_at)}
                                                        {payment.reference && ` · ${payment.reference}`}
                                                    </span>
                                                    {payment.notes && <span className="ivp-sub">{payment.notes}</span>}
                                                </div>

                                                <div>
                                                    <span className="ivp-pay-amount">
                                                        {payment.is_refund ? '−' : ''}
                                                        {money(payment.amount)}
                                                    </span>

                                                    {(canPrint || (canRefund && !payment.is_refund)) && (
                                                        <div className="ivp-pay-links">
                                                            {/* This row's own slip — a receipt for money in, a
                                                                refund receipt for money back — not the bill again. */}
                                                            {canPrint && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        void print(
                                                                            payment.is_refund
                                                                                ? 'clinic_refund'
                                                                                : 'clinic_receipt',
                                                                            payment.id,
                                                                        )
                                                                    }
                                                                    disabled={generate.isPending}
                                                                >
                                                                    {payment.is_refund ? 'Refund slip' : 'Receipt'}
                                                                </button>
                                                            )}
                                                            {canRefund && !payment.is_refund && (
                                                                <button type="button" onClick={() => startRefund(payment.id)}>
                                                                    Refund
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="ivp-card">
                        <h3 className="ivp-side-head mb-0">
                            <i className="ti ti-calendar-event" aria-hidden="true" />
                            {invoice.kind === 'visit' ? 'Visit Details' : 'Bill Details'}
                        </h3>

                        <div className="ivp-side-body">
                            <div className="ivp-facts">
                                <div className="ivp-fact">
                                    <span className="ivp-fact-icon" aria-hidden="true">
                                        <i className="ti ti-building-hospital" />
                                    </span>
                                    <div>
                                        <small>{invoice.kind === 'visit' ? 'Visit Type' : 'Bill Type'}</small>
                                        <b>{INVOICE_KIND_LABELS[invoice.kind] ?? invoice.kind}</b>
                                    </div>
                                </div>

                                {invoice.doctor_name ? (
                                    <div className="ivp-fact">
                                        <span className="ivp-fact-icon" aria-hidden="true">
                                            <i className="ti ti-user" />
                                        </span>
                                        <div>
                                            <small>{invoice.kind === 'lab' ? 'Ordered By' : 'Consultation By'}</small>
                                            <b>{invoice.doctor_name}</b>
                                            {invoice.doctor_specialisation && <span>{invoice.doctor_specialisation}</span>}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="ivp-fact">
                                        <span className="ivp-fact-icon" aria-hidden="true">
                                            <i className="ti ti-user" />
                                        </span>
                                        <div>
                                            <small>Patient</small>
                                            <b>{invoice.customer_name ?? 'Walk-in'}</b>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </section>
                </aside>
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
                            key: refundKey,
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
                    {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}. A refund also credits the
                    bill — the patient is not asked for it again.
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
