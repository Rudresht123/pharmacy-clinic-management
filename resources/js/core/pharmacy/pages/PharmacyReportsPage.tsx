import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { formatDate } from '@/shared/utils/format';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { ReportSummaryView } from '../components/ReportSummary';
import { PAYMENT_METHOD_LABELS } from '../types';
import {
    REPORTS,
    REPORT_BLURBS,
    REPORT_LABELS,
    useReportRows,
    useReportSummary,
    type ReportKey,
} from '../reports';

type View = 'dashboard' | 'table';

const VIEWS: TabItem<View>[] = [
    { value: 'dashboard', label: 'Dashboard', icon: 'ti ti-chart-histogram' },
    { value: 'table', label: 'Table', icon: 'ti ti-table' },
];

/** Rupees, grouped the Indian way — ₹12,48,500 rather than ₹1,248,500. */
function money(amount: number): string {
    return `₹${Math.round(amount).toLocaleString('en-IN')}`;
}

function count(value: number): string {
    return value.toLocaleString('en-IN');
}

/** `upi` is how a tender is stored; "UPI" is how it is read. */
function tenderName(method: string): string {
    return PAYMENT_METHOD_LABELS[method] ?? method;
}


/** Which columns each report's table has, and how each cell reads. */
type Column = {
    key: string;
    label: string;
    /** Right-aligned, tabular, and never wrapped. */
    numeric?: boolean;
    cell?: (row: Record<string, unknown>) => string;
};

const COLUMNS: Record<ReportKey, Column[]> = {
    sales: [
        { key: 'date', label: 'Date', cell: (row) => formatDate(row.date as string) },
        { key: 'number', label: 'Bill' },
        { key: 'customer', label: 'Sold to' },
        { key: 'lines', label: 'Lines', numeric: true },
        { key: 'taxable', label: 'Taxable', numeric: true, cell: (row) => money(row.taxable as number) },
        { key: 'tax', label: 'GST', numeric: true, cell: (row) => money(row.tax as number) },
        { key: 'total', label: 'Total', numeric: true, cell: (row) => money(row.total as number) },
        { key: 'due', label: 'Owed', numeric: true, cell: (row) => money(row.due as number) },
        {
            key: 'tender',
            label: 'Paid by',
            cell: (row) => tenderName(row.tender as string),
        },
    ],
    purchases: [
        { key: 'date', label: 'Received', cell: (row) => formatDate(row.date as string) },
        { key: 'number', label: 'GRN' },
        { key: 'supplier', label: 'Supplier' },
        { key: 'invoice', label: 'Invoice', cell: (row) => (row.invoice as string) ?? '—' },
        { key: 'lines', label: 'Lines', numeric: true },
        { key: 'total', label: 'Total', numeric: true, cell: (row) => money(row.total as number) },
    ],
    stock: [
        { key: 'item', label: 'Item' },
        { key: 'category', label: 'Category' },
        { key: 'batches', label: 'Batches', numeric: true },
        { key: 'units', label: 'Units', numeric: true, cell: (row) => count(row.units as number) },
        { key: 'at_cost', label: 'At cost', numeric: true, cell: (row) => money(row.at_cost as number) },
        { key: 'at_mrp', label: 'At MRP', numeric: true, cell: (row) => money(row.at_mrp as number) },
    ],
    expiry: [
        { key: 'item', label: 'Item' },
        { key: 'batch', label: 'Batch' },
        { key: 'expiry', label: 'Expires', cell: (row) => formatDate(row.expiry as string) },
        {
            key: 'days_left',
            label: 'Days left',
            numeric: true,
            cell: (row) => ((row.days_left as number) < 0 ? 'Expired' : `${row.days_left}d`),
        },
        { key: 'units', label: 'Units', numeric: true, cell: (row) => count(row.units as number) },
        { key: 'at_cost', label: 'At cost', numeric: true, cell: (row) => money(row.at_cost as number) },
    ],
    profit: [
        { key: 'item', label: 'Item' },
        { key: 'units', label: 'Sold', numeric: true, cell: (row) => count(row.units as number) },
        { key: 'revenue', label: 'Revenue', numeric: true, cell: (row) => money(row.revenue as number) },
        { key: 'cost', label: 'Cost', numeric: true, cell: (row) => money(row.cost as number) },
        { key: 'margin', label: 'Margin', numeric: true, cell: (row) => money(row.margin as number) },
        { key: 'margin_percent', label: 'Margin %', numeric: true, cell: (row) => `${row.margin_percent}%` },
    ],
    gst: [
        { key: 'hsn', label: 'HSN' },
        { key: 'rate', label: 'Rate', numeric: true, cell: (row) => `${row.rate}%` },
        { key: 'units', label: 'Units', numeric: true, cell: (row) => count(row.units as number) },
        { key: 'taxable', label: 'Taxable', numeric: true, cell: (row) => money(row.taxable as number) },
        { key: 'tax', label: 'GST', numeric: true, cell: (row) => money(row.tax as number) },
    ],
};

/**
 * The pharmacy's reports, each readable two ways.
 *
 * **Dashboard** is the shape of the thing — a few figures and a chart — and
 * **Table** is the evidence, a page at a time. Both are cut from the same
 * filtered query on the server, so a figure on one is the sum of the other:
 * a summary nobody can drill into is a number taken on trust, and a table
 * with no summary is a spreadsheet.
 *
 * Which report, which view, which store and which dates all live in the URL,
 * so a refresh lands where it was and a link to "profit, last month, as a
 * table" is a link somebody can send.
 */
