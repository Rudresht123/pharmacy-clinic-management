import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { useDebounce } from '@/shared/hooks/useDebounce';
import {
    PAYMENT_METHOD_LABELS,
    usePayments,
    usePaymentsSummary,
    type PaymentRow,
} from '../api';
import {
    DateRangePicker,
    avatarTone,
    last30DaysWindow,
    formatDisplayDate,
    initials,
    money,
    moneyExact,
    useDismiss,
} from '../components/BillingWidgets';

/* ── Constants ─────────────────────────────────────────────────────────── */

type Method = 'all' | 'cash' | 'card' | 'upi' | 'bank_transfer' | 'other';

const METHODS: { value: Method; label: string; icon: string | null }[] = [
    { value: 'all', label: 'All payments', icon: null },
    { value: 'cash', label: 'Cash', icon: 'ti ti-cash' },
    { value: 'card', label: 'Card', icon: 'ti ti-credit-card' },
    { value: 'upi', label: 'UPI', icon: 'ti ti-device-mobile' },
    { value: 'bank_transfer', label: 'Bank transfer', icon: 'ti ti-building-bank' },
    { value: 'other', label: 'Other', icon: 'ti ti-dots' },
];

const METHOD_ICONS: Record<string, string> = {
    cash: 'ti ti-cash',
    card: 'ti ti-credit-card',
    upi: 'ti ti-device-mobile',
    bank_transfer: 'ti ti-building-bank',
    cheque: 'ti ti-file-text',
    online: 'ti ti-world',
    other: 'ti ti-dots',
};

const METHOD_DOT: Record<string, string> = {
    upi: '#2563eb',
    card: '#10b981',
    cash: '#f59e0b',
    bank_transfer: '#6366f1',
    online: '#06b6d4',
    cheque: '#a855f7',
    other: '#94a3b8',
};

const PAGE_SIZES = [10, 25, 50];

/**
 * Every payment taken, newest first — the register a till reconciles from.
 *
 * REFUNDS ARE ROWS HERE, marked rather than hidden. A register that omits
 * them cannot be reconciled against a drawer: the money left, and the sheet
 * has to say so.
 *
 * There is no "Record payment" button. A payment belongs to an invoice, and
 * taking one starts from the bill being settled — on Outstanding, or on the
 * invoice itself. A payment recorded against nothing is how a till and a
 * ledger come to disagree.
 */
