import type { ReactNode } from 'react';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { formatDate } from '@/shared/utils/format';
import { count, inr } from '@/shared/components/ui/ReportCharts';
import { PAYMENT_METHOD_LABELS } from '../../types';
import type { FilterOptions, ReportKey, ReportRows } from '../../reports';
import { Badge } from './ReportDashboardView';

type Row = Record<string, unknown>;

interface Column {
    key: string;
    label: string;
    numeric?: boolean;
    /** The `sort` value the server takes for this column, where it sorts. */
    sort?: string;
    cell?: (row: Row) => ReactNode;
}

/** A name with a quieter line under it — a phone, a category. */
function Stacked({ main, sub }: { main: ReactNode; sub?: ReactNode }) {
    return (
        <span className="phr-stacked">
            <b>{main}</b>
            {sub ? <small>{sub}</small> : null}
        </span>
    );
}

const money = (key: string) => (row: Row) => inr(Number(row[key] ?? 0));
const number = (key: string) => (row: Row) => count(Number(row[key] ?? 0));

/** "upi" is how it is stored; "UPI" is how it is read. */
const tender = (method: string) => PAYMENT_METHOD_LABELS[method] ?? method;

const INWARD_TYPES: Record<string, string> = {
    purchase: 'Purchase',
    opening_balance: 'Opening balance',
    return_from_patient: 'Patient return',
};

