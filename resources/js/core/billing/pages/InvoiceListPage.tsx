import { useEffect, useMemo, useRef, useState, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    exportInvoices,
    useFinalizeInvoice,
    useInvoiceSummary,
    useInvoices,
    type Invoice,
    type InvoiceCard,
    type InvoiceItem,
} from '../api';
import { PaymentDialog } from '../components/PaymentDialog';

/**
 * What the billing counter works from.
 *
 * A COLLECTION screen, not a creation one. Invoices are produced by the
 * clinic's own events — a consultation finishing, a lab signing off, the
 * counter dispensing — and consolidated onto one bill per visit by the
 * server. Nobody at this desk types a consultation line by hand.
 *
 * The tabs are the questions somebody at the till actually asks:
 *
 *   OUTSTANDING  finalized bills with money left on them — the work queue
 *   OPEN         visits still collecting charges, so the desk can see who is
 *                mid-visit and close a bill when the patient is ready to go
 *   ALL          the register
 *   PAID / CANCELLED  the two ends a bill can reach
 */
type View = 'outstanding' | 'open' | 'all' | 'paid' | 'cancelled';

const VIEWS: { value: View; label: string }[] = [
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'open', label: 'Open visits' },
    { value: 'all', label: 'All Invoices' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

function isView(value: string | null): value is View {
    return VIEWS.some((entry) => entry.value === value);
}

/** What each tab asks the list for. */
const VIEW_FILTERS: Record<View, Record<string, string | number>> = {
    outstanding: { outstanding_only: 1 },
    open: { include_drafts: 1, status: 'draft' },
    all: { include_drafts: 1 },
    paid: { status: 'paid' },
    cancelled: { status: 'cancelled' },
};

const TYPES = [
    { value: '', label: 'All Types' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'lab_test', label: 'Lab Test' },
    { value: 'pharmacy', label: 'Pharmacy' },
    { value: 'procedure', label: 'Procedure' },
    { value: 'other', label: 'Other services' },
];

const STATUSES = [
    { value: '', label: 'All Status' },
    { value: 'unpaid', label: 'Unpaid' },
    { value: 'partial', label: 'Part paid' },
    { value: 'paid', label: 'Paid' },
];

const PAGE_SIZES = [10, 25, 50];

/** Line source → how the Visit Type column names it. */
const VISIT_TYPES: Record<string, { label: string; icon: string; tone: string }> = {
    consultation: { label: 'Consultation', icon: 'ti ti-stethoscope', tone: 'is-blue' },
    lab_test: { label: 'Lab Test', icon: 'ti ti-flask', tone: 'is-purple' },
    pharmacy_sale_item: { label: 'Pharmacy', icon: 'ti ti-pill', tone: 'is-green' },
    procedure: { label: 'Procedure', icon: 'ti ti-scissors', tone: 'is-orange' },
    service: { label: 'Service', icon: 'ti ti-layout-grid', tone: 'is-muted' },
    custom: { label: 'Service', icon: 'ti ti-layout-grid', tone: 'is-muted' },
};

const AVATAR_TONES = ['is-blue', 'is-orange', 'is-pink', 'is-green', 'is-purple', 'is-teal'];

const money = (value: number) =>
    `₹${value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const rupees = (value: number) => `₹${value.toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;

function localIso(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const longDate = (iso: string) =>
    new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

const clock = (iso: string | null) =>
    iso
        ? new Date(iso)
              .toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true })
              .toUpperCase()
        : '';

/** "Asha Verma" → "AV"; one word gives its first two letters. */
function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();

    return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}

/** The same patient gets the same colour every time. */
function avatarTone(name: string): string {
    let hash = 0;

    for (const letter of name) {
        hash = (hash * 31 + letter.charCodeAt(0)) >>> 0;
    }

    return AVATAR_TONES[hash % AVATAR_TONES.length];
}

/**
 * What the bill mostly is — the line source carrying the most money — and a
 * word about it: the test's name, the doctor, "Medicines".
 */