export default function PharmacyReportsPage() {
    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();
    const [params, setParams] = useSearchParams();

    const report = (REPORTS.find((key) => key === params.get('report')) ?? 'sales') as ReportKey;
    const view: View = params.get('view') === 'table' ? 'table' : 'dashboard';
    const page = Math.max(1, Number(params.get('page') ?? 1));
    const search = params.get('q') ?? '';

    const window = useMemo(
        () => ({
            from: params.get('from') ?? undefined,
            to: params.get('to') ?? undefined,
        }),
        [params],
    );

    function set(changes: Record<string, string | undefined>) {
        const next = new URLSearchParams(params);

        for (const [key, value] of Object.entries(changes)) {
            if (value === undefined || value === '') {
                next.delete(key);
            } else {
                next.set(key, value);
            }
        }

        setParams(next, { replace: true });
    }

    const summary = useReportSummary(store?.id, report, window);
    const rows = useReportRows(
        store?.id,
        report,
        { ...window, page, per_page: 25, search: search || undefined },
        view === 'table',
    );

    const columns = COLUMNS[report];

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    if (!store) {
        return (
            <Card>
                <NoStores />
            </Card>
        );
    }

    const dated = summary.data?.dated ?? true;

    return (
        <>
            <PageHeader
                title="Pharmacy reports"
                subtitle={REPORT_BLURBS[report]}
                icon="ti ti-report-analytics"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Reports' }]}
                actions={<StorePicker stores={stores} value={store} onChange={choose} />}
            />

            {/* Which report. Its own row, because it changes everything below. */}
            <div className="rp-reports">
                {REPORTS.map((key) => (
                    <button
                        type="button"
                        key={key}
                        className={`rp-report${key === report ? ' is-active' : ''}`}
                        onClick={() => set({ report: key, page: undefined, q: undefined })}
                    >
                        {REPORT_LABELS[key]}
                    </button>
                ))}
            </div>

            <div className="rp-bar">
                <Tabs tabs={VIEWS} value={view} onChange={(next) => set({ view: next })} label="How to read it" />

                {/* Hidden for the reports about the shelf right now: a date
                    range that changes nothing is a control that lies. */}
                {dated && (
                    <div className="rp-dates">
                        <label htmlFor="rp-from">From</label>
                        <input
                            id="rp-from"
                            type="date"
                            className="form-control"
                            value={summary.data?.from ?? ''}
                            onChange={(event) => set({ from: event.target.value, page: undefined })}
                        />

                        <label htmlFor="rp-to">to</label>
                        <input
                            id="rp-to"
                            type="date"
                            className="form-control"
                            value={summary.data?.to ?? ''}
                            onChange={(event) => set({ to: event.target.value, page: undefined })}
                        />
                    </div>
                )}
            </div>

            {summary.isError ? (
                <ErrorState onRetry={() => summary.refetch()} />
            ) : summary.isLoading || !summary.data ? (
                <LoadingBlock label="Adding it up…" />
            ) : view === 'dashboard' ? (
                <ReportSummaryView
                    summary={summary.data}
                    isLoading={summary.isLoading}
                    isError={summary.isError}
                    onRetry={() => summary.refetch()}
                />
            ) : (
                <Card>
                    <div className="rp-tools">
                        <div className="dpt-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="search"
                                className="form-control"
                                placeholder="Search these rows…"
                                aria-label="Search the report"
                                defaultValue={search}
                                onChange={(event) => set({ q: event.target.value, page: undefined })}
                            />
                        </div>

                        <span className="rp-count">
                            {rows.data ? `${count(rows.data.meta.total)} rows` : ''}
                        </span>
                    </div>

                    {rows.isError ? (
                        <ErrorState onRetry={() => rows.refetch()} />
                    ) : rows.isLoading || !rows.data ? (
                        <LoadingBlock label="Reading the rows…" />
                    ) : rows.data.data.length === 0 ? (
                        <p className="dpt-none">
                            Nothing in this range. Widen the dates, or clear the search.
                        </p>
                    ) : (
                        <>
                            <div className="dpt-frame">
                                <table className="dpt-table rp-table">
                                    <thead>
                                        <tr>
                                            {columns.map((column) => (
                                                <th
                                                    key={column.key}
                                                    className={column.numeric ? 'text-end' : undefined}
                                                >
                                                    {column.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.data.data.map((row, index) => (
                                            <tr key={String(row.id ?? index)}>
                                                {columns.map((column) => {
                                                    const text = column.cell
                                                        ? column.cell(row)
                                                        : String(row[column.key] ?? '—');

                                                    return (
                                                        <td
                                                            key={column.key}
                                                            className={column.numeric ? 'text-end dpt-num' : undefined}
                                                            title={text}
                                                        >
                                                            {text}
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {rows.data.meta.last_page > 1 && (
                                <Pagination
                                    page={rows.data.meta.current_page}
                                    pageCount={rows.data.meta.last_page}
                                    total={rows.data.meta.total}
                                    perPage={rows.data.meta.per_page}
                                    onChange={(next) => set({ page: String(next) })}
                                />
                            )}
                        </>
                    )}
                </Card>
            )}
        </>
    );
}
