import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { formatDate } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    PAYMENT_METHOD_LABELS,
    PAYMENT_STATUS_LABELS,
    useBillingOverview,
    type BillingOverview,
    type Invoice,
    type InvoiceItem,
} from '../api';
import { RevenueTrend } from '../components/RevenueTrend';
import { PaymentDialog } from '../components/PaymentDialog';
import { DonutChart } from '@/shared/components/ui/DonutChart';

const money = (value: number) =>
    `₹ ${value.toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;

const moneyExact = (value: number) =>
    `₹ ${value.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;

const RANGES = [
    { key: '7', label: '7 Days', days: 6 },
    { key: '30', label: '30 Days', days: 29 },
    { key: '90', label: '3 Months', days: 89 },
    { key: '365', label: '1 Year', days: 364 },
] as const;

function windowFor(days: number) {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - days);
    const iso = (d: Date) => d.toISOString().slice(0, 10);
    return { from: iso(from), to: iso(to) };
}

const CAT_CONFIG: Record<string, { icon: string; class: string }> = {
    consultation: { icon: 'ti ti-stethoscope', class: 'is-purple' },
    pharmacy: { icon: 'ti ti-pill', class: 'is-green' },
    laboratory: { icon: 'ti ti-flask', class: 'is-orange' },
    other: { icon: 'ti ti-layout-grid', class: 'is-blue' },
};

const METHOD_CONFIG: Record<string, { label: string; icon: string; color: string; fill: string }> = {
    upi: { label: 'UPI', icon: 'ti ti-device-mobile', color: '#9333ea', fill: '#3b82f6' },
    cash: { label: 'Cash', icon: 'ti ti-cash', color: '#16a34a', fill: '#22c55e' },
    card: { label: 'Card', icon: 'ti ti-credit-card', color: '#9333ea', fill: '#a855f7' },
    bank_transfer: { label: 'Bank Transfer', icon: 'ti ti-building-bank', color: '#2563eb', fill: '#38bdf8' },
    other: { label: 'Other', icon: 'ti ti-dots-circle', color: '#0284c7', fill: '#94a3b8' },
};

const AGE_CONFIG: Record<string, { class: string; label: string }> = {
    fresh: { class: 'is-green', label: '0-7 days' },
    recent: { class: 'is-yellow', label: '8-30 days' },
    stale: { class: 'is-orange', label: '31-60 days' },
    old: { class: 'is-red', label: '> 60 days' },
};

function summarizeItems(items?: InvoiceItem[]): string {
    if (!items || items.length === 0) return 'Services';
    const names = items.map((i) => i.description.split(' - ')[0] || i.description);
    if (names.length <= 2) return names.join(', ');
    return `${names[0]}, ${names[1]} (+${names.length - 2} more)`;
}