export default function PaymentsPage() {
    const [dateRange, setDateRange] = useState(last30DaysWindow);
    const [method, setMethod] = useState<Method>('all');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);

    const debouncedSearch = useDebounce(search);

    useEffect(() => {
        setPage(1);
    }, [method, debouncedSearch, dateRange.from, dateRange.to]);

    const summaryQuery = usePaymentsSummary(dateRange);
    const summary = summaryQuery.data;

    const { data: pageData, isLoading, isFetching, isError, refetch } = usePayments({
        page,
        per_page: perPage,
        from: dateRange.from || undefined,
        to: dateRange.to || undefined,
        search: debouncedSearch.trim() || undefined,
        ...(method === 'all' ? {} : { method }),
    });

    const rows = pageData?.data ?? [];
    const meta = pageData?.meta;

    const methodCount = (value: string) =>
        value === 'all'
            ? summary?.transactions.value ?? 0
            : summary?.methods.find((m) => m.method === value)?.count ?? 0;

    function handleReset() {
        setDateRange(last30DaysWindow());
        setSearch('');
        setMethod('all');
    }

    const hasFilters = Boolean(search || method !== 'all');

    return (
        <div className="inv-page">
            <PageHeader
                title="Payments"
                subtitle="All payment transactions collected against invoices."
                icon="ti ti-cash"
                tone="teal"
                crumbs={[{ label: 'Billing', to: '/billing' }, { label: 'Payments' }]}
                actions={<ExportButton dateRange={dateRange} method={method} search={debouncedSearch} />}
            />

            <div className="inv-kpis">
                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-teal" aria-hidden="true">
                        <i className="ti ti-currency-rupee" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Total Collected</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value">{moneyExact(summary?.total_collected.value ?? 0)}</b>
                        </div>
                        {summary?.total_collected.change !== null && summary?.total_collected.change !== undefined && (
                            <span className={`inv-kpi-change-line ${summary.total_collected.change >= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${summary.total_collected.change >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(summary.total_collected.change)}% vs previous month
                            </span>
                        )}
                    </div>
                    <i className="ti ti-chart-bar inv-kpi-watermark" aria-hidden="true" />
                </div>

                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-blue" aria-hidden="true">
                        <i className="ti ti-credit-card" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Transactions</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value">{summary?.transactions.value ?? 0}</b>
                        </div>
                        {summary?.transactions.change !== null && summary?.transactions.change !== undefined && (
                            <span className={`inv-kpi-change-line ${summary.transactions.change >= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${summary.transactions.change >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(summary.transactions.change)}% vs previous month
                            </span>
                        )}
                    </div>
                    <i className="ti ti-chart-bar inv-kpi-watermark" aria-hidden="true" />
                </div>

                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-red" aria-hidden="true">
                        <i className="ti ti-receipt-refund" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Total Refunds</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value">{moneyExact(summary?.total_refunds.value ?? 0)}</b>
                        </div>
                        {summary?.total_refunds.change !== null && summary?.total_refunds.change !== undefined && (
                            <span className={`inv-kpi-change-line ${summary.total_refunds.change >= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${summary.total_refunds.change >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(summary.total_refunds.change)}% vs previous month
                            </span>
                        )}
                    </div>
                    <i className="ti ti-chart-bar inv-kpi-watermark" aria-hidden="true" />
                </div>

                <div className="pm-methods">
                    <div className="pm-methods-head">
                        <span className="pm-methods-icon" aria-hidden="true">
                            <i className="ti ti-chart-pie" />
                        </span>
                        <span className="pm-methods-title">Payment Methods</span>
                    </div>
                    <ul className="pm-methods-list">
                        {(summary?.methods ?? []).map((m) => (
                            <li key={m.method}>
                                <i style={{ background: METHOD_DOT[m.method] ?? '#94a3b8' }} />
                                <span>{PAYMENT_METHOD_LABELS[m.method] ?? m.method}</span>
                                <b>{m.share}%</b>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="inv-tabs">
                {METHODS.map((m) => (
                    <button
                        key={m.value}
                        type="button"
                        className={`inv-tab${method === m.value ? ' is-active' : ''}`}
                        onClick={() => setMethod(m.value)}
                    >
                        {m.icon && <i className={m.icon} aria-hidden="true" />}
                        {m.label} ({methodCount(m.value)})
                    </button>
                ))}
            </div>

            <div className="inv-card">
                <div className="inv-filters">
                    <label className="inv-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by receipt, invoice, patient..."
                        />
                    </label>

                    <DateRangePicker value={dateRange} onChange={setDateRange} />

                    <button type="button" className="inv-reset" onClick={handleReset} disabled={!hasFilters}>
                        <i className="ti ti-refresh" aria-hidden="true" />
                        Reset
                    </button>
                </div>

                {isLoading ? (
                    <LoadingBlock label="Loading payments…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon="ti ti-cash"
                        tone="teal"
                        title="No payments yet"
                        description="Payments appear here as the counter collects against invoices."
                    />
                ) : (
                    <>
                        <div className={`inv-table-wrap${isFetching ? ' is-refreshing' : ''}`}>
                            <table className="inv-table">
                                <thead>
                                    <tr>
                                        <th>Date &amp; Time</th>
                                        <th>Receipt #</th>
                                        <th>Invoice #</th>
                                        <th>Patient</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th className="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <PaymentRowLine key={row.id} row={row} />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {meta && (
                            <div className="inv-foot">
                                <Pagination
                                    page={meta.current_page}
                                    pageCount={meta.last_page}
                                    total={meta.total}
                                    perPage={meta.per_page}
                                    onChange={setPage}
                                />

                                <select
                                    className="inv-per-page"
                                    value={perPage}
                                    onChange={(e) => setPerPage(Number(e.target.value))}
                                    aria-label="Payments per page"
                                >
                                    {PAGE_SIZES.map((size) => (
                                        <option key={size} value={size}>
                                            {size} / page
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

/* ── One row ───────────────────────────────────────────────────────────── */

function PaymentRowLine({ row }: { row: PaymentRow }) {
    const patientName = row.patient_name || 'Walk-in';

    return (
        <tr>
            <td>
                <div>
                    <span className="inv-main is-plain">{formatDisplayDate(row.paid_at ?? '')}</span>
                    <span className="inv-sub">
                        {row.paid_at
                            ? new Date(row.paid_at).toLocaleTimeString('en-IN', {
                                  hour: '2-digit',
                                  minute: '2-digit',
                                  hour12: true,
                              }).toUpperCase()
                            : ''}
                    </span>
                </div>
            </td>
            <td>
                <span className="inv-num">{row.receipt_number}</span>
            </td>
            <td>
                <Link to={`/billing/invoices/${row.invoice_id}`} className="inv-number">
                    {row.invoice_number ?? '—'}
                </Link>
            </td>
            <td>
                <div className="inv-person">
                    <span className={`inv-avatar ${avatarTone(patientName)}`}>{initials(patientName)}</span>
                    <span className="inv-main">{patientName}</span>
                </div>
            </td>
            <td>
                <span className={`inv-num ${row.is_refund ? 'is-owed' : ''}`} style={row.is_refund ? undefined : { fontWeight: 700 }}>
                    {row.is_refund ? '−' : ''}
                    {money(row.amount)}
                </span>
            </td>
            <td>
                <span className="inv-method">
                    <i className={METHOD_ICONS[row.method] ?? 'ti ti-dots'} aria-hidden="true" />
                    {PAYMENT_METHOD_LABELS[row.method] ?? row.method}
                </span>
            </td>
            <td>
                <span className="inv-sub">{row.reference ?? '—'}</span>
            </td>
            <td>
                {row.is_refund ? (
                    <span className="inv-pill is-red">Refund</span>
                ) : (
                    <span className="inv-pill is-green">Paid</span>
                )}
            </td>
            <td className="text-end">
                <Link to={`/billing/invoices/${row.invoice_id}`} className="inv-btn">
                    <i className="ti ti-eye" aria-hidden="true" />
                    View
                </Link>
            </td>
        </tr>
    );
}

/* ── Export dropdown ───────────────────────────────────────────────────── */

function ExportButton({
    dateRange,
    method,
    search,
}: {
    dateRange: { from: string; to: string };
    method: Method;
    search: string;
}) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    function exportCsv() {
        setOpen(false);

        const params = new URLSearchParams({
            from: dateRange.from,
            to: dateRange.to,
        });

        if (method !== 'all') params.set('method', method);
        if (search.trim()) params.set('search', search.trim());

        window.open(`/api/v1/tenant/billing/payments/export?${params.toString()}`, '_blank');
    }

    return (
        <div className="inv-menu-wrap" ref={box}>
            <button type="button" className="inv-export" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
                <i className="ti ti-download" aria-hidden="true" />
                Export
                <i className="ti ti-chevron-down" aria-hidden="true" />
            </button>

            {open && (
                <div className="inv-menu is-right" role="menu">
                    <button type="button" role="menuitem" onClick={exportCsv}>
                        <i className="ti ti-file-spreadsheet" aria-hidden="true" />
                        Export this list (CSV)
                    </button>
                </div>
            )}
        </div>
    );
}
