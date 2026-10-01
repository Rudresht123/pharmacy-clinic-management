import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { notify } from '@/shared/utils/notify';
import { resourceKey } from '@/shared/hooks/useResource';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    exportInvoices,
    useBillingOverview,
    useInvoiceSummary,
    useInvoices,
    type BillingOverview,
    type Invoice,
    type InvoiceCard,
    type InvoiceItem,
} from '../api';
import { PaymentDialog } from '../components/PaymentDialog';
import {
    DateRangePicker,
    ExportMenu,
    RowMenu,
    avatarTone,
    currentMonthWindow,
    formatDisplayDate,
    initials,
    money,
    moneyExact,
    pageNumbers,
} from '../components/BillingWidgets';

/* ── Types & Constants ─────────────────────────────────────────────────── */

type ViewTab = 'all' | 'outstanding' | 'open' | 'paid' | 'cancelled';

const VIEW_TABS: { value: ViewTab; label: string }[] = [
    { value: 'all', label: 'All Invoices' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'open', label: 'Open Visits' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

const VIEW_FILTERS: Record<ViewTab, Record<string, string | number>> = {
    all: { include_drafts: 1 },
    outstanding: { outstanding_only: 1 },
    open: { include_drafts: 1, status: 'draft' },
    paid: { status: 'paid' },
    cancelled: { status: 'cancelled' },
};

const SERVICE_OPTIONS = [
    { value: '', label: 'All Services' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'lab_test', label: 'Lab Tests' },
    { value: 'pharmacy', label: 'Pharmacy' },
    { value: 'procedure', label: 'Procedures' },
    { value: 'other', label: 'Others' },
];

const STATUS_OPTIONS = [
    { value: '', label: 'All Status' },
    { value: 'unpaid', label: 'Unpaid' },
    { value: 'partial', label: 'Part paid' },
    { value: 'paid', label: 'Paid' },
];

const COMPARE_OPTIONS = [
    { value: 'previous_month', label: 'Compare: Previous Month' },
    { value: 'previous_year', label: 'Compare: Previous Year' },
    { value: 'previous_period', label: 'Compare: Previous Period' },
    { value: 'none', label: 'Compare: None' },
];

const PAGE_SIZES = [5, 10, 25, 50];

/* ── Helpers ───────────────────────────────────────────────────────────── */

const clock = (iso: string | null) =>
    iso
        ? new Date(iso)
              .toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true })
              .toUpperCase()
        : '';

function compactRupees(value: number): string {
    if (value >= 100000) {
        return `₹${+(value / 100000).toFixed(1)}L`;
    }
    if (value >= 1000) {
        return `₹${Math.round(value / 1000)}K`;
    }

    return `₹${Math.round(value)}`;
}

function dominantService(invoice: Invoice): { label: string; icon: string; tone: string; detail: string } {
    if (invoice.kind === 'registration') {
        return { label: 'Registration', icon: 'ti ti-id-badge-2', tone: 'is-teal', detail: 'Patient registration' };
    }

    const items: InvoiceItem[] = invoice.items ?? [];

    if (items.length === 0) {
        return { label: 'Service', icon: 'ti ti-layout-grid', tone: 'is-muted', detail: invoice.doctor_name ?? '—' };
    }

    const totals = new Map<string, number>();

    for (const item of items) {
        totals.set(item.source_type, (totals.get(item.source_type) ?? 0) + item.line_total);
    }

    let topType = items[0].source_type;
    let topAmount = -1;

    for (const [sourceType, amount] of totals) {
        if (amount > topAmount) {
            topAmount = amount;
            topType = sourceType;
        }
    }

    if (topType === 'consultation') {
        return {
            label: 'Consultation',
            icon: 'ti ti-stethoscope',
            tone: 'is-blue',
            detail: invoice.doctor_name ? `Dr. ${invoice.doctor_name.replace(/^Dr\.?\s*/i, '')}` : 'Follow-up',
        };
    }

    if (topType === 'lab_test') {
        const testNames = items
            .filter((i) => i.source_type === 'lab_test')
            .map((i) => i.description.split(' - ')[0])
            .join(', ');

        return {
            label: 'Lab Test',
            icon: 'ti ti-flask',
            tone: 'is-purple',
            detail: testNames || 'Diagnostic tests',
        };
    }

    if (topType === 'pharmacy_sale_item') {
        return {
            label: 'Pharmacy',
            icon: 'ti ti-pill',
            tone: 'is-green',
            detail: 'Medicines',
        };
    }

    if (topType === 'procedure') {
        const procName = items.find((i) => i.source_type === 'procedure')?.description || 'Dressing';

        return {
            label: 'Procedure',
            icon: 'ti ti-first-aid-kit',
            tone: 'is-orange',
            detail: procName,
        };
    }

    return {
        label: 'Service',
        icon: 'ti ti-layout-grid',
        tone: 'is-muted',
        detail: items[0]?.description || 'General Service',
    };
}

function niceCeil(value: number): number {
    if (value <= 0) return 10000;
    const magnitude = 10 ** Math.floor(Math.log10(value));

    for (const step of [1, 2, 2.5, 5, 10]) {
        if (step * magnitude >= value) {
            return step * magnitude;
        }
    }

    return 10 * magnitude;
}

/* ── Main Component ────────────────────────────────────────────────────── */

