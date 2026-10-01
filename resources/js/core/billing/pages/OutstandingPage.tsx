import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { Button } from '@/shared/components/ui/Button';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { PAYMENT_STATUS_LABELS, useInvoices, useOutstandingSummary, type Invoice } from '../api';
import { PaymentDialog } from '../components/PaymentDialog';
import {
    DateRangePicker,
    avatarTone,
    last30DaysWindow,
    formatDisplayDate,
    initials,
    money,
    useDismiss,
} from '../components/BillingWidgets';

const STATUS_OPTIONS = [
    { value: '', label: 'All Status' },
    { value: 'unpaid', label: 'Unpaid' },
    { value: 'partial', label: 'Part paid' },
];

const AGING_OPTIONS = [
    { value: '', label: 'All' },
    { value: 'fresh', label: '0–7 days' },
    { value: 'recent', label: '8–30 days' },
    { value: 'stale', label: '31–60 days' },
    { value: 'old', label: '60+ days' },
];

const PAGE_SIZES = [10, 25, 50];

function daysSince(iso: string | null): number {
    if (!iso) return 0;

    return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000));
}

/**
 * The work queue: bills with money still on them.
 *
 * Sorted oldest-first by default rather than newest, because this is the
 * screen somebody works THROUGH — the debt that has been sitting longest is
 * the one worth a phone call, and a list that buries it under today's
 * part-payments is a list nobody finishes.
 *
 * Drafts never appear (useInvoices only hides them unless include_drafts is
 * set, and this screen never sets it) — a visit still collecting charges
 * owes nothing yet, and putting it here would send somebody to chase a
 * patient who has not been billed.
 */
