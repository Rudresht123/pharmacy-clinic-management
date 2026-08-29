import { useState, type ReactNode } from 'react';
import {
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
    type ColumnDef,
    type SortingState,
} from '@tanstack/react-table';
import { LoadingBlock, EmptyState, ErrorState, NoResultsState } from './Feedback';
import type { Tone } from './tones';
import { cn } from '@/shared/utils/cn';

/**
 * Lets a column supply its own mobile label when its header is JSX rather
 * than plain text:  { meta: { label: 'Status' } }
 */
declare module '@tanstack/react-table' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface ColumnMeta<TData extends unknown, TValue> {
        label?: string;
    }
}

const PAGE_SIZES = [10, 25, 50, 100];

/** Page buttons to show around the current one before collapsing to an ellipsis. */
const WINDOW = 1;

/** Supplied by useServerTable when the API does the paging. */
export interface ServerTableBinding {
    pageIndex: number;
    pageSize: number;
    sorting: SortingState;
    search: string;
    setPageIndex(index: number): void;
    setPageSize(size: number): void;
    setSorting(sorting: SortingState): void;
    setSearch(value: string): void;
    total: number;
    pageCount: number;
}

interface DataTableProps<TRow> {
    data: TRow[];
    columns: ColumnDef<TRow, any>[];
    /** First load, with nothing to show yet. */
    loading?: boolean;
    /**
     * A background refresh — a new page or search result — while previous
     * rows are still on screen. Shows a spinner over the table.
     */
    fetching?: boolean;
    error?: boolean;
    onRetry?: () => void;
    searchPlaceholder?: string;
    emptyIcon?: string;
    emptyTone?: Tone;
    emptyTitle?: string;
    emptyDescription?: ReactNode;
    emptyAction?: ReactNode;
    pageSize?: number;
    toolbar?: ReactNode;
    /**
     * Present = the server handles search, sort and paging; the component
     * renders exactly the rows it is given. Absent = everything is done in
     * the browser over the full `data` array.
     */
    server?: ServerTableBinding;
}

/** Stacked chevrons while unsorted, a single arrow once sorted. */
function SortIcon({ direction }: { direction: false | 'asc' | 'desc' }) {
    const icon =
        direction === 'asc'
            ? 'ti-chevron-up'
            : direction === 'desc'
              ? 'ti-chevron-down'
              : 'ti-selector';

    return <i className={cn('ti', icon, 'dt-sort-icon')} />;
}

/**
 * Builds a compact page list: 1 … 4 5 6 … 12
 */
function pageNumbers(current: number, total: number): (number | 'gap')[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index);
    }

    const pages = new Set<number>([0, total - 1, current]);

    for (let offset = 1; offset <= WINDOW; offset += 1) {
        pages.add(Math.max(0, current - offset));
        pages.add(Math.min(total - 1, current + offset));
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const output: (number | 'gap')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            output.push('gap');
        }

        output.push(page);
    });

    return output;
}

/**
 * The app's table: "Show N Entries" and search above, sortable headers with
 * the active column tinted, and windowed pagination below.
 *
 * Styling lives in vendor/css/table.css (.dt-*). Sorting, filtering and
 * paging run client-side through TanStack Table — jQuery DataTables cannot
 * see React-rendered rows.
 */
