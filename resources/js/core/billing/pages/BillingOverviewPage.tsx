import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { LoadingBlock, ErrorState, EmptyState } from '@/shared/components/ui/Feedback';
import { formatDate } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    PAYMENT_STATUS_LABELS,
    useBillingOverview,
    type BillingOverview,
    type Invoice,
    type InvoiceItem,
    type OutstandingPatient,
} from '../api';
import { CollectionTrend } from '../components/CollectionTrend';
import { PaymentDialog } from '../components/PaymentDialog';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { BarList } from '@/shared/components/ui/BarList';

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

/** One entry per InvoiceItem source_type — the type tag and the tinted icon. */
const TYPE_CONFIG: Record<string, { label: string; icon: string; cls: string }> = {
    consultation: { label: 'OPD', icon: 'ti ti-stethoscope', cls: 'is-blue' },
    pharmacy_sale_item: { label: 'Pharmacy', icon: 'ti ti-pill', cls: 'is-green' },
    lab_test: { label: 'Lab', icon: 'ti ti-flask', cls: 'is-purple' },
    procedure: { label: 'Procedure', icon: 'ti ti-first-aid-kit', cls: 'is-orange' },
    service: { label: 'Other', icon: 'ti ti-layout-grid', cls: 'is-muted' },
    custom: { label: 'Other', icon: 'ti ti-layout-grid', cls: 'is-muted' },
};

/** The line whose amount weighs most in the invoice — what the Type column names. */
function dominantType(items?: InvoiceItem[]): { label: string; cls: string } {
    if (!items || items.length === 0) {
        return { label: '—', cls: 'is-muted' };
    }

    const totals = new Map<string, number>();

    for (const item of items) {
        totals.set(item.source_type, (totals.get(item.source_type) ?? 0) + item.line_total);
    }

    let bestType: string = items[0].source_type;
    let bestAmount = -1;

    for (const [type, amount] of totals) {
        if (amount > bestAmount) {
            bestAmount = amount;
            bestType = type;
        }
    }

    const conf = TYPE_CONFIG[bestType] ?? TYPE_CONFIG.service;

    return { label: conf.label, cls: conf.cls };
}

function summarizeItems(items?: InvoiceItem[]): string {
    if (!items || items.length === 0) return 'Services';

    const names = items.map((item) => item.description.split(' - ')[0] || item.description);

    if (names.length <= 2) return names.join(', ');

    return `${names[0]}, ${names[1]} (+${names.length - 2} more)`;
}

