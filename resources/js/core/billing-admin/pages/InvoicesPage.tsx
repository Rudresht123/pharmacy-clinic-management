import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { Modal } from '@/shared/components/ui/Modal';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import { formatDay, formatPaise, rupeesToPaise } from '@/core/subscription/money';
import { useBillingMutation, useInvoiceInsights, useInvoices, useOrganizationOptions, type AdminInvoice } from '../api';
import { ActionDialog, FieldError, StatusChip } from '../components/Common';
import { ChartCard, compactPaise, GroupedBars, KpiCard, KpiRow } from '../components/Visuals';

const TREND = [
    { label: 'Paid', color: 'var(--cat-2)' },
    { label: 'Pending', color: 'var(--cat-4)' },
    { label: 'Overdue', color: 'var(--cat-5)' },
];

const PAGE = 10;

/** Every subscription invoice on the platform, and the money against each. */
export default function InvoicesPage() {
    const [params, setParams] = useSearchParams();
    const [page, setPage] = useState(1);
    const [viewing, setViewing] = useState<AdminInvoice | null>(null);
    const [paying, setPaying] = useState<AdminInvoice | null>(null);
    const [voiding, setVoiding] = useState<AdminInvoice | null>(null);
    const [draft, setDraft] = useState({
        search: params.get('search') ?? '',
        organization: params.get('organization') ?? '',
        status: params.get('status') ?? '',
        from: params.get('from') ?? '',
        to: params.get('to') ?? '',
    });

    const filters = Object.fromEntries(
        ['search', 'organization', 'status', 'from', 'to'].map((key) => [key, params.get(key) || undefined]),
    );

    const { data: insights } = useInvoiceInsights();
    const { data, isLoading, isFetching } = useInvoices({ ...filters, page, per_page: PAGE });
    const { data: organizations } = useOrganizationOptions('');
    const t = insights?.tiles;
    const total = data?.meta?.total ?? 0;

    function apply(next = draft) {
        setPage(1);
        setParams(Object.fromEntries(Object.entries(next).filter(([, v]) => v)));
    }

    async function exportCsv() {
        try {
            const { data: body } = await http.get<ApiResponse<AdminInvoice[]>>('/admin/billing/invoices', { params: { ...filters, per_page: 500 } });
            const rows = [
                ['Invoice', 'Organization', 'Kind', 'Subtotal', 'Tax', 'Total', 'Issued', 'Due', 'Paid', 'Status', 'Payment mode'],
                ...body.data.map((i) => [
                    i.number ?? '',
                    i.organization?.name ?? i.bill_to?.name ?? '',
                    i.kind,
                    (i.subtotal / 100).toFixed(2),
                    (i.tax / 100).toFixed(2),
                    (i.total / 100).toFixed(2),
                    i.issued_at?.slice(0, 10) ?? '',
                    i.due_at?.slice(0, 10) ?? '',
                    i.paid_at?.slice(0, 10) ?? '',
                    i.display_status,
                    i.payment_mode ?? '',
                ]),
            ];
            const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
            link.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
        } catch {
            notify.error('The export could not be prepared.');
        }
    }

    return (
        <>
            <PageHeader
                title="Invoices"
                subtitle="Manage invoices, payments and transaction history."
                icon="ti ti-receipt"
                actions={
                    <div className="d-flex flex-wrap gap-2 align-items-center">
                        <form className="sxa-search" onSubmit={(e) => { e.preventDefault(); apply(); }}>
                            <i className="ti ti-search" aria-hidden="true" />
                            <input className="form-control" placeholder="Search by invoice ID, organization…" value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                        </form>
                        <Button variant="light" icon="ti ti-download" onClick={() => void exportCsv()}>Export</Button>
                    </div>
                }
            />

            <KpiRow columns={4}>
                <KpiCard icon="ti ti-file-invoice" tone="violet" label="Total Invoices" value={t?.total_invoices ?? 0} hint="Issued, not void" />
                <KpiCard icon="ti ti-circle-check" tone="emerald" label="Paid Amount" value={formatPaise(t?.paid_amount ?? 0)} onClick={() => apply({ ...draft, status: 'paid' })} />
                <KpiCard icon="ti ti-clock-dollar" tone="amber" label="Pending Amount" value={formatPaise(t?.pending_amount ?? 0)} onClick={() => apply({ ...draft, status: 'pending' })} />
                <KpiCard icon="ti ti-alert-octagon" tone="rose" label="Overdue Amount" value={formatPaise(t?.overdue_amount ?? 0)} onClick={() => apply({ ...draft, status: 'overdue' })} />
            </KpiRow>

            <div className="sxa-three">
                <ChartCard title="Invoice Status">
                    <DonutChart slices={insights?.status ?? []} centreLabel="Total" empty="No invoices yet." />
                </ChartCard>
                <ChartCard title="Invoice Trend" legend={TREND}>
                    <GroupedBars points={insights?.trend ?? []} series={TREND} format={(v) => formatPaise(v)} axis={compactPaise} height={200} empty="No invoices in the last six months." />
                </ChartCard>
                <ChartCard title="Payment Methods">
                    <DonutChart slices={insights?.payment_methods ?? []} centreLabel="Collected" format={compactPaise} empty="No payments received yet." />
                </ChartCard>
            </div>

            <Card>
                <form className="sxa-filters" onSubmit={(e) => { e.preventDefault(); apply(); }}>
                    <select className="form-select" value={draft.organization} onChange={(e) => setDraft({ ...draft, organization: e.target.value })}>
                        <option value="">All Organizations</option>
                        {(organizations ?? []).map((org) => <option key={org.uuid} value={org.uuid}>{org.organization_name}</option>)}
                    </select>
                    <select className="form-select" value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                        <option value="">All Statuses</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="overdue">Overdue</option>
                        <option value="void">Void</option>
                    </select>
                    <div className="sxa-range">
                        <i className="ti ti-calendar" aria-hidden="true" />
                        <input type="date" aria-label="Issued from" value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} />
                        <span>–</span>
                        <input type="date" aria-label="Issued to" value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} />
                    </div>
                    <Button type="submit" icon="ti ti-filter">Filter</Button>
                </form>

                <div className={`sxa-table ${isFetching ? 'is-fetching' : ''}`}>
                    <table className="table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Invoice ID</th>
                                <th>Organization</th>
                                <th>Amount</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Payment Mode</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(data?.rows ?? []).map((invoice, i) => (
                                <tr key={invoice.uuid}>
                                    <td>{(page - 1) * PAGE + i + 1}</td>
                                    <td><b>{invoice.number ?? '—'}</b></td>
                                    <td>
                                        {invoice.organization ? (
                                            <Link to={`/billing/organizations/${invoice.organization.uuid}`}>{invoice.organization.name}</Link>
                                        ) : (
                                            <span>{invoice.bill_to?.name ?? '—'} <span className="dt-muted">(sign-up)</span></span>
                                        )}
                                    </td>
                                    <td>{formatPaise(invoice.total, invoice.currency)}</td>
                                    <td>{formatDay(invoice.issued_at)}</td>
                                    <td>{formatDay(invoice.due_at)}</td>
                                    <td><StatusChip status={invoice.display_status === 'overdue' ? 'expired' : invoice.display_status === 'pending' ? 'issued' : invoice.display_status} label={invoice.display_status} /></td>
                                    <td>{invoice.payment_mode ?? '—'}</td>
                                    <td><button type="button" className="btn btn-link sxa-link p-0" onClick={() => setViewing(invoice)}>View</button></td>
                                </tr>
                            ))}
                            {!isLoading && (data?.rows.length ?? 0) === 0 && (
                                <tr><td colSpan={9} className="text-center text-muted py-4">No invoices match.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="sxa-pager">
                    <span>{total === 0 ? 'No results' : `Showing ${(page - 1) * PAGE + 1}–${Math.min(page * PAGE, total)} of ${total}`}</span>
                    <div className="d-flex gap-2">
                        <Button size="sm" variant="light" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                        <Button size="sm" variant="light" disabled={page * PAGE >= total} onClick={() => setPage(page + 1)}>Next</Button>
                    </div>
                </div>
            </Card>

            {viewing && (
                <InvoiceDetail
                    invoice={viewing}
                    onClose={() => setViewing(null)}
                    onPay={() => { setPaying(viewing); setViewing(null); }}
                    onVoid={() => { setVoiding(viewing); setViewing(null); }}
                />
            )}
            {paying && <RecordPayment invoice={paying} onClose={() => setPaying(null)} />}
            {voiding && <VoidInvoice invoice={voiding} onClose={() => setVoiding(null)} />}
        </>
    );
}