function visitType(invoice: Invoice): { label: string; icon: string; tone: string; detail: string } {
    if (invoice.kind === 'registration') {
        return { label: 'Registration', icon: 'ti ti-id-badge-2', tone: 'is-teal', detail: 'Patient registration' };
    }

    const items: InvoiceItem[] = invoice.items ?? [];

    if (items.length === 0) {
        return { ...VISIT_TYPES.custom, detail: '—' };
    }

    const totals = new Map<string, number>();

    for (const item of items) {
        totals.set(item.source_type, (totals.get(item.source_type) ?? 0) + item.line_total);
    }

    const [dominant] = [...totals.entries()].sort((a, b) => b[1] - a[1])[0];
    const meta = VISIT_TYPES[dominant] ?? VISIT_TYPES.custom;

    if (dominant === 'pharmacy_sale_item') {
        return { ...meta, detail: 'Medicines' };
    }

    const first = items.find((item) => item.source_type === dominant)?.description ?? '';
    // "Consultation — General Physician" reads as its second half.
    const detail = first.split(/\s[—-]\s/).pop() ?? first;

    return { ...meta, detail: detail || invoice.doctor_name || '—' };
}

function statusPill(invoice: Invoice): { label: string; tone: string } {
    if (invoice.is_draft) return { label: 'Collecting charges', tone: 'is-amber' };
    if (invoice.status === 'cancelled') return { label: 'Cancelled', tone: 'is-muted' };
    if (invoice.status === 'refunded') return { label: 'Refunded', tone: 'is-muted' };
    if (invoice.payment_status === 'paid') return { label: 'Paid', tone: 'is-green' };
    if (invoice.payment_status === 'partial') return { label: 'Part paid', tone: 'is-orange' };

    return { label: 'Unpaid', tone: 'is-red' };
}

/** A compact page list: 1 … 4 5 6 … 12 */
function pageNumbers(current: number, total: number): (number | 'gap')[] {
    if (total <= 7) return Array.from({ length: total }, (_, index) => index + 1);

    const pages = [...new Set([1, total, current - 1, current, current + 1])]
        .filter((page) => page >= 1 && page <= total)
        .sort((a, b) => a - b);

    const out: (number | 'gap')[] = [];

    pages.forEach((page, index) => {
        if (index > 0 && page - pages[index - 1] > 1) out.push('gap');
        out.push(page);
    });

    return out;
}

type SortKey = 'invoice_date' | 'patient' | 'total_amount' | 'paid_amount' | 'outstanding' | 'status';