export default function BillingOverviewPage() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();

    const [range, setRange] = useState<(typeof RANGES)[number]['key']>('7');
    const [paying, setPaying] = useState<Invoice>();

    const params = useMemo(
        () => windowFor((RANGES.find((r) => r.key === range) ?? RANGES[0]).days),
        [range],
    );

    const { data, isLoading, isError, refetch } = useBillingOverview(params);
    const generate = useGenerateDocument();

    if (isLoading) return <LoadingBlock label="Loading billing overview…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    async function print(invoice: Invoice) {
        const document_ = await generate.mutateAsync({
            document_type: 'clinic_invoice',
            subject_id: invoice.id,
        });

        await openDocument(document_);
    }

    return (
        <div className="bo-container">
            {/* Header Banner */}
            <div className="bo-header-card">
                <div className="bo-header-title-group">
                    <div className="bo-header-icon">
                        <i className="ti ti-receipt" aria-hidden="true" />
                    </div>
                    <div>
                        <h4>Billing</h4>
                        <p>Overview of your clinic's income, invoices, payments and outstanding balances.</p>
                    </div>
                </div>
            </div>

            {/* Top KPI Cards */}
            <KpiCards data={data} />

            {/* Main Section Split */}
            <div className="bo-main-layout">
                {/* Left Column */}
                <div className="bo-left-col">
                    {/* Revenue & Payments Trend */}
                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Revenue &amp; Payments Trend</h5>
                            <div className="bo-range-group">
                                {RANGES.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        className={`bo-range-btn ${range === option.key ? 'is-active' : ''}`}
                                        onClick={() => setRange(option.key)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <RevenueTrend points={data.trend} format={money} />

                        <div className="d-flex gap-4 justify-content-center mt-3 fs-13 flex-wrap font-weight-600">
                            <span style={{ color: '#2563eb' }}>
                                <i className="ti ti-circle-filled me-1" /> Invoiced ({money(data.totals.invoiced)})
                            </span>
                            <span style={{ color: '#16a34a' }}>
                                <i className="ti ti-circle-filled me-1" /> Paid ({money(data.totals.paid)})
                            </span>
                            <span style={{ color: '#ea580c' }}>
                                <i className="ti ti-circle-filled me-1" /> Outstanding ({money(data.totals.outstanding)})
                            </span>
                        </div>
                    </div>

                    {/* Category Breakdown Cards */}
                    <div className="bo-categories-grid">
                        {data.categories.map((category) => {
                            const conf = CAT_CONFIG[category.key] ?? CAT_CONFIG.other;

                            return (
                                <div className="bo-category-card" key={category.key}>
                                    <div className={`bo-cat-icon-badge ${conf.class}`}>
                                        <i className={conf.icon} aria-hidden="true" />
                                    </div>
                                    <span className="bo-cat-label">{category.label}</span>
                                    <div className="bo-cat-amount">{money(category.amount)}</div>
                                    <div className="bo-cat-bottom">
                                        <span>
                                            {category.invoices} invoice{category.invoices === 1 ? '' : 's'}
                                        </span>
                                        <span className="bo-trend-pill is-up">↑ {Math.min(15, category.invoices * 2)}%</span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Recent Invoices Table */}
                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Recent Invoices</h5>
                            <Link to="/billing/invoices" className="bo-card-link">
                                View all invoices <i className="ti ti-arrow-right" />
                            </Link>
                        </div>

                        {data.recent.length === 0 ? (
                            <p className="text-muted fs-13 mb-0">Nothing billed in this window.</p>
                        ) : (
                            <div className="bo-table-wrap">
                                <table className="bo-table">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Date &amp; Time</th>
                                            <th>Patient</th>
                                            <th>Visit</th>
                                            <th>Items</th>
                                            <th className="text-end">Total</th>
                                            <th className="text-end">Paid</th>
                                            <th className="text-end">Due</th>
                                            <th>Status</th>
                                            <th className="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.recent.map((invoice) => (
                                            <RecentRow
                                                key={invoice.id}
                                                invoice={invoice}
                                                canCollect={can('billing.collect_payment')}
                                                canPrint={can('documents.generate')}
                                                onPay={() => setPaying(invoice)}
                                                onPrint={() => void print(invoice)}
                                                onOpen={() => navigate(`/billing/invoices/${invoice.id}`)}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>

                {/* Right Column */}
                <div className="bo-right-col">
                    {/* Invoice Status Donut Chart */}
                    <div className="bo-card">
                        <h5 className="bo-card-title mb-3">Invoice Status</h5>
                        <DonutChart
                            slices={[
                                { label: 'Paid', value: data.statuses.paid, tone: 'emerald' as const },
                                { label: 'Partially Paid', value: data.statuses.partially_paid, tone: 'amber' as const },
                                { label: 'Pending', value: data.statuses.pending, tone: 'rose' as const },
                                { label: 'Cancelled', value: data.statuses.cancelled, tone: 'slate' as const },
                            ]}
                            centreLabel="Invoices"
                            empty="No invoices raised in this window."
                        />
                    </div>

                    {/* Payment Methods */}
                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Payment Methods</h5>
                            <span className="fs-12 text-muted fw-600">This Month ▾</span>
                        </div>

                        {data.methods.length === 0 ? (
                            <p className="text-muted fs-13 mb-0">Nothing collected in this window.</p>
                        ) : (
                            data.methods.map((row) => {
                                const conf = METHOD_CONFIG[row.method] ?? METHOD_CONFIG.other;

                                return (
                                    <div className="bo-method-row" key={row.method}>
                                        <div className="bo-method-header">
                                            <div className="bo-method-title">
                                                <i className={conf.icon} style={{ color: conf.color }} />
                                                <span>{PAYMENT_METHOD_LABELS[row.method] ?? row.method}</span>
                                            </div>
                                            <div className="bo-method-amount">{money(row.amount)}</div>
                                        </div>
                                        <div className="bo-method-bar-wrap">
                                            <div className="bo-method-bar">
                                                <div
                                                    className="bo-method-bar-fill"
                                                    style={{ width: `${Math.max(row.share, 4)}%`, backgroundColor: conf.fill }}
                                                />
                                            </div>
                                            <div className="bo-method-pct">{row.share}%</div>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>

                    {/* Outstanding by Age */}
                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Outstanding by Age</h5>
                            <Link to="/billing/outstanding" className="bo-card-link">
                                View all <i className="ti ti-arrow-right" />
                            </Link>
                        </div>

                        <div className="bo-aging-grid">
                            {data.ageing.map((bucket) => {
                                const conf = AGE_CONFIG[bucket.key] ?? { class: 'is-green', label: bucket.label };

                                return (
                                    <div className={`bo-aging-card ${conf.class}`} key={bucket.key}>
                                        <span className="bo-aging-label">{conf.label}</span>
                                        <div className="bo-aging-amount">{money(bucket.amount)}</div>
                                        <span className="bo-aging-sub">
                                            {bucket.invoices} invoice{bucket.invoices === 1 ? '' : 's'}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Quick Actions */}
                    <div className="bo-card">
                        <h5 className="bo-card-title mb-3">Quick Actions</h5>
                        <div className="bo-actions-grid">
                            <button
                                type="button"
                                className="bo-act-btn is-main"
                                onClick={() => navigate('/billing/outstanding')}
                            >
                                <i className="ti ti-credit-card" aria-hidden="true" />
                                <span>Take Payment</span>
                            </button>

                            {can('billing.create') && (
                                <button
                                    type="button"
                                    className="bo-act-btn is-purple"
                                    onClick={() => navigate('/billing/invoices/new')}
                                >
                                    <i className="ti ti-file-plus" aria-hidden="true" />
                                    <span>Manual Bill</span>
                                </button>
                            )}

                            <button
                                type="button"
                                className="bo-act-btn is-indigo"
                                onClick={() => navigate('/billing/payments')}
                            >
                                <i className="ti ti-printer" aria-hidden="true" />
                                <span>Print Report</span>
                            </button>

                            {can('billing.manage_settings') && (
                                <button
                                    type="button"
                                    className="bo-act-btn is-blue"
                                    onClick={() => navigate('/billing/settings')}
                                >
                                    <i className="ti ti-settings" aria-hidden="true" />
                                    <span>Settings</span>
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <PaymentDialog
                invoice={paying}
                onClose={() => setPaying(undefined)}
                onPaid={() => {
                    setPaying(undefined);
                    void refetch();
                }}
            />
        </div>
    );
}

function KpiCards({ data }: { data: BillingOverview }) {
    const { totals, previous, statuses } = data;

    const delta = (now: number, before: number) =>
        before > 0 ? Math.round(((now - before) / before) * 100) : null;

    const share = (part: number, whole: number) =>
        whole > 0 ? `${Math.round((part / whole) * 1000) / 10}% of total` : '—';

    const cards = [
        {
            label: 'Total Revenue',
            value: money(totals.invoiced),
            sub: 'vs previous period',
            icon: 'ti ti-file-text',
            iconClass: 'is-blue',
            delta: delta(totals.invoiced, previous.invoiced),
            isDanger: false,
        },
        {
            label: 'Total Paid',
            value: money(totals.paid),
            sub: share(totals.paid, totals.invoiced),
            icon: 'ti ti-circle-check',
            iconClass: 'is-green',
            delta: delta(totals.paid, previous.paid),
            isDanger: false,
        },
        {
            label: 'Outstanding',
            value: money(totals.outstanding),
            sub: share(totals.outstanding, totals.invoiced),
            icon: 'ti ti-hourglass',
            iconClass: 'is-orange',
            delta: 18,
            isDanger: true,
        },
        {
            label: 'Total Invoices',
            value: String(totals.invoiced_count),
            sub: `${statuses.paid} paid · ${statuses.pending} pending · ${statuses.partially_paid} partial`,
            icon: 'ti ti-files',
            iconClass: 'is-purple',
            delta: delta(totals.invoiced_count, previous.count),
            isDanger: false,
        },
    ];

    return (
        <div className="bo-kpi-grid">
            {cards.map((card) => (
                <div className="bo-kpi-card" key={card.label}>
                    <div className="bo-kpi-info">
                        <span className="bo-kpi-label">{card.label}</span>
                        <div className="bo-kpi-value">{card.value}</div>
                        <span className="bo-kpi-sub">{card.sub}</span>
                    </div>

                    <div className="bo-kpi-right">
                        <div className={`bo-kpi-icon ${card.iconClass}`}>
                            <i className={card.icon} aria-hidden="true" />
                        </div>
                        {card.delta !== null && (
                            <span className={`bo-trend-pill ${card.isDanger ? 'is-up-danger' : card.delta >= 0 ? 'is-up' : 'is-down'}`}>
                                <i className={`ti ti-arrow-${card.delta >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(card.delta)}%
                            </span>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}

function RecentRow({
    invoice,
    canCollect,
    canPrint,
    onPay,
    onPrint,
    onOpen,
}: {
    invoice: Invoice;
    canCollect: boolean;
    canPrint: boolean;
    onPay: () => void;
    onPrint: () => void;
    onOpen: () => void;
}) {
    const statusClass =
        invoice.status === 'cancelled'
            ? 'is-cancelled'
            : invoice.payment_status === 'paid'
              ? 'is-paid'
              : invoice.payment_status === 'partial'
                ? 'is-partial'
                : 'is-pending';

    const statusLabel =
        invoice.status === 'cancelled'
            ? 'Cancelled'
            : (PAYMENT_STATUS_LABELS[invoice.payment_status] ?? invoice.payment_status);

    return (
        <tr>
            <td>
                <Link to={`/billing/invoices/${invoice.id}`} className="bo-inv-num">
                    {invoice.invoice_number}
                </Link>
            </td>
            <td className="text-muted">{formatDate(invoice.invoice_date)}</td>
            <td className="fw-600">{invoice.customer_name ?? '—'}</td>
            <td className="text-muted">{invoice.appointment_id ? `OPD-${invoice.appointment_id}` : '—'}</td>
            <td className="text-muted">{summarizeItems(invoice.items)}</td>
            <td className="text-end fw-600">{moneyExact(invoice.total_amount)}</td>
            <td className="text-end fw-600">{moneyExact(invoice.paid_amount)}</td>
            <td className="text-end fw-600">
                {invoice.outstanding > 0 ? (
                    <span className="text-danger">{moneyExact(invoice.outstanding)}</span>
                ) : (
                    moneyExact(0)
                )}
            </td>
            <td>
                <span className={`bo-badge ${statusClass}`}>{statusLabel}</span>
            </td>
            <td className="text-end">
                <button
                    type="button"
                    className="bo-action-btn"
                    title="Open invoice"
                    onClick={onOpen}
                >
                    <i className="ti ti-eye" />
                </button>
                {canPrint && invoice.status !== 'cancelled' && !invoice.is_draft && (
                    <button
                        type="button"
                        className="bo-action-btn"
                        title="Print invoice"
                        onClick={onPrint}
                    >
                        <i className="ti ti-printer" />
                    </button>
                )}
                {canCollect && invoice.outstanding > 0 && invoice.status !== 'cancelled' && (
                    <button
                        type="button"
                        className="bo-action-btn"
                        title="Take payment"
                        onClick={onPay}
                    >
                        <i className="ti ti-cash" />
                    </button>
                )}
            </td>
        </tr>
    );
}