/** Each report's table: what it shows, and which columns it can be sorted by. */
const COLUMNS: Record<ReportKey, Column[]> = {
    sales: [
        {
            key: 'date',
            label: 'Date & time',
            sort: 'date',
            cell: (row) => (
                <span className="phr-when">
                    {formatDate(row.date as string)}
                    <small>
                        {new Date(row.date as string).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
                    </small>
                </span>
            ),
        },
        { key: 'number', label: 'Invoice no.' },
        { key: 'customer', label: 'Patient', cell: (row) => <Stacked main={row.customer as string} sub={row.phone as string} /> },
        { key: 'lines', label: 'Items', numeric: true },
        { key: 'taxable', label: 'Taxable', numeric: true, cell: money('taxable') },
        { key: 'tax', label: 'GST', numeric: true, cell: money('tax') },
        { key: 'total', label: 'Amount', numeric: true, sort: 'amount', cell: (row) => <b>{inr(Number(row.total))}</b> },
        {
            key: 'due',
            label: 'Due',
            numeric: true,
            cell: (row) =>
                Number(row.due) > 0 ? <span className="phr-down">{inr(Number(row.due))}</span> : <span className="phr-muted">—</span>,
        },
        { key: 'tender', label: 'Paid by', cell: (row) => <span className="phr-pill">{tender(row.tender as string)}</span> },
        { key: 'status', label: 'Status', cell: (row) => <Badge status={row.status as string} /> },
    ],
    purchases: [
        { key: 'date', label: 'Received', sort: 'date', cell: (row) => formatDate(row.date as string) },
        { key: 'number', label: 'GRN no.' },
        { key: 'supplier', label: 'Supplier', cell: (row) => <b>{row.supplier as string}</b> },
        { key: 'invoice', label: 'Supplier invoice', cell: (row) => (row.invoice as string) || <span className="phr-muted">—</span> },
        { key: 'lines', label: 'Items', numeric: true },
        { key: 'type', label: 'Type', cell: (row) => <span className="phr-pill">{INWARD_TYPES[row.type as string] ?? (row.type as string)}</span> },
        { key: 'total', label: 'Amount', numeric: true, sort: 'amount', cell: (row) => <b>{inr(Number(row.total))}</b> },
    ],
    stock: [
        { key: 'item', label: 'Medicine', sort: 'name', cell: (row) => <Stacked main={row.item as string} sub={row.category as string} /> },
        { key: 'batches', label: 'Batches', numeric: true },
        {
            key: 'units',
            label: 'In stock',
            numeric: true,
            sort: 'units',
            cell: (row) => `${count(Number(row.units))} ${row.unit ?? ''}`.trim(),
        },
        {
            key: 'reorder_level',
            label: 'Reorder level',
            numeric: true,
            cell: (row) => (Number(row.reorder_level) > 0 ? count(Number(row.reorder_level)) : <span className="phr-muted">—</span>),
        },
        { key: 'status', label: 'Status', cell: (row) => <Badge status={row.status as string} /> },
        { key: 'at_cost', label: 'Value at cost', numeric: true, sort: 'value', cell: money('at_cost') },
        { key: 'at_mrp', label: 'Value at MRP', numeric: true, cell: money('at_mrp') },
    ],
    expiry: [
        { key: 'item', label: 'Medicine', cell: (row) => <b>{row.item as string}</b> },
        { key: 'batch', label: 'Batch no.', cell: (row) => <span className="phr-muted">{row.batch as string}</span> },
        { key: 'expiry', label: 'Expiry date', sort: 'expiry', cell: (row) => formatDate(row.expiry as string) },
        {
            key: 'days_left',
            label: 'Days left',
            numeric: true,
            cell: (row) => {
                const days = Number(row.days_left);

                return days <= 0 ? (
                    <Badge status="expired" />
                ) : (
                    <span className={`phr-days${days <= 30 ? ' is-soon' : ''}`}>{days}d</span>
                );
            },
        },
        { key: 'units', label: 'Units', numeric: true, cell: number('units') },
        { key: 'at_cost', label: 'Value at cost', numeric: true, sort: 'value', cell: money('at_cost') },
    ],
    profit: [
        { key: 'item', label: 'Medicine', cell: (row) => <Stacked main={row.item as string} sub={row.category as string} /> },
        { key: 'units', label: 'Units sold', numeric: true, sort: 'units', cell: number('units') },
        { key: 'revenue', label: 'Revenue', numeric: true, sort: 'revenue', cell: money('revenue') },
        { key: 'cost', label: 'Cost', numeric: true, cell: money('cost') },
        {
            key: 'margin',
            label: 'Profit',
            numeric: true,
            sort: 'margin',
            cell: (row) => <b className={Number(row.margin) < 0 ? 'phr-down' : undefined}>{inr(Number(row.margin))}</b>,
        },
        {
            key: 'margin_percent',
            label: 'Margin',
            numeric: true,
            sort: 'margin_percent',
            cell: (row) => {
                const percent = Number(row.margin_percent);

                return (
                    <span className="phr-margin">
                        <span className="phr-margin-bar">
                            <span style={{ width: `${Math.min(Math.max(percent, 0), 100)}%` }} />
                        </span>
                        {percent}%
                    </span>
                );
            },
        },
    ],
    gst: [
        { key: 'hsn', label: 'HSN code', cell: (row) => <b>{row.hsn as string}</b> },
        { key: 'rate', label: 'GST rate', numeric: true, cell: (row) => <span className="phr-pill">{Number(row.rate)}%</span> },
        { key: 'units', label: 'Units', numeric: true, cell: number('units') },
        { key: 'taxable', label: 'Taxable value', numeric: true, sort: 'taxable', cell: money('taxable') },
        { key: 'cgst', label: 'CGST', numeric: true, cell: money('cgst') },
        { key: 'sgst', label: 'SGST', numeric: true, cell: money('sgst') },
        { key: 'tax', label: 'Total GST', numeric: true, sort: 'tax', cell: (row) => <b>{inr(Number(row.tax))}</b> },
    ],
};

/** The sort a report's table opens on — the server's default, shown as such. */
const DEFAULT_SORT: Record<ReportKey, { sort: string; direction: 'asc' | 'desc' }> = {
    sales: { sort: 'date', direction: 'desc' },
    purchases: { sort: 'date', direction: 'desc' },
    stock: { sort: 'value', direction: 'desc' },
    expiry: { sort: 'expiry', direction: 'asc' },
    profit: { sort: 'margin', direction: 'desc' },
    gst: { sort: 'tax', direction: 'desc' },
};

interface FilterDef {
    key: string;
    label: string;
    all: string;
    /** Fixed choices; otherwise they come from the server with the rows. */
    options?: { value: string; label: string }[];
    named?: (value: string) => string;
}

const FILTERS: Record<ReportKey, FilterDef[]> = {
    sales: [
        {
            key: 'status',
            label: 'Status',
            all: 'All statuses',
            options: [
                { value: 'paid', label: 'Paid' },
                { value: 'partial', label: 'Partial' },
                { value: 'unpaid', label: 'Unpaid' },
            ],
        },
        { key: 'method', label: 'Payment method', all: 'All methods', named: tender },
    ],
    purchases: [
        { key: 'supplier_id', label: 'Supplier', all: 'All suppliers' },
        {
            key: 'type',
            label: 'Type',
            all: 'All types',
            options: Object.entries(INWARD_TYPES).map(([value, label]) => ({ value, label })),
        },
    ],
    stock: [
        { key: 'category', label: 'Category', all: 'All categories' },
        {
            key: 'level',
            label: 'Stock level',
            all: 'Any level',
            options: [
                { value: 'low', label: 'At or below reorder' },
                { value: 'ok', label: 'Above reorder' },
            ],
        },
    ],
    expiry: [
        {
            key: 'within',
            label: 'Expiry',
            all: 'Next 90 days',
            options: [
                { value: 'expired', label: 'Already expired' },
                { value: '30', label: 'Within 30 days' },
                { value: '60', label: 'Within 60 days' },
                { value: '90', label: 'Within 90 days' },
            ],
        },
    ],
    profit: [{ key: 'category', label: 'Category', all: 'All categories' }],
    gst: [{ key: 'rate', label: 'GST rate', all: 'All rates' }],
};

export const FILTER_KEYS: string[] = [...new Set(Object.values(FILTERS).flat().map((filter) => filter.key))];

/**
 * A report as its rows — searchable, filterable by what that report is
 * about, sortable by the columns that make sense to sort, a page at a time.
 *
 * Every control writes to the address bar through `set`, so the page the
 * reader is looking at is a link they can send.
 */
export function ReportTableView({
    report,
    rows,
    params,
    set,
}: {
    report: ReportKey;
    rows: { data?: ReportRows; isLoading: boolean; isError: boolean; isFetching: boolean; refetch: () => void };
    params: URLSearchParams;
    set: (changes: Record<string, string | undefined>) => void;
}) {
    const columns = COLUMNS[report];
    const filters = FILTERS[report];
    const options: FilterOptions = rows.data?.meta.filters ?? {};

    const sort = params.get('sort') ?? DEFAULT_SORT[report].sort;
    const direction = (params.get('direction') as 'asc' | 'desc' | null) ?? DEFAULT_SORT[report].direction;
    const perPage = params.get('per_page') ?? '25';

    const narrowed = Boolean(params.get('q')) || filters.some((filter) => params.get(filter.key));

    function sortBy(column: Column) {
        if (!column.sort) return;

        // A second click on the same column turns it round; a new column
        // starts the way that column is most often read.
        const next =
            column.sort === sort
                ? direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : column.sort === 'name' || column.sort === 'expiry'
                  ? 'asc'
                  : 'desc';

        set({ sort: column.sort, direction: next, page: undefined });
    }

    return (
        <section className="phr-card phr-rows">
            <div className="phr-tools">
                <div className="phr-search">
                    <i className="ti ti-search" aria-hidden="true" />
                    <input
                        key={report}
                        type="search"
                        className="form-control"
                        placeholder={report === 'gst' ? 'Search is not used for GST' : 'Search…'}
                        aria-label="Search the report"
                        disabled={report === 'gst'}
                        defaultValue={params.get('q') ?? ''}
                        onChange={(event) => set({ q: event.target.value, page: undefined })}
                    />
                </div>

                {filters.map((filter) => {
                    const choices = filter.options ?? options[filter.key] ?? [];

                    return (
                        <span className="od-select phr-filter" key={filter.key}>
                            <select
                                value={params.get(filter.key) ?? ''}
                                aria-label={filter.label}
                                onChange={(event) => set({ [filter.key]: event.target.value, page: undefined })}
                            >
                                <option value="">{filter.all}</option>
                                {choices.map((choice) => (
                                    <option key={choice.value} value={choice.value}>
                                        {filter.named ? filter.named(choice.label) : choice.label}
                                    </option>
                                ))}
                            </select>
                            <i className="ti ti-chevron-down" aria-hidden="true" />
                        </span>
                    );
                })}

                {narrowed && (
                    <button
                        type="button"
                        className="phr-clear"
                        onClick={() =>
                            set({
                                q: undefined,
                                page: undefined,
                                ...Object.fromEntries(filters.map((filter) => [filter.key, undefined])),
                            })
                        }
                    >
                        <i className="ti ti-x" aria-hidden="true" />
                        Clear filters
                    </button>
                )}

                <span className="phr-count">
                    {rows.data ? `${count(rows.data.meta.total)} ${rows.data.meta.total === 1 ? 'row' : 'rows'}` : ''}
                </span>
            </div>

            {rows.isError ? (
                <ErrorState onRetry={() => rows.refetch()} />
            ) : rows.isLoading || !rows.data ? (
                <LoadingBlock label="Reading the rows…" />
            ) : rows.data.data.length === 0 ? (
                <div className="od-nothing">
                    <i className="ti ti-table-off" aria-hidden="true" />
                    <p>{narrowed ? 'Nothing matches these filters.' : 'Nothing in this range. Try a wider date range.'}</p>
                </div>
            ) : (
                <>
                    <div className={`phr-table-wrap${rows.isFetching ? ' is-busy' : ''}`}>
                        <table className="phr-table is-full">
                            <thead>
                                <tr>
                                    {columns.map((column) => (
                                        <th
                                            key={column.key}
                                            className={column.numeric ? 'is-num' : undefined}
                                            aria-sort={
                                                column.sort === sort
                                                    ? direction === 'asc'
                                                        ? 'ascending'
                                                        : 'descending'
                                                    : undefined
                                            }
                                        >
                                            {column.sort ? (
                                                <button
                                                    type="button"
                                                    className={`phr-sort${column.sort === sort ? ' is-active' : ''}`}
                                                    onClick={() => sortBy(column)}
                                                >
                                                    {column.label}
                                                    <i
                                                        className={
                                                            column.sort !== sort
                                                                ? 'ti ti-arrows-sort'
                                                                : direction === 'asc'
                                                                  ? 'ti ti-arrow-up'
                                                                  : 'ti ti-arrow-down'
                                                        }
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            ) : (
                                                column.label
                                            )}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.data.map((row, index) => (
                                    <tr key={String(row.id ?? index)}>
                                        {columns.map((column) => (
                                            <td key={column.key} className={column.numeric ? 'is-num' : undefined}>
                                                {column.cell ? column.cell(row) : String(row[column.key] ?? '—')}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="phr-foot">
                        <label className="phr-per-page">
                            Rows per page
                            <span className="od-select">
                                <select value={perPage} onChange={(event) => set({ per_page: event.target.value, page: undefined })}>
                                    {['10', '25', '50', '100'].map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </select>
                                <i className="ti ti-chevron-down" aria-hidden="true" />
                            </span>
                        </label>

                        {rows.data.meta.last_page > 1 && (
                            <Pagination
                                page={rows.data.meta.current_page}
                                pageCount={rows.data.meta.last_page}
                                total={rows.data.meta.total}
                                perPage={rows.data.meta.per_page}
                                onChange={(next) => set({ page: String(next) })}
                            />
                        )}
                    </div>
                </>
            )}
        </section>
    );
}