export default function InvoiceListPage() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();
    const canCreate = can('billing.create');
    const canCollect = can('billing.collect_payment');
    const canPrint = can('documents.generate');

    const [params, setParams] = useSearchParams();
    const asked = params.get('view');
    const view: View = isView(asked) ? asked : 'outstanding';

    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [range, setRange] = useState<{ from: string; to: string } | null>(null);
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [sort, setSort] = useState<{ key: SortKey; direction: 'asc' | 'desc' }>({
        key: 'invoice_date',
        direction: 'desc',
    });
    const [selected, setSelected] = useState<Set<number>>(new Set());

    const [paying, setPaying] = useState<Invoice>();
    const [error, setError] = useState<string | null>(null);

    // Typing settles before it searches — one request, not one per key.
    useEffect(() => {
        const timer = window.setTimeout(() => setSearch(searchInput.trim()), 300);

        return () => window.clearTimeout(timer);
    }, [searchInput]);

    /* The filters every count and the list share — not the tab, not the status. */
    const shared = useMemo(
        () => ({
            ...(search ? { search } : {}),
            ...(range ? { from: range.from, to: range.to } : {}),
            ...(type ? { type } : {}),
        }),
        [search, range, type],
    );

    const listParams = useMemo(
        () => ({
            ...shared,
            ...VIEW_FILTERS[view],
            ...(status ? { payment_status: status } : {}),
            page,
            per_page: perPage,
            sort: sort.key,
            direction: sort.direction,
        }),
        [shared, view, status, page, perPage, sort],
    );

    // A different question starts from its first page, with nothing ticked.
    useEffect(() => {
        setPage(1);
        setSelected(new Set());
    }, [shared, view, status, perPage, sort]);

    const { data: list, isLoading, isFetching, isError, refetch } = useInvoices(listParams);
    const { data: summary } = useInvoiceSummary(shared);

    const finalize = useFinalizeInvoice();
    const generate = useGenerateDocument();

    const rows = list?.data ?? [];
    const meta = list?.meta;

    function show(next: View) {
        const updated = new URLSearchParams(params);

        if (next === 'outstanding') updated.delete('view');
        else updated.set('view', next);

        setParams(updated, { replace: true });
    }

    function reset() {
        setSearchInput('');
        setSearch('');
        setRange(null);
        setType('');
        setStatus('');
    }

    function sortBy(key: SortKey) {
        setSort((current) =>
            current.key === key
                ? { key, direction: current.direction === 'asc' ? 'desc' : 'asc' }
                : { key, direction: key === 'patient' ? 'asc' : 'desc' },
        );
    }

    async function closeBill(invoice: Invoice) {
        setError(null);

        try {
            // Straight into taking the money — the patient is at the desk.
            setPaying(await finalize.mutateAsync(invoice.id));
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    async function pdf(invoice: Invoice, download: boolean) {
        setError(null);

        try {
            const document_ = await generate.mutateAsync({ document_type: 'clinic_invoice', subject_id: invoice.id });
            await openDocument(document_, download);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    async function runExport(onlySelected: boolean) {
        try {
            await exportInvoices(
                { ...shared, ...VIEW_FILTERS[view], ...(status ? { payment_status: status } : {}) },
                onlySelected ? [...selected] : undefined,
            );
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        }
    }

    const allTicked = rows.length > 0 && rows.every((row) => selected.has(row.id));

    function tickAll() {
        setSelected((current) => {
            const next = new Set(current);

            if (allTicked) rows.forEach((row) => next.delete(row.id));
            else rows.forEach((row) => next.add(row.id));

            return next;
        });
    }

    function tick(id: number) {
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) next.delete(id);
            else next.add(id);

            return next;
        });
    }

    const filtered = Boolean(searchInput || range || type || status);

    const sortable = (label: string, column: SortKey) => (
        <th>
            <button
                type="button"
                className={`inv-sort${sort.key === column ? ' is-active' : ''}`}
                onClick={() => sortBy(column)}
                aria-sort={sort.key === column ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
            >
                {label}
                <i
                    className={`ti ${
                        sort.key !== column
                            ? 'ti-selector'
                            : sort.direction === 'asc'
                              ? 'ti-chevron-up'
                              : 'ti-chevron-down'
                    }`}
                    aria-hidden="true"
                />
            </button>
        </th>
    );

    return (
        <div className="inv-page">
            <PageHeader
                title="Invoices"
                subtitle="Bills raised by the clinic's own work — consultations, tests, procedures and medicines on one bill per visit."
                icon="ti ti-file-text"
                tone="teal"
                crumbs={[{ label: 'Billing', to: '/billing' }, { label: 'Invoices' }]}
                actions={
                    canCreate ? (
                        <Button icon="ti ti-plus" onClick={() => navigate('/billing/invoices/new')}>
                            Create Invoice
                        </Button>
                    ) : undefined
                }
            />

            {/* ── The four cards ─────────────────────────────────── */}
            <div className="inv-kpis">
                <KpiCard
                    label="Total Invoices"
                    icon="ti ti-file-text"
                    tone="is-blue"
                    value={summary ? summary.cards.invoices.value.toLocaleString('en-IN') : '—'}
                    card={summary?.cards.invoices}
                    sub={summary ? `This month: ${summary.cards.invoices.this_month ?? 0}` : ''}
                />
                <KpiCard
                    label="Total Billed"
                    icon="ti ti-currency-rupee"
                    tone="is-green"
                    value={summary ? rupees(summary.cards.billed.value) : '—'}
                    card={summary?.cards.billed}
                    sub={summary ? `This month: ${rupees(summary.cards.billed.this_month ?? 0)}` : ''}
                />
                <KpiCard
                    label="Outstanding"
                    icon="ti ti-clock"
                    tone="is-orange"
                    value={summary ? rupees(summary.cards.outstanding.value) : '—'}
                    card={summary?.cards.outstanding}
                    riseIsBad
                    sub={summary ? `${summary.cards.outstanding.invoices ?? 0} invoices` : ''}
                />
                <KpiCard
                    label="Collected"
                    icon="ti ti-credit-card"
                    tone="is-purple"
                    value={summary ? rupees(summary.cards.collected.value) : '—'}
                    card={summary?.cards.collected}
                    sub={summary ? `${summary.cards.collected.invoices ?? 0} invoices` : ''}
                />
            </div>

            {/* ── Tabs, and the export beside them ───────────────── */}
            <div className="inv-tabs-row">
                <div className="inv-tabs" role="tablist" aria-label="Which bills to show">
                    {VIEWS.map((entry) => {
                        const active = entry.value === view;
                        const count = summary?.counts[entry.value];

                        return (
                            <button
                                key={entry.value}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                className={`inv-tab${active ? ' is-active' : ''}`}
                                onClick={() => show(entry.value)}
                            >
                                {entry.label}
                                {count !== undefined &&
                                    (active ? (
                                        <span className="inv-tab-count">{count}</span>
                                    ) : (
                                        <span className="inv-tab-paren">({count})</span>
                                    ))}
                            </button>
                        );
                    })}
                </div>

                <ExportMenu selected={selected.size} onExport={(onlySelected) => void runExport(onlySelected)} />
            </div>

            {error && <div className="alert alert-danger py-2 mb-0">{error}</div>}

            {/* ── The list ───────────────────────────────────────── */}
            <div className="inv-card">
                <div className="inv-filters">
                    <label className="inv-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            placeholder="Search by invoice number, patient name, phone..."
                            value={searchInput}
                            onChange={(event) => setSearchInput(event.target.value)}
                            aria-label="Search invoices"
                        />
                    </label>

                    <DateRangeField value={range} onChange={setRange} />

                    <label className="inv-select">
                        <i className="ti ti-file-description" aria-hidden="true" />
                        <select value={type} onChange={(event) => setType(event.target.value)} aria-label="Type">
                            {TYPES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="inv-select">
                        <i className="ti ti-clock" aria-hidden="true" />
                        <select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Status">
                            {STATUSES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button type="button" className="inv-reset" onClick={reset} disabled={!filtered}>
                        <i className="ti ti-refresh" aria-hidden="true" />
                        Reset
                    </button>
                </div>

                {isLoading ? (
                    <LoadingBlock label="Loading invoices…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon="ti ti-receipt"
                        tone="teal"
                        title={
                            filtered
                                ? 'No invoices match these filters'
                                : view === 'outstanding'
                                  ? 'Nothing outstanding'
                                  : view === 'open'
                                    ? 'No visits collecting charges'
                                    : 'No invoices here'
                        }
                        description={
                            filtered
                                ? 'Clear a filter or pick a wider date range.'
                                : view === 'outstanding'
                                  ? 'Every bill raised so far has been settled.'
                                  : view === 'open'
                                    ? 'A bill appears here while a visit is still producing charges.'
                                    : 'Invoices appear here as the clinic bills its work.'
                        }
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
                                                checked={allTicked}
                                                onChange={tickAll}
                                                aria-label="Select every invoice on this page"
                                            />
                                        </th>
                                        <th>Invoice No.</th>
                                        {sortable('Date', 'invoice_date')}
                                        {sortable('Patient', 'patient')}
                                        <th>Visit Type</th>
                                        <th>Items</th>
                                        {sortable('Total', 'total_amount')}
                                        {sortable('Paid', 'paid_amount')}
                                        {sortable('Outstanding', 'outstanding')}
                                        {sortable('Status', 'status')}
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((invoice) => {
                                        const name = invoice.customer_name ?? 'Walk-in';
                                        const phone = invoice.customer_phone ?? invoice.walk_in_phone;
                                        const kind = visitType(invoice);
                                        const pill = statusPill(invoice);
                                        const cancelled = invoice.status === 'cancelled';
                                        const count = invoice.items_count ?? invoice.items?.length ?? 0;

                                        return (
                                            <tr key={invoice.id} className={selected.has(invoice.id) ? 'is-selected' : undefined}>
                                                <td className="inv-check-col">
                                                    <input
                                                        type="checkbox"
                                                        className="form-check-input"
                                                        checked={selected.has(invoice.id)}
                                                        onChange={() => tick(invoice.id)}
                                                        aria-label={`Select ${invoice.invoice_number}`}
                                                    />
                                                </td>
                                                <td>
                                                    <Link to={`/billing/invoices/${invoice.id}`} className="inv-number">
                                                        {invoice.invoice_number}
                                                    </Link>
                                                    {invoice.kind !== 'visit' && (
                                                        <span className="inv-kind">
                                                            {invoice.kind === 'registration' ? 'Registration' : 'Manual'}
                                                        </span>
                                                    )}
                                                </td>
                                                <td>
                                                    <span className="inv-main is-plain">{longDate(invoice.invoice_date)}</span>
                                                    <span className="inv-sub">{clock(invoice.created_at)}</span>
                                                </td>
                                                <td>
                                                    <div className="inv-person">
                                                        <span className={`inv-avatar ${avatarTone(name)}`} aria-hidden="true">
                                                            {initials(name)}
                                                        </span>
                                                        <div className="inv-person-text">
                                                            <span className="inv-main" title={name}>
                                                                {name}
                                                            </span>
                                                            {phone && <span className="inv-sub">{phone}</span>}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div className="inv-person">
                                                        <span className={`inv-type-icon ${kind.tone}`} aria-hidden="true">
                                                            <i className={kind.icon} />
                                                        </span>
                                                        <div className="inv-person-text">
                                                            <span className="inv-main">{kind.label}</span>
                                                            <span className="inv-sub" title={kind.detail}>
                                                                {kind.detail}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="inv-muted">
                                                    {count} item{count === 1 ? '' : 's'}
                                                </td>
                                                <td className="inv-num">{money(invoice.total_amount)}</td>
                                                <td className="inv-num">{money(invoice.paid_amount)}</td>
                                                <td
                                                    className={`inv-num inv-owed ${
                                                        cancelled ? '' : invoice.outstanding > 0 ? 'is-owed' : 'is-clear'
                                                    }`}
                                                >
                                                    {money(invoice.outstanding)}
                                                </td>
                                                <td>
                                                    <span className={`inv-pill ${pill.tone}`}>{pill.label}</span>
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
                                                                ...(invoice.is_draft && canCreate
                                                                    ? [
                                                                          {
                                                                              label: 'Close bill',
                                                                              icon: 'ti ti-lock-check',
                                                                              onClick: () => void closeBill(invoice),
                                                                          },
                                                                      ]
                                                                    : []),
                                                                ...(invoice.outstanding > 0 && !cancelled && canCollect
                                                                    ? [
                                                                          {
                                                                              label: 'Take payment',
                                                                              icon: 'ti ti-credit-card',
                                                                              onClick: () => setPaying(invoice),
                                                                          },
                                                                      ]
                                                                    : []),
                                                                ...(!cancelled && canPrint
                                                                    ? [
                                                                          {
                                                                              label: 'Download / Print PDF',
                                                                              icon: 'ti ti-download',
                                                                              onClick: () => void pdf(invoice, true),
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

                                        {pageNumbers(meta.current_page, meta.last_page).map((entry, index) =>
                                            entry === 'gap' ? (
                                                <span key={`gap-${index}`} className="dt-info px-1">
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
                                        onChange={(event) => setPerPage(Number(event.target.value))}
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

/* ── Pieces ──────────────────────────────────────────────────────────── */

function KpiCard({
    label,
    icon,
    tone,
    value,
    sub,
    card,
    riseIsBad = false,
}: {
    label: string;
    icon: string;
    tone: string;
    value: string;
    sub: string;
    card?: InvoiceCard;
    riseIsBad?: boolean;
}) {
    const change = card?.change ?? null;
    // Up is good for money in, bad for money owed.
    const good = change === null ? true : change >= 0 ? !riseIsBad : riseIsBad;

    return (
        <div className="inv-kpi">
            <span className={`inv-kpi-icon ${tone}`} aria-hidden="true">
                <i className={icon} />
            </span>

            <div className="inv-kpi-body">
                <span className="inv-kpi-label">{label}</span>
                <div className="inv-kpi-row">
                    <b className="inv-kpi-value">{value}</b>
                    {change !== null && (
                        <span
                            className={`inv-kpi-change ${good ? 'is-good' : 'is-bad'}`}
                            title="This month against last month"
                        >
                            <i className={`ti ${change >= 0 ? 'ti-trending-up' : 'ti-trending-down'}`} aria-hidden="true" />
                            {change >= 0 ? '+' : ''}
                            {change}%
                        </span>
                    )}
                </div>
                <span className="inv-kpi-sub">{sub}</span>
            </div>
        </div>
    );
}

/** Closes when somebody clicks anywhere else or presses Escape. */
function useDismiss(open: boolean, close: () => void, ...inside: RefObject<HTMLElement | null>[]) {
    useEffect(() => {
        if (!open) return;

        const outside = (event: MouseEvent) => {
            if (!inside.some((ref) => ref.current?.contains(event.target as Node))) close();
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') close();
        };

        document.addEventListener('mousedown', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', outside);
            document.removeEventListener('keydown', escape);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
}

function ExportMenu({ selected, onExport }: { selected: number; onExport: (onlySelected: boolean) => void }) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    return (
        <div className="inv-menu-wrap" ref={box}>
            <button type="button" className="inv-export" onClick={() => setOpen((current) => !current)} aria-expanded={open}>
                <i className="ti ti-download" aria-hidden="true" />
                Export
                <i className="ti ti-chevron-down" aria-hidden="true" />
            </button>

            {open && (
                <div className="inv-menu is-right" role="menu">
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => {
                            setOpen(false);
                            onExport(false);
                        }}
                    >
                        <i className="ti ti-file-spreadsheet" aria-hidden="true" />
                        Export this list (CSV)
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        disabled={selected === 0}
                        onClick={() => {
                            setOpen(false);
                            onExport(true);
                        }}
                    >
                        <i className="ti ti-checkbox" aria-hidden="true" />
                        Export selected ({selected})
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * The "⋮" on a row. Drawn into the page body at the button's position rather
 * than inside the row, so the table's horizontal scroll never clips it.
 */
function RowMenu({ items }: { items: { label: string; icon: string; onClick: () => void }[] }) {
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    useDismiss(at !== null, () => setAt(null), button, menu);

    useEffect(() => {
        if (at === null) return;

        const close = () => setAt(null);
        window.addEventListener('scroll', close, true);

        return () => window.removeEventListener('scroll', close, true);
    }, [at]);

    function toggle() {
        if (at !== null) {
            setAt(null);

            return;
        }

        const rect = button.current?.getBoundingClientRect();

        if (rect) setAt({ top: rect.bottom + 6, right: window.innerWidth - rect.right });
    }

    return (
        <>
            <button
                ref={button}
                type="button"
                className="inv-btn is-icon"
                onClick={toggle}
                aria-label="More actions"
                aria-expanded={at !== null}
            >
                <i className="ti ti-dots-vertical" aria-hidden="true" />
            </button>

            {at !== null &&
                createPortal(
                    <div ref={menu} className="inv-menu is-fixed" role="menu" style={{ top: at.top, right: at.right }}>
                        {items.map((item) => (
                            <button
                                key={item.label}
                                type="button"
                                role="menuitem"
                                onClick={() => {
                                    setAt(null);
                                    item.onClick();
                                }}
                            >
                                <i className={item.icon} aria-hidden="true" />
                                {item.label}
                            </button>
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}

/**
 * The date filter: a field showing the range, a panel with the ranges a desk
 * actually asks for, and two dates for anything else. Empty means every date
 * — the Outstanding tab must not hide last month's debts by default.
 */
function DateRangeField({
    value,
    onChange,
}: {
    value: { from: string; to: string } | null;
    onChange: (next: { from: string; to: string } | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    const today = new Date();
    const presets: { label: string; from: Date; to: Date }[] = [
        { label: 'Today', from: today, to: today },
        {
            label: 'Last 7 days',
            from: new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6),
            to: today,
        },
        { label: 'This month', from: new Date(today.getFullYear(), today.getMonth(), 1), to: today },
        {
            label: 'Last month',
            from: new Date(today.getFullYear(), today.getMonth() - 1, 1),
            to: new Date(today.getFullYear(), today.getMonth(), 0),
        },
        {
            // 1 April — the year every Indian register runs on.
            label: 'This financial year',
            from: new Date(today.getMonth() >= 3 ? today.getFullYear() : today.getFullYear() - 1, 3, 1),
            to: today,
        },
    ];

    const invalid = !from || !to || from > to;

    function toggle() {
        setFrom(value?.from ?? '');
        setTo(value?.to ?? '');
        setOpen((current) => !current);
    }

    function pick(next: { from: string; to: string } | null) {
        onChange(next);
        setOpen(false);
    }

    return (
        <div className="inv-menu-wrap" ref={box}>
            <button type="button" className="inv-select is-button" onClick={toggle} aria-expanded={open}>
                <i className="ti ti-calendar" aria-hidden="true" />
                <span className="inv-select-text">
                    {value ? `${longDate(value.from)} - ${longDate(value.to)}` : 'All dates'}
                </span>
                <i className="ti ti-chevron-down inv-select-caret" aria-hidden="true" />
            </button>

            {open && (
                <div className="inv-range-pop" role="dialog" aria-label="Date range">
                    <div className="inv-range-presets">
                        {presets.map((preset) => (
                            <button
                                key={preset.label}
                                type="button"
                                onClick={() => pick({ from: localIso(preset.from), to: localIso(preset.to) })}
                            >
                                {preset.label}
                            </button>
                        ))}
                    </div>

                    <div className="row g-2">
                        <div className="col-6">
                            <label htmlFor="inv-from">From</label>
                            <input
                                id="inv-from"
                                type="date"
                                className="form-control form-control-sm"
                                value={from}
                                max={to || localIso(today)}
                                onChange={(event) => setFrom(event.target.value)}
                            />
                        </div>
                        <div className="col-6">
                            <label htmlFor="inv-to">To</label>
                            <input
                                id="inv-to"
                                type="date"
                                className="form-control form-control-sm"
                                value={to}
                                min={from}
                                onChange={(event) => setTo(event.target.value)}
                            />
                        </div>
                    </div>

                    <div className="d-flex justify-content-between gap-2 mt-3">
                        <Button variant="light" size="sm" type="button" onClick={() => pick(null)}>
                            All dates
                        </Button>
                        <Button size="sm" type="button" disabled={invalid} onClick={() => pick({ from, to })}>
                            Apply
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