export default function BillingOverviewPage() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();

    const [range, setRange] = useState<(typeof RANGES)[number]['key']>('7');
    const [paying, setPaying] = useState<Invoice>();
    const [createOpen, setCreateOpen] = useState(false);

    const params = useMemo(
        () => windowFor((RANGES.find((r) => r.key === range) ?? RANGES[0]).days),
        [range],
    );

    const { data, isLoading, isError, refetch } = useBillingOverview(params);
    const generate = useGenerateDocument();

    if (isLoading) return <LoadingBlock label="Loading billing overview…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    const hasCategories = data.categories.some((category) => category.amount > 0);
    const hasMethods = data.methods.length > 0;

    async function print(invoice: Invoice) {
        const document_ = await generate.mutateAsync({
            document_type: 'clinic_invoice',
            subject_id: invoice.id,
        });

        await openDocument(document_);
    }

    return (
        <div className="bo-container">
            {/* Header */}
            <div className="bo-header-card">
                <div className="bo-header-title-group">
                    <div>
                        <h4>Billing Dashboard</h4>
                        <p>Overview of your clinic&rsquo;s billing, payments and revenue.</p>
                    </div>
                </div>

                {can('billing.create') && (
                    <div className="bod-create-dd" onBlur={() => setCreateOpen(false)}>
                        <button
                            type="button"
                            className="bod-create-btn"
                            onClick={() => setCreateOpen((open) => !open)}
                        >
                            <i className="ti ti-plus" aria-hidden="true" />
                            Create Invoice
                            <i className="ti ti-chevron-down" aria-hidden="true" />
                        </button>

                        {createOpen && (
                            <div className="bod-create-menu">
                                <Link to="/billing/invoices/new">New manual invoice</Link>
                                <Link to="/billing/invoices">All invoices</Link>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* KPI cards */}
            <KpiCards data={data} />

            {/* Collection Trend + Collection by Module */}
            <div className="bo-main-layout">
                <div className="bo-left-col">
                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Collection Trend</h5>
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

                        <div className="bo-card-body">
                            <CollectionTrend points={data.trend} format={money} />
                        </div>
                    </div>
                </div>

                <div className="bo-right-col">
                    <div className="bo-card db-donut">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Collection by Module</h5>
                        </div>

                        <div className={`bo-card-body ${hasCategories ? '' : 'is-center'}`}>
                            {hasCategories ? (
                                <DonutChart
                                    slices={data.categories.map((category) => ({
                                        label: category.label,
                                        value: category.amount,
                                    }))}
                                    centreLabel="Total Collection"
                                    format={money}
                                />
                            ) : (
                                <EmptyState
                                    icon="ti ti-chart-donut"
                                    title="Nothing billed yet"
                                    description="Collection by module shows up once invoices land in this window."
                                />
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Recent Invoices / Outstanding Patients + Payment Mode / Top Services */}
            <div className="bo-main-layout">
                <div className="bo-left-col">
                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Recent Invoices</h5>
                            <Link to="/billing/invoices" className="bo-card-link">
                                View All <i className="ti ti-arrow-right" />
                            </Link>
                        </div>

                        <div className="bo-card-body">
                            {data.recent.length === 0 ? (
                                <EmptyState
                                    icon="ti ti-file-invoice"
                                    title="No invoices yet"
                                    description="Invoices raised in this window will show up here."
                                />
                            ) : (
                                <div className="bo-table-wrap">
                                    <table className="bo-table">
                                        <thead>
                                            <tr>
                                                <th style={{ width: 28 }}>
                                                    <input type="checkbox" className="bod-check" aria-label="Select all" />
                                                </th>
                                                <th>#</th>
                                                <th>Date</th>
                                                <th>Patient</th>
                                                <th>Type</th>
                                                <th>Doctor / Service</th>
                                                <th className="text-end">Total</th>
                                                <th className="text-end">Paid</th>
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

                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Outstanding Patients</h5>
                            <Link to="/billing/outstanding" className="bo-card-link">
                                View All <i className="ti ti-arrow-right" />
                            </Link>
                        </div>

                        <div className="bo-card-body">
                            {data.outstanding_patients_list.length === 0 ? (
                                <EmptyState
                                    icon="ti ti-mood-smile"
                                    title="Nobody owes money"
                                    description="Every bill in this window has been settled."
                                />
                            ) : (
                                <div className="bo-table-wrap">
                                    <table className="bo-table">
                                        <thead>
                                            <tr>
                                                <th style={{ width: 28 }}>
                                                    <input type="checkbox" className="bod-check" aria-label="Select all" />
                                                </th>
                                                <th>#</th>
                                                <th>Patient</th>
                                                <th>Last Visit</th>
                                                <th className="text-end">Total</th>
                                                <th className="text-end">Paid</th>
                                                <th className="text-end">Due</th>
                                                <th>Days</th>
                                                <th className="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.outstanding_patients_list.map((row, index) => (
                                                <OutstandingRow key={`${row.patient}-${index}`} row={row} index={index} />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="bo-right-col">
                    <div className="bo-card db-donut">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Payment Mode Breakdown</h5>
                            <span className="fs-12 text-muted fw-600">This Month ▾</span>
                        </div>

                        <div className={`bo-card-body ${hasMethods ? '' : 'is-center'}`}>
                            {hasMethods ? (
                                <DonutChart
                                    slices={data.methods.map((row) => ({
                                        label: row.method.replace(/_/g, ' '),
                                        value: row.amount,
                                    }))}
                                    centreLabel="Total"
                                    format={money}
                                />
                            ) : (
                                <EmptyState
                                    icon="ti ti-cash-off"
                                    title="Nothing collected yet"
                                    description="Payment modes show up once a payment is recorded."
                                />
                            )}
                        </div>
                    </div>

                    <div className="bo-card">
                        <div className="bo-card-title-row">
                            <h5 className="bo-card-title">Top Services by Revenue</h5>
                            <span className="fs-12 text-muted fw-600">This Month ▾</span>
                        </div>

                        <div className={`bo-card-body ${hasCategories ? '' : 'is-center'}`}>
                            {hasCategories ? (
                                <TopServices categories={data.categories} />
                            ) : (
                                <EmptyState
                                    icon="ti ti-list-details"
                                    title="No revenue yet"
                                    description="The top billed services will rank here."
                                />
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

function trendTone(delta: number, riseIsBad: boolean): string {
    const good = delta >= 0 ? !riseIsBad : riseIsBad;

    return good ? (delta >= 0 ? 'is-up' : 'is-down') : 'is-up-danger';
}

function KpiCards({ data }: { data: BillingOverview }) {
    const { totals, previous } = data;

    const delta = (now: number, before: number) =>
        before > 0 ? Math.round(((now - before) / before) * 100) : null;

    const cards = [
        {
            label: 'Total Invoices',
            value: String(totals.invoiced_count),
            icon: 'ti ti-file-text',
            cls: 'is-blue',
            sub: 'This month',
            delta: delta(totals.invoiced_count, previous.count),
            riseIsBad: false,
        },
        {
            label: 'Total Collection',
            value: money(totals.paid),
            icon: 'ti ti-wallet',
            cls: 'is-green',
            sub: 'This month',
            delta: delta(totals.paid, previous.paid),
            riseIsBad: false,
        },
        {
            label: 'Outstanding',
            value: money(totals.outstanding),
            icon: 'ti ti-hourglass-high',
            cls: 'is-orange',
            sub: `${totals.outstanding_patients} patient${totals.outstanding_patients === 1 ? '' : 's'}`,
            delta: delta(totals.outstanding, previous.invoiced - previous.paid),
            riseIsBad: true,
        },
        {
            label: 'New Patients (Billed)',
            value: String(totals.billed_patients),
            icon: 'ti ti-users',
            cls: 'is-purple',
            sub: 'This month',
            delta: delta(totals.billed_patients, previous.billed_patients),
            riseIsBad: false,
        },
    ];

    return (
        <div className="bod-kpi-grid">
            {cards.map((card) => (
                <div className={`bod-kpi-card ${card.cls}`} key={card.label}>
                    <div className="bod-kpi-head">
                        <span className={`bod-kpi-icon ${card.cls}`}>
                            <i className={card.icon} aria-hidden="true" />
                        </span>
                        <span className="bod-kpi-label">{card.label}</span>
                    </div>

                    <div className="bod-kpi-value-row">
                        <span className="bod-kpi-value">{card.value}</span>
                        {card.delta !== null && (
                            <span className={`bod-kpi-delta ${trendTone(card.delta, card.riseIsBad)}`}>
                                <i className={`ti ti-arrow-${card.delta >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(card.delta)}%
                            </span>
                        )}
                    </div>

                    <span className="bod-kpi-sub">{card.sub}</span>
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
            : invoice.payment_status === 'unpaid'
              ? 'Due'
              : (PAYMENT_STATUS_LABELS[invoice.payment_status] ?? invoice.payment_status);

    const type = dominantType(invoice.items);

    /* OPD names its doctor; a lab bill names its tests; a pharmacy bill
       names nothing — there is no third party to credit for a sale. */
    const serviceText =
        invoice.doctor_name ?? (type.label === 'Lab' ? summarizeItems(invoice.items) : '—');

    return (
        <tr>
            <td>
                <input type="checkbox" className="bod-check" aria-label={`Select ${invoice.invoice_number}`} />
            </td>
            <td>
                <Link to={`/billing/invoices/${invoice.id}`} className="bo-inv-num">
                    {invoice.invoice_number}
                </Link>
            </td>
            <td className="text-muted">{formatDate(invoice.invoice_date)}</td>
            <td className="fw-600 bo-table-truncate" title={invoice.customer_name ?? undefined}>
                {invoice.customer_name ?? '—'}
            </td>
            <td>
                <span className={`bod-type-tag ${type.cls}`}>{type.label}</span>
            </td>
            <td className="text-muted bo-table-truncate" title={serviceText === '—' ? undefined : serviceText}>
                {serviceText}
            </td>
            <td className="text-end fw-600">{moneyExact(invoice.total_amount)}</td>
            <td className="text-end fw-600">{moneyExact(invoice.paid_amount)}</td>
            <td>
                <span className={`bo-badge ${statusClass}`}>{statusLabel}</span>
            </td>
            <td className="text-end">
                <button type="button" className="bo-action-btn" title="Open invoice" onClick={onOpen}>
                    <i className="ti ti-eye" />
                </button>
                {canPrint && invoice.status !== 'cancelled' && !invoice.is_draft && (
                    <button type="button" className="bo-action-btn" title="Print invoice" onClick={onPrint}>
                        <i className="ti ti-printer" />
                    </button>
                )}
                {canCollect && invoice.outstanding > 0 && invoice.status !== 'cancelled' && (
                    <button type="button" className="bo-action-btn" title="Take payment" onClick={onPay}>
                        <i className="ti ti-cash" />
                    </button>
                )}
            </td>
        </tr>
    );
}

function OutstandingRow({ row, index }: { row: OutstandingPatient; index: number }) {
    const wa = row.phone ? `https://wa.me/${row.phone.replace(/\D/g, '')}` : null;

    return (
        <tr>
            <td>
                <input type="checkbox" className="bod-check" aria-label={`Select ${row.patient}`} />
            </td>
            <td className="text-muted">{String(index + 1).padStart(2, '0')}</td>
            <td className="fw-600 bo-table-truncate" title={row.patient}>
                {row.customer_id ? (
                    <Link to={`/customers/${row.customer_id}`} className="bo-inv-num">
                        {row.patient}
                    </Link>
                ) : (
                    row.patient
                )}
            </td>
            <td className="text-muted">{formatDate(row.last_visit)}</td>
            <td className="text-end fw-600">{moneyExact(row.total)}</td>
            <td className="text-end fw-600">{moneyExact(row.paid)}</td>
            <td className="text-end fw-600 text-danger">{moneyExact(row.due)}</td>
            <td>
                <span className="bod-days-tag">
                    {row.days} day{row.days === 1 ? '' : 's'}
                </span>
            </td>
            <td className="text-end">
                {row.customer_id && (
                    <Link className="bo-action-btn" title="View patient" to={`/customers/${row.customer_id}`}>
                        <i className="ti ti-eye" />
                    </Link>
                )}
                {wa && (
                    <a className="bo-action-btn" title="Message on WhatsApp" href={wa} target="_blank" rel="noreferrer">
                        <i className="ti ti-brand-whatsapp" />
                    </a>
                )}
            </td>
        </tr>
    );
}

/** Relabelled for this list only — "OPD" names the module, "Consultation" names the service. */
const SERVICE_RELABEL: Record<string, string> = {
    OPD: 'Consultation',
    Pharmacy: 'Medicines',
    Laboratory: 'Lab Tests',
    Procedures: 'Procedures',
    Others: 'Others',
};

function TopServices({ categories }: { categories: BillingOverview['categories'] }) {
    return (
        <BarList
            rows={categories
                .filter((category) => category.amount > 0)
                .map((category) => ({
                    label: SERVICE_RELABEL[category.label] ?? category.label,
                    value: category.amount,
                }))}
            format={money}
            max={5}
        />
    );
}