export function DataTable<TRow>({
    data,
    columns,
    loading = false,
    fetching = false,
    error = false,
    onRetry,
    searchPlaceholder = 'Search here...',
    emptyIcon,
    emptyTone,
    emptyTitle = 'Nothing here yet',
    emptyDescription,
    emptyAction,
    pageSize = 25,
    toolbar,
    server,
}: DataTableProps<TRow>) {
    const [sorting, setSorting] = useState<SortingState>([]);
    const [globalFilter, setGlobalFilter] = useState('');

    const isServer = server !== undefined;

    const table = useReactTable({
        data,
        columns,
        state: isServer
            ? {
                  sorting: server.sorting,
                  pagination: { pageIndex: server.pageIndex, pageSize: server.pageSize },
              }
            : { sorting, globalFilter },

        // In server mode the row models must not re-sort, re-filter or
        // re-slice what the API already decided.
        manualPagination: isServer,
        manualSorting: isServer,
        manualFiltering: isServer,
        pageCount: isServer ? server.pageCount : undefined,

        onSortingChange: isServer
            ? (updater) =>
                  server.setSorting(
                      typeof updater === 'function' ? updater(server.sorting) : updater,
                  )
            : setSorting,
        onGlobalFilterChange: isServer ? undefined : setGlobalFilter,

        getCoreRowModel: getCoreRowModel(),
        ...(isServer
            ? {}
            : {
                  getSortedRowModel: getSortedRowModel(),
                  getFilteredRowModel: getFilteredRowModel(),
                  getPaginationRowModel: getPaginationRowModel(),
              }),
        initialState: isServer ? undefined : { pagination: { pageSize } },
    });

    const rows = table.getRowModel().rows;

    const totalRows = isServer ? server.total : table.getFilteredRowModel().rows.length;
    const pageIndex = isServer ? server.pageIndex : table.getState().pagination.pageIndex;
    const currentPageSize = isServer ? server.pageSize : table.getState().pagination.pageSize;
    const pageCount = isServer ? server.pageCount : table.getPageCount();

    const searchValue = isServer ? server.search : globalFilter;
    const setSearchValue = isServer ? server.setSearch : setGlobalFilter;
    const isSearching = searchValue.trim() !== '';

    /** One place to move pages, whichever side owns the data. */
    const goToPage = (page: number) => {
        const target = Math.max(0, Math.min(page, pageCount - 1));

        if (isServer) {
            server.setPageIndex(target);
        } else {
            table.setPageIndex(target);
        }
    };

    if (error) {
        return <ErrorState onRetry={onRetry} />;
    }

    // In server mode an empty first page with no search really is an empty
    // resource; while searching, an empty result is a "no matches" state.
    if (!loading && data.length === 0 && !isSearching) {
        return (
            <EmptyState
                icon={emptyIcon}
                tone={emptyTone}
                title={emptyTitle}
                description={emptyDescription}
                action={emptyAction}
            />
        );
    }

    return (
        <div className="dt">
            <div className="dt-toolbar">
                <label className="dt-length">
                    <span>Show</span>

                    <select
                        value={currentPageSize}
                        onChange={(event) => {
                            const size = Number(event.target.value);

                            if (isServer) {
                                server.setPageSize(size);
                            } else {
                                table.setPageSize(size);
                            }
                        }}
                        aria-label="Rows per page"
                    >
                        {PAGE_SIZES.map((size) => (
                            <option key={size} value={size}>
                                {size}
                            </option>
                        ))}
                    </select>

                    <span>Entries</span>
                </label>

                <div className="d-flex align-items-center gap-2">
                    {toolbar}

                    <div className="dt-search">
                        <i className="ti ti-search" />

                        <input
                            type="search"
                            placeholder={searchPlaceholder}
                            value={searchValue}
                            onChange={(event) => setSearchValue(event.target.value)}
                            aria-label="Search table"
                        />
                    </div>
                </div>
            </div>

            <div className="dt-wrap" data-fetching={fetching && !loading}>
                {fetching && !loading && (
                    <div className="dt-overlay" role="status" aria-label="Loading results">
                        <span className="dt-overlay-spinner" />
                    </div>
                )}

                <table className="dt-table">
                    <thead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    const sortable = header.column.getCanSort();
                                    const sorted = header.column.getIsSorted();

                                    return (
                                        <th
                                            key={header.id}
                                            style={{ width: header.column.columnDef.size }}
                                            className={cn(
                                                sortable && 'is-sortable',
                                                sorted && 'is-sorted',
                                            )}
                                            onClick={
                                                sortable
                                                    ? header.column.getToggleSortingHandler()
                                                    : undefined
                                            }
                                            aria-sort={
                                                sorted === 'asc'
                                                    ? 'ascending'
                                                    : sorted === 'desc'
                                                      ? 'descending'
                                                      : undefined
                                            }
                                        >
                                            {flexRender(
                                                header.column.columnDef.header,
                                                header.getContext(),
                                            )}

                                            {sortable && <SortIcon direction={sorted} />}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>

                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan={columns.length} className="dt-empty">
                                    <LoadingBlock />
                                </td>
                            </tr>
                        ) : rows.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length} className="dt-empty">
                                    <NoResultsState
                                        term={searchValue}
                                        tone={emptyTone}
                                        onClear={() => setSearchValue('')}
                                    />
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr key={row.id}>
                                    {row.getVisibleCells().map((cell) => {
                                        const header = cell.column.columnDef.header;

                                        // Label shown beside each value once rows
                                        // stack into cards on mobile: an explicit
                                        // meta.label wins, otherwise a plain-text
                                        // header is used.
                                        const label =
                                            cell.column.columnDef.meta?.label ??
                                            (typeof header === 'string' ? header : '');

                                        return (
                                            <td
                                                key={cell.id}
                                                data-label={label}
                                                data-col={cell.column.id}
                                                // Tints the whole sorted column, not just
                                                // its header, so the eye can follow it down.
                                                className={cn(
                                                    cell.column.getIsSorted() && 'is-sorted',
                                                )}
                                            >
                                                {flexRender(
                                                    cell.column.columnDef.cell,
                                                    cell.getContext(),
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <div className="dt-foot">
                <span className="dt-info">
                    Showing {totalRows === 0 ? 0 : pageIndex * currentPageSize + 1}–
                    {Math.min((pageIndex + 1) * currentPageSize, totalRows)} of {totalRows}
                </span>

                {pageCount > 1 && (
                    <nav className="dt-pages" aria-label="Pagination">
                        <button
                            type="button"
                            className="dt-page"
                            aria-label="Previous page"
                            onClick={() => goToPage(pageIndex - 1)}
                            disabled={pageIndex === 0}
                        >
                            <i className="ti ti-chevron-left" />
                        </button>

                        {pageNumbers(pageIndex, pageCount).map((page, index) =>
                            page === 'gap' ? (
                                <span key={`gap-${index}`} className="dt-info px-1">
                                    …
                                </span>
                            ) : (
                                <button
                                    key={page}
                                    type="button"
                                    className={cn('dt-page', page === pageIndex && 'is-active')}
                                    aria-current={page === pageIndex ? 'page' : undefined}
                                    onClick={() => goToPage(page)}
                                >
                                    {page + 1}
                                </button>
                            ),
                        )}

                        <button
                            type="button"
                            className="dt-page"
                            aria-label="Next page"
                            onClick={() => goToPage(pageIndex + 1)}
                            disabled={pageIndex >= pageCount - 1}
                        >
                            <i className="ti ti-chevron-right" />
                        </button>
                    </nav>
                )}
            </div>
        </div>
    );
}