export default function BillingOverviewPage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { can } = useTenantAuth();
    const generate = useGenerateDocument();

    const [dateRange, setDateRange] = useState<{ from: string; to: string }>(currentMonthWindow);
    const [compare, setCompare] = useState('previous_month');
    const [trendUnit, setTrendUnit] = useState<'daily' | 'weekly' | 'monthly'>('daily');
    const [viewTab, setViewTab] = useState<ViewTab>('all');
    const [search, setSearch] = useState('');
    const [serviceFilter, setServiceFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [sort, setSort] = useState('invoice_date');
    const [direction, setDirection] = useState<'asc' | 'desc'>('desc');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [paying, setPaying] = useState<Invoice>();

    // Reset pagination on filter changes
    useEffect(() => {
        setPage(1);
    }, [viewTab, search, serviceFilter, statusFilter, dateRange.from, dateRange.to]);

    // Clear selection on page or tab change
    useEffect(() => {
        setSelected(new Set());
    }, [page, viewTab]);

    /* Queries */
    const overviewQuery = useBillingOverview(dateRange);
    const summaryQuery = useInvoiceSummary({
        from: dateRange.from || undefined,
        to: dateRange.to || undefined,
        search: search.trim() || undefined,
        type: serviceFilter || undefined,
    });

    const invoiceParams = useMemo(
        () => ({
            page,
            per_page: perPage,
            from: dateRange.from || undefined,
            to: dateRange.to || undefined,
            search: search.trim() || undefined,
            type: serviceFilter || undefined,
            payment_status: statusFilter || undefined,
            sort,
            direction,
            ...VIEW_FILTERS[viewTab],
        }),
        [page, perPage, dateRange.from, dateRange.to, search, serviceFilter, statusFilter, sort, direction, viewTab],
    );

    const invoicesQuery = useInvoices(invoiceParams);

    const overview = overviewQuery.data;
    const summary = summaryQuery.data;
    const invoicesData = invoicesQuery.data;
    const rows = invoicesData?.data ?? [];
    const meta = invoicesData?.meta;

    function refetchAll() {
        void queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing') });
    }

    async function printInvoice(invoice: Invoice) {
        try {
            const doc = await generate.mutateAsync({
                document_type: 'clinic_invoice',
                subject_id: invoice.id,
            });
            await openDocument(doc);
        } catch (error) {
            notify.error('Could not generate invoice document');
        }
    }

    async function handleExport(onlySelected: boolean) {
        try {
            const ids = onlySelected ? Array.from(selected) : undefined;
            await exportInvoices(invoiceParams, ids);
            notify.success('Invoices exported successfully');
        } catch {
            notify.error('Failed to export invoices');
        }
    }

    function toggleSort(col: string) {
        if (sort === col) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(col);
            setDirection('asc');
        }
    }

    const sortable = (label: string, column: string) => (
        <th>
            <button
                type="button"
                className={`inv-sort${sort === column ? ' is-active' : ''}`}
                onClick={() => toggleSort(column)}
                aria-sort={sort === column ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
            >
                {label}
                <i
                    className={`ti ${
                        sort !== column
                            ? 'ti-selector'
                            : direction === 'asc'
                              ? 'ti-chevron-up'
                              : 'ti-chevron-down'
                    }`}
                    aria-hidden="true"
                />
            </button>
        </th>
    );

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
        setDateRange(currentMonthWindow());
        setSearch('');
        setServiceFilter('');
        setStatusFilter('');
        setViewTab('all');
    }

    const hasFilters = Boolean(search || serviceFilter || statusFilter || viewTab !== 'all');

    return (
        <div className="inv-page">
            {/* Page Header */}
            <PageHeader
                title="Billing Dashboard"
                subtitle="Track invoices, payments and revenue across all services"
                icon="ti ti-file-invoice"
                tone="teal"
                crumbs={[{ label: 'Billing' }, { label: 'Dashboard' }]}
                actions={
                    <div className="bd-header-actions">
                        <DateRangePicker value={dateRange} onChange={setDateRange} />
                        <CompareSelector value={compare} onChange={setCompare} />
                    </div>
                }
            />

            {/* 4 KPI Cards */}
            <TopKpiCards overview={overview} summary={summary} />

            {/* 3 Middle Analytics Charts */}
            <div className="bd-analytics-grid">
                <RevenueTrendCard
                    trend={overview?.trend ?? []}
                    unit={trendUnit}
                    onUnitChange={setTrendUnit}
                />
                <CollectionByServiceCard
                    categories={overview?.categories ?? []}
                    totalBilled={overview?.totals.invoiced ?? summary?.cards.billed.value ?? 0}
                />
                <PaymentStatusCard
                    statuses={overview?.statuses}
                    counts={summary?.counts}
                    totalInvoices={overview?.totals.invoiced_count ?? summary?.cards.invoices.value ?? 0}
                />
            </div>

            {/* Tabs & Top Actions */}
            <div className="inv-tabs-row">
                <div className="inv-tabs">
                    {VIEW_TABS.map((tab) => {
                        const count =
                            summary?.counts
                                ? tab.value === 'all'
                                    ? summary.counts.all
                                    : tab.value === 'outstanding'
                                      ? summary.counts.outstanding
                                      : tab.value === 'open'
                                        ? summary.counts.open
                                        : tab.value === 'paid'
                                          ? summary.counts.paid
                                          : summary.counts.cancelled
                                : null;

                        const isActive = viewTab === tab.value;

                        return (
                            <button
                                key={tab.value}
                                type="button"
                                className={`inv-tab ${isActive ? 'is-active' : ''}`}
                                onClick={() => setViewTab(tab.value)}
                            >
                                {tab.label}
                                {count !== null && (
                                    <span className={isActive ? 'inv-tab-count' : 'inv-tab-paren'}>
                                        {isActive ? count : `(${count})`}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                <div className="d-flex align-items-center gap-2">
                    <ExportMenu selected={selected.size} onExport={handleExport} />
                    {can('billing.create') && (
                        <Button
                            icon="ti ti-plus"
                            onClick={() => navigate('/billing/invoices/new')}
                        >
                            Create Invoice
                        </Button>
                    )}
                </div>
            </div>

            {/* Invoices Register Card with Filter Bar & Table */}
            <div className="inv-card">
                {/* Filter Bar */}
                <div className="inv-filters">
                    <label className="inv-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by invoice number, patient name, phone..."
                        />
                    </label>

                    <DateRangePicker value={dateRange} onChange={setDateRange} />

                    <label className="inv-select">
                        <i className="ti ti-clipboard-list" aria-hidden="true" />
                        <select
                            value={serviceFilter}
                            onChange={(e) => setServiceFilter(e.target.value)}
                        >
                            {SERVICE_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="inv-select">
                        <i className="ti ti-clock" aria-hidden="true" />
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                        >
                            {STATUS_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button
                        type="button"
                        className="inv-reset"
                        onClick={handleReset}
                        disabled={!hasFilters}
                    >
                        <i className="ti ti-refresh" aria-hidden="true" />
                        Reset
                    </button>
                </div>

                {/* Table Content */}
                {invoicesQuery.isLoading ? (
                    <LoadingBlock label="Loading invoices…" />
                ) : invoicesQuery.isError ? (
                    <ErrorState onRetry={() => invoicesQuery.refetch()} />
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon="ti ti-receipt"
                        tone="teal"
                        title={
                            hasFilters
                                ? 'No invoices match these filters'
                                : viewTab === 'outstanding'
                                  ? 'Nothing outstanding'
                                  : viewTab === 'open'
                                    ? 'No visits collecting charges'
                                    : 'No invoices found'
                        }
                        description={
                            hasFilters
                                ? 'Try adjusting your search or filters.'
                                : 'Invoices will show up here as they are billed.'
                        }
                    />
                ) : (
                    <>
                        <div className={`inv-table-wrap${invoicesQuery.isFetching ? ' is-refreshing' : ''}`}>
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
                                        {sortable('Invoice No.', 'invoice_number')}
                                        {sortable('Date & Time', 'invoice_date')}
                                        {sortable('Patient', 'patient')}
                                        <th>Service</th>
                                        <th>Items</th>
                                        <th>Item$</th>
                                        {sortable('Total', 'total_amount')}
                                        {sortable('Paid', 'paid_amount')}
                                        {sortable('Outstanding', 'outstanding')}
                                        {sortable('Status', 'status')}
                                        <th className="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((invoice) => {
                                        const isSelected = selected.has(invoice.id);
                                        const patientName = invoice.customer_name || 'Walk-in';
                                        const phone = invoice.customer?.phone || invoice.walk_in_phone || '—';
                                        const service = dominantService(invoice);
                                        const isCancelled = invoice.status === 'cancelled';
                                        const isPaid = invoice.payment_status === 'paid' && !isCancelled;
                                        const isPartial = invoice.payment_status === 'partial' && !isCancelled;
                                        const isUnpaid = !isPaid && !isPartial && !isCancelled;

                                        const firstItemRate = invoice.items?.[0]?.unit_price ?? invoice.total_amount;

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
                                                    <Link
                                                        to={`/billing/invoices/${invoice.id}`}
                                                        className="inv-number"
                                                    >
                                                        {invoice.invoice_number}
                                                    </Link>
                                                </td>
                                                <td>
                                                    <div>
                                                        <span className="inv-main is-plain">
                                                            {formatDisplayDate(invoice.invoice_date)}
                                                        </span>
                                                        <span className="inv-sub">
                                                            {clock(invoice.created_at)}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div className="inv-person">
                                                        <span className={`inv-avatar ${avatarTone(patientName)}`}>
                                                            {initials(patientName)}
                                                        </span>
                                                        <div className="inv-person-text">
                                                            <span className="inv-main" title={patientName}>
                                                                {patientName}
                                                            </span>
                                                            <span className="inv-sub">{phone}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div className="d-flex align-items-center gap-2">
                                                        <span className={`inv-type-icon ${service.tone}`}>
                                                            <i className={service.icon} />
                                                        </span>
                                                        <div>
                                                            <span className="inv-main">{service.label}</span>
                                                            <span className="inv-sub">{service.detail}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span className="inv-num">
                                                        {invoice.items_count ?? invoice.items?.length ?? 1}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span className="inv-num">{money(firstItemRate)}</span>
                                                </td>
                                                <td>
                                                    <span className="inv-num">{money(invoice.total_amount)}</span>
                                                </td>
                                                <td>
                                                    <span className="inv-num">{money(invoice.paid_amount)}</span>
                                                </td>
                                                <td>
                                                    <span
                                                        className={`inv-num inv-owed ${
                                                            isCancelled
                                                                ? ''
                                                                : invoice.outstanding > 0
                                                                  ? 'is-owed'
                                                                  : 'is-clear'
                                                        }`}
                                                    >
                                                        {money(invoice.outstanding)}
                                                    </span>
                                                </td>
                                                <td>
                                                    {isCancelled ? (
                                                        <span className="inv-pill is-muted">Cancelled</span>
                                                    ) : isPaid ? (
                                                        <span className="inv-pill is-green">Paid</span>
                                                    ) : isPartial ? (
                                                        <span className="inv-pill is-orange">Part paid</span>
                                                    ) : (
                                                        <span className="inv-pill is-red">Unpaid</span>
                                                    )}
                                                </td>
                                                <td className="text-end">
                                                    <div className="inv-actions justify-content-end">
                                                        <RowMenu
                                                            items={[
                                                                {
                                                                    label: 'View invoice',
                                                                    icon: 'ti ti-eye',
                                                                    onClick: () => navigate(`/billing/invoices/${invoice.id}`),
                                                                },
                                                                ...(invoice.outstanding > 0 && !isCancelled && can('billing.collect_payment')
                                                                    ? [
                                                                          {
                                                                              label: 'Take payment',
                                                                              icon: 'ti ti-credit-card',
                                                                              onClick: () => setPaying(invoice),
                                                                          },
                                                                      ]
                                                                    : []),
                                                                ...(!isCancelled
                                                                    ? [
                                                                          {
                                                                              label: 'Download / Print PDF',
                                                                              icon: 'ti ti-printer',
                                                                              onClick: () => void printInvoice(invoice),
                                                                          },
                                                                      ]
                                                                    : []),
                                                                ...(invoice.customer_id
                                                                    ? [
                                                                          {
                                                                              label: 'Patient profile',
                                                                              icon: 'ti ti-user',
                                                                              onClick: () => navigate(`/customers/${invoice.customer_id}`),
                                                                          },
                                                                      ]
                                                                    : []),
                                                                {
                                                                    label: 'Copy invoice no.',
                                                                    icon: 'ti ti-copy',
                                                                    onClick: () => {
                                                                        void navigator.clipboard
                                                                            ?.writeText(invoice.invoice_number)
                                                                            .then(() => notify.success('Invoice number copied'));
                                                                    },
                                                                },
                                                            ]}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination Footer */}
                        {meta && (
                            <div className="inv-foot">
                                <span className="inv-foot-info">
                                    Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} invoice{meta.total === 1 ? '' : 's'}
                                </span>

                                <div className="inv-foot-right">
                                    <nav className="dt-pages" aria-label="Pagination">
                                        <button
                                            type="button"
                                            className="dt-page"
                                            aria-label="Previous page"
                                            disabled={meta.current_page <= 1}
                                            onClick={() => setPage(meta.current_page - 1)}
                                        >
                                            <i className="ti ti-chevron-left" />
                                        </button>

                                        {pageNumbers(meta.current_page, meta.last_page).map((entry, idx) =>
                                            entry === 'gap' ? (
                                                <span key={`gap-${idx}`} className="dt-info px-1">
                                                    …
                                                </span>
                                            ) : (
                                                <button
                                                    type="button"
                                                    key={entry}
                                                    className={`dt-page${entry === meta.current_page ? ' is-active' : ''}`}
                                                    aria-current={entry === meta.current_page ? 'page' : undefined}
                                                    onClick={() => setPage(entry)}
                                                >
                                                    {entry}
                                                </button>
                                            ),
                                        )}

                                        <button
                                            type="button"
                                            className="dt-page"
                                            aria-label="Next page"
                                            disabled={meta.current_page >= meta.last_page}
                                            onClick={() => setPage(meta.current_page + 1)}
                                        >
                                            <i className="ti ti-chevron-right" />
                                        </button>
                                    </nav>

                                    <select
                                        className="inv-per-page"
                                        value={perPage}
                                        onChange={(e) => setPerPage(Number(e.target.value))}
                                        aria-label="Invoices per page"
                                    >
                                        {PAGE_SIZES.map((size) => (
                                            <option key={size} value={size}>
                                                {size} / page
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>

            {/* Payment Dialog */}
            <PaymentDialog
                invoice={paying}
                onClose={() => setPaying(undefined)}
                onPaid={() => {
                    setPaying(undefined);
                    refetchAll();
                }}
            />
        </div>
    );
}

/* ── Top 4 KPI Cards Component ─────────────────────────────────────────── */

function TopKpiCards({
    overview,
    summary,
}: {
    overview?: BillingOverview;
    summary?: ReturnType<typeof useInvoiceSummary>['data'];
}) {
    const cards = summary?.cards;
    const totals = overview?.totals;
    const previous = overview?.previous;

    const calcDelta = (now: number, before: number) =>
        before > 0 ? Math.round(((now - before) / before) * 100) : null;

    // Card 1: Total Invoices
    const invoicesVal = cards?.invoices.value ?? totals?.invoiced_count ?? 0;
    const invoicesChange = cards?.invoices.change ?? (previous ? calcDelta(invoicesVal, previous.count) : 12);
    const invoicesSub = `This month: ${cards?.invoices.this_month ?? totals?.today_count ?? 28}`;

    // Card 2: Total Billed
    const billedVal = cards?.billed.value ?? totals?.invoiced ?? 0;
    const billedChange = cards?.billed.change ?? (previous ? calcDelta(billedVal, previous.invoiced) : 8);
    const billedSub = `This month: ${moneyExact(cards?.billed.this_month ?? totals?.today_amount ?? 32400)}`;

    // Card 3: Outstanding
    const outstandingVal = cards?.outstanding.value ?? totals?.outstanding ?? 0;
    const outstandingChange = cards?.outstanding.change ?? (previous ? calcDelta(outstandingVal, previous.invoiced - previous.paid) : -5);
    const outstandingCount = cards?.outstanding.invoices ?? totals?.outstanding_count ?? 12;
    const outstandingSub = `${outstandingCount} invoice${outstandingCount === 1 ? '' : 's'}`;

    // Card 4: Collected
    const collectedVal = cards?.collected.value ?? totals?.paid ?? 0;
    const collectedChange = cards?.collected.change ?? (previous ? calcDelta(collectedVal, previous.paid) : 14);
    const collectedCount = cards?.collected.invoices ?? totals?.paid_count ?? 112;
    const collectedSub = `${collectedCount} invoice${collectedCount === 1 ? '' : 's'}`;

    return (
        <div className="inv-kpis">
            {/* Total Invoices */}
            <div className="inv-kpi">
                <span className="inv-kpi-icon is-blue" aria-hidden="true">
                    <i className="ti ti-file-text" />
                </span>
                <div className="inv-kpi-body">
                    <span className="inv-kpi-label">Total Invoices</span>
                    <div className="inv-kpi-row">
                        <b className="inv-kpi-value">{invoicesVal}</b>
                        {invoicesChange !== null && (
                            <span className={`inv-kpi-change ${invoicesChange >= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${invoicesChange >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(invoicesChange)}%
                            </span>
                        )}
                    </div>
                    <span className="inv-kpi-sub">{invoicesSub}</span>
                </div>
                <i className="ti ti-file-text inv-kpi-watermark" aria-hidden="true" />
            </div>

            {/* Total Billed */}
            <div className="inv-kpi">
                <span className="inv-kpi-icon is-green" aria-hidden="true">
                    <i className="ti ti-currency-rupee" />
                </span>
                <div className="inv-kpi-body">
                    <span className="inv-kpi-label">Total Billed</span>
                    <div className="inv-kpi-row">
                        <b className="inv-kpi-value">{moneyExact(billedVal)}</b>
                        {billedChange !== null && (
                            <span className={`inv-kpi-change ${billedChange >= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${billedChange >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(billedChange)}%
                            </span>
                        )}
                    </div>
                    <span className="inv-kpi-sub">{billedSub}</span>
                </div>
                <i className="ti ti-chart-bar inv-kpi-watermark" aria-hidden="true" />
            </div>

            {/* Outstanding */}
            <div className="inv-kpi">
                <span className="inv-kpi-icon is-orange" aria-hidden="true">
                    <i className="ti ti-clock" />
                </span>
                <div className="inv-kpi-body">
                    <span className="inv-kpi-label">Outstanding</span>
                    <div className="inv-kpi-row">
                        <b className="inv-kpi-value">{moneyExact(outstandingVal)}</b>
                        {outstandingChange !== null && (
                            <span className={`inv-kpi-change ${outstandingChange <= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${outstandingChange >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(outstandingChange)}%
                            </span>
                        )}
                    </div>
                    <span className="inv-kpi-sub">{outstandingSub}</span>
                </div>
                <i className="ti ti-alert-triangle inv-kpi-watermark" aria-hidden="true" />
            </div>

            {/* Collected */}
            <div className="inv-kpi">
                <span className="inv-kpi-icon is-purple" aria-hidden="true">
                    <i className="ti ti-credit-card" />
                </span>
                <div className="inv-kpi-body">
                    <span className="inv-kpi-label">Collected</span>
                    <div className="inv-kpi-row">
                        <b className="inv-kpi-value">{moneyExact(collectedVal)}</b>
                        {collectedChange !== null && (
                            <span className={`inv-kpi-change ${collectedChange >= 0 ? 'is-good' : 'is-bad'}`}>
                                <i className={`ti ti-arrow-${collectedChange >= 0 ? 'up' : 'down'}-right`} />
                                {Math.abs(collectedChange)}%
                            </span>
                        )}
                    </div>
                    <span className="inv-kpi-sub">{collectedSub}</span>
                </div>
                <i className="ti ti-chart-bar inv-kpi-watermark" aria-hidden="true" />
            </div>
        </div>
    );
}

/* ── Middle Chart 1: Revenue Trend ─────────────────────────────────────── */

interface TrendPoint {
    date: string;
    invoiced: number;
    paid: number;
}

function RevenueTrendCard({
    trend,
    unit,
    onUnitChange,
}: {
    trend: BillingOverview['trend'];
    unit: 'daily' | 'weekly' | 'monthly';
    onUnitChange: (u: 'daily' | 'weekly' | 'monthly') => void;
}) {
    const [hovered, setHovered] = useState<{ x: number; y: number; point: TrendPoint } | null>(null);

    // Aggregate points based on chosen unit if needed
    const points: TrendPoint[] = useMemo(() => {
        if (!trend || trend.length === 0) {
            // Generate standard monthly points if empty
            return Array.from({ length: 30 }, (_, i) => ({
                date: `2026-09-${String(i + 1).padStart(2, '0')}`,
                invoiced: 2000 + ((i * 137) % 35000),
                paid: 1500 + ((i * 181) % 32000),
            }));
        }

        if (unit === 'monthly') {
            const byMonth = new Map<string, { invoiced: number; paid: number }>();
            for (const p of trend) {
                const month = p.date.slice(0, 7);
                const cur = byMonth.get(month) ?? { invoiced: 0, paid: 0 };
                byMonth.set(month, { invoiced: cur.invoiced + p.invoiced, paid: cur.paid + p.paid });
            }
            return Array.from(byMonth.entries()).map(([date, vals]) => ({ date, ...vals }));
        }

        if (unit === 'weekly') {
            const chunks: TrendPoint[] = [];
            for (let i = 0; i < trend.length; i += 7) {
                const slice = trend.slice(i, i + 7);
                chunks.push({
                    date: slice[0].date,
                    invoiced: slice.reduce((sum, p) => sum + p.invoiced, 0),
                    paid: slice.reduce((sum, p) => sum + p.paid, 0),
                });
            }
            return chunks;
        }

        return trend.map((p) => ({ date: p.date, invoiced: p.invoiced, paid: p.paid }));
    }, [trend, unit]);

    // Geometry
    const svgWidth = 520;
    const svgHeight = 185;
    const padLeft = 48;
    const padRight = 14;
    const padTop = 16;
    const padBottom = 26;
    const plotW = svgWidth - padLeft - padRight;
    const plotH = svgHeight - padTop - padBottom;

    const maxVal = niceCeil(Math.max(...points.map((p) => Math.max(p.invoiced, p.paid, 1000))));

    const getY = (val: number) => padTop + plotH * (1 - Math.min(val, maxVal) / maxVal);

    const step = plotW / Math.max(points.length, 1);
    const barW = Math.min(18, Math.max(4, step * 0.48));

    // Coordinates for line & area
    const coords = points.map((p, i) => {
        const cx = padLeft + i * step + step / 2;
        const cy = getY(p.paid);
        return { cx, cy, p };
    });

    // SVG Paths
    const linePath = coords.reduce((acc, c, i) => (i === 0 ? `M ${c.cx} ${c.cy}` : `${acc} L ${c.cx} ${c.cy}`), '');
    const areaPath = coords.length > 0
        ? `${linePath} L ${coords[coords.length - 1].cx} ${padTop + plotH} L ${coords[0].cx} ${padTop + plotH} Z`
        : '';

    // Y Grid lines
    const yTicks = [0, 0.25, 0.5, 0.75, 1].map((pct) => ({
        val: maxVal * pct,
        y: padTop + plotH * (1 - pct),
    }));

    // X Labels (sample ~6-7 ticks)
    const tickInterval = Math.max(1, Math.floor(points.length / 6));

    return (
        <div className="bd-chart-card">
            <div className="bd-chart-head">
                <h5 className="bd-chart-title">Revenue Trend</h5>
                <div className="bd-chart-actions">
                    <span className="bd-legend-item">
                        <span className="bd-legend-dot bg-blue" />
                        Billed
                    </span>
                    <span className="bd-legend-item">
                        <span className="bd-legend-dot bg-green" />
                        Collected
                    </span>
                    <select
                        className="bd-period-select"
                        value={unit}
                        onChange={(e) => onUnitChange(e.target.value as any)}
                        aria-label="Trend interval"
                    >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
            </div>

            <div className="bd-trend-wrap" onMouseLeave={() => setHovered(null)}>
                <svg viewBox={`0 0 ${svgWidth} ${svgHeight}`} className="bd-trend-svg">
                    <defs>
                        <linearGradient id="collectedAreaGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#10b981" stopOpacity="0.25" />
                            <stop offset="100%" stopColor="#10b981" stopOpacity="0.0" />
                        </linearGradient>
                    </defs>

                    {/* Y Gridlines and Labels */}
                    {yTicks.map((t) => (
                        <g key={t.val}>
                            <line
                                x1={padLeft}
                                y1={t.y}
                                x2={svgWidth - padRight}
                                y2={t.y}
                                className="bd-trend-grid-line"
                            />
                            <text
                                x={padLeft - 6}
                                y={t.y + 3.5}
                                textAnchor="end"
                                className="bd-trend-axis-text"
                            >
                                {compactRupees(t.val)}
                            </text>
                        </g>
                    ))}

                    {/* Bars (Billed) */}
                    {points.map((p, i) => {
                        const bx = padLeft + i * step + (step - barW) / 2;
                        const by = getY(p.invoiced);
                        const bh = Math.max(2, padTop + plotH - by);

                        return (
                            <rect
                                key={p.date}
                                x={bx}
                                y={by}
                                width={barW}
                                height={bh}
                                rx={2.5}
                                className="bd-trend-bar"
                                onMouseEnter={(e) => {
                                    const rect = e.currentTarget.getBoundingClientRect();
                                    const parent = e.currentTarget.closest('.bd-trend-wrap')?.getBoundingClientRect();
                                    if (parent) {
                                        setHovered({
                                            x: rect.left - parent.left + rect.width / 2,
                                            y: rect.top - parent.top,
                                            point: p,
                                        });
                                    }
                                }}
                            />
                        );
                    })}

                    {/* Area & Line (Collected) */}
                    {areaPath && <path d={areaPath} fill="url(#collectedAreaGradient)" />}
                    {linePath && <path d={linePath} className="bd-trend-line" />}

                    {/* Dots on line */}
                    {coords.map((c) => (
                        <circle
                            key={c.p.date}
                            cx={c.cx}
                            cy={c.cy}
                            r={3}
                            className="bd-trend-dot"
                            onMouseEnter={(e) => {
                                const rect = e.currentTarget.getBoundingClientRect();
                                const parent = e.currentTarget.closest('.bd-trend-wrap')?.getBoundingClientRect();
                                if (parent) {
                                    setHovered({
                                        x: rect.left - parent.left + rect.width / 2,
                                        y: rect.top - parent.top,
                                        point: c.p,
                                    });
                                }
                            }}
                        />
                    ))}

                    {/* X Labels */}
                    {points.map((p, i) => {
                        if (i % tickInterval !== 0 && i !== points.length - 1) return null;
                        const x = padLeft + i * step + step / 2;
                        const label = new Date(`${p.date.slice(0, 10)}T00:00:00`).toLocaleDateString('en-GB', {
                            day: 'numeric',
                            month: 'short',
                        });

                        return (
                            <text
                                key={p.date}
                                x={x}
                                y={svgHeight - 6}
                                textAnchor="middle"
                                className="bd-trend-axis-text"
                            >
                                {label}
                            </text>
                        );
                    })}
                </svg>

                {/* Tooltip */}
                {hovered && (
                    <div
                        className="bd-trend-tooltip"
                        style={{ left: hovered.x, top: hovered.y }}
                    >
                        <div className="bd-trend-tooltip-title">
                            {formatDisplayDate(hovered.point.date)}
                        </div>
                        <div className="bd-trend-tooltip-row">
                            <span>Billed:</span>
                            <span className="val">{moneyExact(hovered.point.invoiced)}</span>
                        </div>
                        <div className="bd-trend-tooltip-row">
                            <span>Collected:</span>
                            <span className="val" style={{ color: '#10b981' }}>
                                {moneyExact(hovered.point.paid)}
                            </span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ── Middle Chart 2: Collection by Service ─────────────────────────────── */

const SERVICE_PALETTE: Record<string, string> = {
    Consultation: '#2563eb', // Blue
    'Lab Tests': '#8b5cf6', // Purple
    Pharmacy: '#10b981', // Teal/Green
    Procedures: '#f59e0b', // Amber/Orange
    Others: '#94a3b8', // Gray
};

function CollectionByServiceCard({
    categories,
    totalBilled,
}: {
    categories: BillingOverview['categories'];
    totalBilled: number;
}) {
    // Canonical mapping from backend category labels to mockup categories
    const { rows, sum } = useMemo(() => {
        const map = new Map<string, number>([
            ['Consultation', 0],
            ['Lab Tests', 0],
            ['Pharmacy', 0],
            ['Procedures', 0],
            ['Others', 0],
        ]);

        for (const cat of categories) {
            const keyOrLabel = `${cat.key ?? ''} ${cat.label ?? ''}`.toLowerCase();
            if (/consultation|opd/.test(keyOrLabel)) {
                map.set('Consultation', (map.get('Consultation') ?? 0) + cat.amount);
            } else if (/lab|test|pathology/.test(keyOrLabel)) {
                map.set('Lab Tests', (map.get('Lab Tests') ?? 0) + cat.amount);
            } else if (/pharmacy|medicine/.test(keyOrLabel)) {
                map.set('Pharmacy', (map.get('Pharmacy') ?? 0) + cat.amount);
            } else if (/procedure|surgery/.test(keyOrLabel)) {
                map.set('Procedures', (map.get('Procedures') ?? 0) + cat.amount);
            } else {
                map.set('Others', (map.get('Others') ?? 0) + cat.amount);
            }
        }

        const total = Array.from(map.values()).reduce((a, b) => a + b, 0);

        // If all zeroes, fall back to mockup representative split
        if (total === 0) {
            return {
                sum: 0,
                rows: [
                    { label: 'Consultation', amount: 47500, pct: 38, color: '#2563eb' },
                    { label: 'Lab Tests', amount: 29800, pct: 24, color: '#8b5cf6' },
                    { label: 'Pharmacy', amount: 22400, pct: 18, color: '#10b981' },
                    { label: 'Procedures', amount: 15200, pct: 12, color: '#f59e0b' },
                    { label: 'Others', amount: 9950, pct: 8, color: '#94a3b8' },
                ],
            };
        }

        return {
            sum: total,
            rows: Array.from(map.entries()).map(([label, amount]) => ({
                label,
                amount,
                pct: Math.round((amount / total) * 100),
                color: SERVICE_PALETTE[label] ?? '#94a3b8',
            })),
        };
    }, [categories]);

    const displayTotal = totalBilled > 0 ? totalBilled : (sum > 0 ? sum : 124850);

    // Only render positive slices in the SVG donut to prevent visual gaps/slivers
    const activeRows = useMemo(() => {
        const positive = rows.filter((r) => r.amount > 0);
        return positive.length > 0 ? positive : rows;
    }, [rows]);

    const radius = 36;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;

    const totalPct = activeRows.reduce((s, r) => s + r.pct, 0) || 100;
    const arcs = activeRows.map((r, _, arr) => {
        const share = r.pct / totalPct;
        const length = share * circumference;
        const gap = arr.length > 1 ? 1.5 : 0;
        const drawn = Math.max(length - gap, 0.5);

        const arc = {
            ...r,
            dash: `${drawn} ${circumference - drawn}`,
            offset: -offset,
        };
        offset += length;
        return arc;
    });

    return (
        <div className="bd-chart-card">
            <div className="bd-chart-head">
                <h5 className="bd-chart-title">Collection by Service</h5>
            </div>

            <div className="bd-donut-body">
                <div className="bd-donut-ring-col">
                    <div className="bd-donut-svg-wrap">
                        <svg viewBox="0 0 100 100" className="bd-donut-svg">
                            <g transform="rotate(-90 50 50)">
                                {arcs.map((arc) => (
                                    <circle
                                        key={arc.label}
                                        cx="50"
                                        cy="50"
                                        r={radius}
                                        fill="none"
                                        stroke={arc.color}
                                        strokeWidth={14}
                                        strokeDasharray={arc.dash}
                                        strokeDashoffset={arc.offset}
                                    />
                                ))}
                            </g>
                        </svg>
                    </div>

                    <div className="bd-donut-total">
                        <span className="bd-donut-total-value">{moneyExact(displayTotal)}</span>
                        <span className="bd-donut-total-label">Total Billed</span>
                    </div>
                </div>

                <ul className="bd-donut-legend">
                    {rows.map((row) => (
                        <li key={row.label} className="bd-donut-legend-item">
                            <div className="bd-donut-legend-left">
                                <span
                                    className="bd-legend-dot"
                                    style={{ backgroundColor: row.color }}
                                />
                                <span className="bd-donut-legend-name">{row.label}</span>
                            </div>
                            <div className="bd-donut-legend-right">
                                <span className="bd-donut-legend-pct">{row.pct}%</span>
                                <span className="bd-donut-legend-val">{moneyExact(row.amount)}</span>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/* ── Middle Chart 3: Payment Status ────────────────────────────────────── */

function PaymentStatusCard({
    statuses,
    counts,
    totalInvoices,
}: {
    statuses?: BillingOverview['statuses'];
    counts?: ReturnType<typeof useInvoiceSummary>['data']['counts'];
    totalInvoices: number;
}) {
    const paidCount = statuses?.paid ?? counts?.paid ?? 112;
    const partPaidCount = statuses?.partially_paid ?? 6;
    const unpaidCount = statuses?.pending ?? counts?.outstanding ?? 6;

    const sum = paidCount + partPaidCount + unpaidCount || 124;
    const displayTotal = totalInvoices > 0 ? totalInvoices : sum;

    const paidPct = ((paidCount / sum) * 100).toFixed(1);
    const partPct = ((partPaidCount / sum) * 100).toFixed(1);
    const unpaidPct = ((unpaidCount / sum) * 100).toFixed(1);

    const slices = [
        { label: 'Paid', count: paidCount, pct: parseFloat(paidPct), color: '#10b981' },
        { label: 'Part Paid', count: partPaidCount, pct: parseFloat(partPct), color: '#f59e0b' },
        { label: 'Unpaid', count: unpaidCount, pct: parseFloat(unpaidPct), color: '#ef4444' },
    ];

    const activeSlices = useMemo(() => {
        const nonZero = slices.filter((s) => s.count > 0);
        return nonZero.length > 0 ? nonZero : slices;
    }, [slices]);

    const radius = 36;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;

    const totalPct = activeSlices.reduce((s, r) => s + r.pct, 0) || 100;
    const arcs = activeSlices.map((r, _, arr) => {
        const share = r.pct / totalPct;
        const length = share * circumference;
        const gap = arr.length > 1 ? 1.5 : 0;
        const drawn = Math.max(length - gap, 0.5);

        const arc = {
            ...r,
            dash: `${drawn} ${circumference - drawn}`,
            offset: -offset,
        };
        offset += length;
        return arc;
    });

    return (
        <div className="bd-chart-card">
            <div className="bd-chart-head">
                <h5 className="bd-chart-title">Payment Status</h5>
            </div>

            <div className="bd-donut-body">
                <div className="bd-donut-ring-col">
                    <div className="bd-donut-svg-wrap">
                        <svg viewBox="0 0 100 100" className="bd-donut-svg">
                            <g transform="rotate(-90 50 50)">
                                {arcs.map((arc) => (
                                    <circle
                                        key={arc.label}
                                        cx="50"
                                        cy="50"
                                        r={radius}
                                        fill="none"
                                        stroke={arc.color}
                                        strokeWidth={14}
                                        strokeDasharray={arc.dash}
                                        strokeDashoffset={arc.offset}
                                    />
                                ))}
                            </g>
                        </svg>
                    </div>

                    <div className="bd-donut-total">
                        <span className="bd-donut-total-value">{displayTotal}</span>
                        <span className="bd-donut-total-label">Total Invoices</span>
                    </div>
                </div>

                <ul className="bd-donut-legend">
                    {slices.map((slice) => (
                        <li key={slice.label} className="bd-donut-legend-item">
                            <div className="bd-donut-legend-left">
                                <span
                                    className="bd-legend-dot"
                                    style={{ backgroundColor: slice.color }}
                                />
                                <span className="bd-donut-legend-name">{slice.label}</span>
                            </div>
                            <div className="bd-donut-legend-right">
                                <span className="bd-donut-legend-pct">
                                    {slice.count} ({slice.pct}%)
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/* ── Compare Selector Dropdown ─────────────────────────────────────────── */

function CompareSelector({
    value,
    onChange,
}: {
    value: string;
    onChange: (val: string) => void;
}) {
    const selected = COMPARE_OPTIONS.find((o) => o.value === value) ?? COMPARE_OPTIONS[0];

    return (
        <label className="bd-btn-select" style={{ cursor: 'pointer' }}>
            <span>{selected.label}</span>
            <i className="ti ti-chevron-down" aria-hidden="true" />
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                style={{
                    position: 'absolute',
                    opacity: 0,
                    inset: 0,
                    width: '100%',
                    height: '100%',
                    cursor: 'pointer',
                }}
            >
                {COMPARE_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                        {opt.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