function InvoiceDetail({ invoice, onClose, onPay, onVoid }: { invoice: AdminInvoice; onClose: () => void; onPay: () => void; onVoid: () => void }) {
    return (
        <Modal
            open
            size="lg"
            title={`Invoice ${invoice.number ?? ''}`}
            subtitle={invoice.organization?.name ?? invoice.bill_to?.name ?? undefined}
            icon={<i className="ti ti-file-invoice" />}
            onClose={onClose}
            footer={
                invoice.status === 'issued' ? (
                    <>
                        <Button variant="light" onClick={onVoid}>Void</Button>
                        <Button icon="ti ti-cash" onClick={onPay}>Record payment</Button>
                    </>
                ) : (
                    <Button variant="light" onClick={onClose}>Close</Button>
                )
            }
        >
            <dl className="sx-status-facts mb-3">
                <div><dt>Status</dt><dd><StatusChip status={invoice.display_status === 'overdue' ? 'expired' : invoice.display_status === 'pending' ? 'issued' : invoice.display_status} label={invoice.display_status} /></dd></div>
                <div><dt>Issued</dt><dd>{formatDay(invoice.issued_at)}</dd></div>
                <div><dt>Due</dt><dd>{formatDay(invoice.due_at)}</dd></div>
                {invoice.paid_at && <div><dt>Paid</dt><dd>{formatDay(invoice.paid_at)}</dd></div>}
                {invoice.period_start && <div><dt>Period</dt><dd>{formatDay(invoice.period_start)} – {formatDay(invoice.period_end)}</dd></div>}
            </dl>
            <div className="sxa-table">
                <table className="table mb-0">
                    <thead><tr><th>Item</th><th>Qty</th><th>Rate</th><th className="text-end">Amount</th></tr></thead>
                    <tbody>
                        {invoice.lines.map((line, i) => (
                            <tr key={i}><td>{line.description}</td><td>{line.quantity}</td><td>{formatPaise(line.unit_price)}</td><td className="text-end">{formatPaise(line.amount)}</td></tr>
                        ))}
                        <tr><td colSpan={3} className="text-end">Subtotal</td><td className="text-end">{formatPaise(invoice.subtotal)}</td></tr>
                        {invoice.tax > 0 && <tr><td colSpan={3} className="text-end">Tax ({invoice.tax_rate_bp / 100}%{invoice.tax_mode === 'inclusive' ? ', included' : ''})</td><td className="text-end">{formatPaise(invoice.tax)}</td></tr>}
                        <tr><td colSpan={3} className="text-end"><b>Total</b></td><td className="text-end"><b>{formatPaise(invoice.total)}</b></td></tr>
                    </tbody>
                </table>
            </div>
            {invoice.payments.length > 0 && (
                <>
                    <div className="sxa-section-title">Payments</div>
                    <ul className="sxa-events">
                        {invoice.payments.map((p) => (
                            <li key={p.uuid}>
                                <b>{formatPaise(p.amount)}</b> · {p.method === 'manual' ? `Manual · ${p.reference}` : `Razorpay · ${p.gateway_payment_id ?? p.gateway_order_id}`} · {p.status}
                                <small>{p.verified_at ? `Verified ${new Date(p.verified_at).toLocaleString('en-IN')} via ${p.verified_via}` : p.failure_reason ?? 'Not verified'}</small>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Modal>
    );
}

function RecordPayment({ invoice, onClose }: { invoice: AdminInvoice; onClose: () => void }) {
    const record = useBillingMutation<Record<string, unknown>>('post', `/invoices/${invoice.uuid}/payments`);
    const [amount, setAmount] = useState(String(invoice.total / 100));
    const [reference, setReference] = useState('');
    const [receivedAt, setReceivedAt] = useState('');

    return (
        <ActionDialog
            title={`Record payment for ${invoice.number}`}
            subtitle="The invoice is paid, and what it was for happens — a renewal unlocks the organization."
            icon="ti ti-cash"
            askReason={false}
            submitLabel="Record payment"
            onClose={onClose}
            submit={() => record.mutateAsync({ amount: rupeesToPaise(amount) ?? -1, reference, ...(receivedAt ? { received_at: receivedAt } : {}) })}
        >
            {(errors) => (
                <>
                    <div className="mb-3">
                        <label className="form-label">Amount received (₹)</label>
                        <input className="form-control" value={amount} onChange={(e) => setAmount(e.target.value)} />
                        <small className="text-muted">Invoice total: {formatPaise(invoice.total)}</small>
                        <FieldError errors={errors} name="amount" />
                        <FieldError errors={errors} name="method" />
                    </div>
                    <div className="mb-3">
                        <label className="form-label">Reference (UTR / cheque no.)</label>
                        <input className="form-control" value={reference} onChange={(e) => setReference(e.target.value)} />
                        <FieldError errors={errors} name="reference" />
                    </div>
                    <div className="mb-3">
                        <label className="form-label">Received on</label>
                        <input type="date" className="form-control" value={receivedAt} onChange={(e) => setReceivedAt(e.target.value)} />
                    </div>
                </>
            )}
        </ActionDialog>
    );
}

function VoidInvoice({ invoice, onClose }: { invoice: AdminInvoice; onClose: () => void }) {
    const voidIt = useBillingMutation<{ reason: string }>('post', `/invoices/${invoice.uuid}/void`);

    return <ActionDialog title={`Void ${invoice.number}`} tone="danger" submitLabel="Void invoice" onClose={onClose} submit={(reason) => voidIt.mutateAsync({ reason })} />;
}