export default function OutstandingPage() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();
    const canCollect = can('billing.collect_payment');

    const [dateRange, setDateRange] = useState(last30DaysWindow);
    const [status, setStatus] = useState('');
    const [aging, setAging] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [paying, setPaying] = useState<Invoice>();

    const debouncedSearch = useDebounce(search);

    useEffect(() => {
        setPage(1);
    }, [status, aging, debouncedSearch, dateRange.from, dateRange.to]);

    useEffect(() => {
        setSelected(new Set());
    }, [page]);

    const summaryQuery = useOutstandingSummary(dateRange);
    const summary = summaryQuery.data;

    const { data: pageData, isLoading, isFetching, isError, refetch } = useInvoices({
        page,
        per_page: perPage,
        outstanding_only: true,
        from: dateRange.from || undefined,
        to: dateRange.to || undefined,
        payment_status: status || undefined,
        aging: aging || undefined,
        search: debouncedSearch.trim() || undefined,
        sort: 'invoice_date',
        direction: 'asc',
    });

    const rows = pageData?.data ?? [];
    const meta = pageData?.meta;

    function toggleSelectAll() {
        if (selected.size === rows.length && rows.length > 0) {
            setSelected(new Set());
        } else {
            setSelected(new Set(rows.map((r) => r.id)));
        }
    }

    function toggleSelectOne(id: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function handleReset() {
        setDateRange(last30DaysWindow());
        setStatus('');
        setAging('');
        setSearch('');
    }

    const hasFilters = Boolean(status || aging || search);

    return (
        <div className="inv-page">
            <PageHeader
                title="Outstanding Invoices"
                subtitle="Invoices with pending or partially paid amounts."
                icon="ti ti-clock-dollar"
                tone="amber"
                crumbs={[{ label: 'Billing', to: '/billing' }, { label: 'Outstanding Invoices' }]}
            />

            <div className="inv-kpis">
                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-red" aria-hidden="true">
                        <i className="ti ti-currency-rupee" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Total Outstanding</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value inv-owed is-owed">{money(summary?.total_outstanding ?? 0)}</b>
                        </div>
                        <span className="inv-kpi-sub">Across {summary?.total_invoices ?? 0} invoices</span>
                    </div>
                </div>

                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-blue" aria-hidden="true">
                        <i className="ti ti-file-text" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Total Invoices</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value">{summary?.total_invoices ?? 0}</b>
                        </div>
                        <span className="inv-kpi-sub">With pending amount</span>
                    </div>
                </div>

                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-red" aria-hidden="true">
                        <i className="ti ti-alert-circle" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Unpaid Invoices</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value">{summary?.unpaid.count ?? 0}</b>
                        </div>
                        <span className="inv-kpi-sub">Total amount: {money(summary?.unpaid.amount ?? 0)}</span>
                    </div>
                </div>

                <div className="inv-kpi">
                    <span className="inv-kpi-icon is-orange" aria-hidden="true">
                        <i className="ti ti-chart-pie" />
                    </span>
                    <div className="inv-kpi-body">
                        <span className="inv-kpi-label">Partially Paid Invoices</span>
                        <div className="inv-kpi-row">
                            <b className="inv-kpi-value">{summary?.partial.count ?? 0}</b>
                        </div>
                        <span className="inv-kpi-sub">Total amount: {money(summary?.partial.amount ?? 0)}</span>
                    </div>
                </div>
            </div>

            <div className="inv-card">
                <div className="inv-filters">
                    <label className="inv-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by invoice number, patient name or phone..."
                        />
                    </label>

                    <DateRangePicker value={dateRange} onChange={setDateRange} />

                    <label className="inv-select">
                        <i className="ti ti-clock" aria-hidden="true" />
                        <select value={status} onChange={(e) => setStatus(e.target.value)}>
                            {STATUS_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="inv-select">
                        <i className="ti ti-hourglass" aria-hidden="true" />
                        <select value={aging} onChange={(e) => setAging(e.target.value)}>
                            {AGING_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.value === '' ? 'Aging: All' : opt.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button type="button" className="inv-reset" onClick={handleReset} disabled={!hasFilters}>
                        <i className="ti ti-refresh" aria-hidden="true" />
                        Reset
                    </button>
                </div>
            </div>

            <div className="inv-card">
                <div className="inv-toolbar">
                    <label className="inv-entries">
                        Show
                        <select value={perPage} onChange={(e) => setPerPage(Number(e.target.value))}>
                            {PAGE_SIZES.map((size) => (
                                <option key={size} value={size}>
                                    {size}
                                </option>
                            ))}
                        </select>
                        Entries
                    </label>

                    <ExportButton dateRange={dateRange} status={status} aging={aging} search={debouncedSearch} />
                </div>

                {isLoading ? (
                    <LoadingBlock label="Loading outstanding invoices…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon="ti ti-circle-check"
                        tone="emerald"
                        title="Nothing outstanding"
                        description="Every bill raised so far has been settled."
                    />
                ) : (
                    <>
                        <div className={`inv-table-wrap${isFetching ? ' is-refreshing' : ''}`}>
                            <table className="inv-table">
                                <thead>
                                    <tr>
                                        <th className="inv-check-col">
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                checked={selected.size === rows.length && rows.length > 0}
                                                onChange={toggleSelectAll}
                                                aria-label="Select all invoices"
                                            />
                                        </th>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Patient</th>
                                        <th>Total</th>
                                        <th>Paid</th>
                                        <th>Outstanding</th>
                                        <th>Days</th>
                                        <th>Status</th>
                                        <th className="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((invoice) => {
                                        const isSelected = selected.has(invoice.id);
                                        const patientName = invoice.customer_name || 'Walk-in';
                                        const days = daysSince(invoice.invoice_date);
                                        const isPartial = invoice.payment_status === 'partial';

                                        return (
                                            <tr key={invoice.id} className={isSelected ? 'is-selected' : undefined}>
                                                <td className="inv-check-col">
                                                    <input
                                                        type="checkbox"
                                                        className="form-check-input"
                                                        checked={isSelected}
                                                        onChange={() => toggleSelectOne(invoice.id)}
                                                        aria-label={`Select invoice ${invoice.invoice_number}`}
                                                    />
                                                </td>
                                                <td>
                                                    <Link to={`/billing/invoices/${invoice.id}`} className="inv-number">
                                                        {invoice.invoice_number}
                                                    </Link>
                                                </td>
                                                <td>
                                                    <span className="inv-main is-plain">
                                                        {formatDisplayDate(invoice.invoice_date)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <div className="inv-person">
                                                        <span className={`inv-avatar ${avatarTone(patientName)}`}>
                                                            {initials(patientName)}
                                                        </span>
                                                        <span className="inv-main">{patientName}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span className="inv-num">{money(invoice.total_amount)}</span>
                                                </td>
                                                <td>
                                                    <span className="inv-num">{money(invoice.paid_amount)}</span>
                                                </td>
                                                <td>
                                                    <span className="inv-num inv-owed is-owed">
                                                        {money(invoice.outstanding)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span className={`inv-pill ${days >= 7 ? 'is-red' : days >= 3 ? 'is-orange' : 'is-green'}`}>
                                                        {days}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span className={`inv-pill ${isPartial ? 'is-orange' : 'is-red'}`}>
                                                        {PAYMENT_STATUS_LABELS[invoice.payment_status] ?? invoice.payment_status}
                                                    </span>
                                                </td>
                                                <td className="text-end">
                                                    <div className="inv-actions justify-content-end">
                                                        {canCollect && (
                                                            <Button size="sm" icon="ti ti-cash" onClick={() => setPaying(invoice)}>
                                                                Take payment
                                                            </Button>
                                                        )}
                                                        <Button
                                                            size="sm"
                                                            variant="light"
                                                            onClick={() => navigate(`/billing/invoices/${invoice.id}`)}
                                                        >
                                                            View
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
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
                            </div>
                        )}
                    </>
                )}
            </div>

            <PaymentDialog
                invoice={paying}
                onClose={() => setPaying(undefined)}
                onPaid={() => {
                    setPaying(undefined);
                    void refetch();
                    void summaryQuery.refetch();
                }}
            />
        </div>
    );
}

/* ── Export dropdown ───────────────────────────────────────────────────── */

function ExportButton({
    dateRange,
    status,
    aging,
    search,
}: {
    dateRange: { from: string; to: string };
    status: string;
    aging: string;
    search: string;
}) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    function exportCsv() {
        setOpen(false);

        const params = new URLSearchParams({
            outstanding_only: '1',
            from: dateRange.from,
            to: dateRange.to,
        });

        if (status) params.set('payment_status', status);
        if (aging) params.set('aging', aging);
        if (search.trim()) params.set('search', search.trim());

        window.open(`/api/v1/tenant/invoices/export?${params.toString()}`, '_blank');
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
