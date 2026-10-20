import { useMemo, useState } from 'react';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { cn } from '@/shared/utils/cn';
import { downloadExport, useExportPreview } from '../api';
import { rangeOf } from '../periods';
import type { ExportDataset, ExportFormat, ExportParams, ExportTab } from '../types';
import { ColumnPicker } from './ColumnPicker';
import { DateRangeField, type DateRange } from './DateRangeField';
import { Pager } from './Pager';
import { RecordsTable } from './RecordsTable';
import { usePopover } from './usePopover';

const FORMATS: { format: ExportFormat; label: string; icon: string }[] = [
    { format: 'xlsx', label: 'Excel', icon: 'ti ti-file-spreadsheet' },
    { format: 'csv', label: 'CSV', icon: 'ti ti-file-type-csv' },
    { format: 'pdf', label: 'PDF', icon: 'ti ti-file-type-pdf' },
];

interface Filters {
    range: DateRange;
    status: string;
    branch: string;
    doctor: string;
    search: string;
}

function start(dataset: ExportDataset): Filters {
    return {
        range: dataset.date_label
            ? { period: dataset.default_period, ...rangeOf(dataset.default_period, { from: '', to: '' }) }
            : { period: 'all', from: '', to: '' },
        status: '',
        branch: '',
        doctor: '',
        search: '',
    };
}

/**
 * One export, browsed and taken.
 *
 * The first card sets it up — its dates, status, branch, doctor and search,
 * applied together with Apply filters, and which columns go in. The second is
 * the result: how many records, the table of them a page at a time, and the
 * three formats. What the table shows is what the file holds — the same rows,
 * the same columns, the same order; tick rows and the file holds only those.
 *
 * Mounted once per export (the page keys it), so switching export starts from
 * that export's own defaults.
 */
export function ExportWorkspace({
    tab,
    dataset,
    doctors,
    pdfLimit,
}: {
    tab: ExportTab;
    dataset: ExportDataset;
    doctors: { id: number; name: string }[];
    pdfLimit: number;
}) {
    const [draft, setDraft] = useState<Filters>(() => start(dataset));
    const [applied, setApplied] = useState<Filters>(() => start(dataset));
    const [columns, setColumns] = useState(() => dataset.columns.map((column) => column.key));
    const [sort, setSort] = useState<{ key: string; direction: 'asc' | 'desc' } | null>(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState<ExportFormat | null>(null);
    const exportMenu = usePopover();

    const set = (next: Partial<Filters>) => setDraft((current) => ({ ...current, ...next }));

    const apply = (next: Filters = draft) => {
        setApplied(next);
        setPage(1);
        setSelected([]);
    };

    const reset = () => {
        const fresh = start(dataset);
        setDraft(fresh);
        apply(fresh);
    };

    // What narrows the rows — shared by the table and every download.
    const narrowing = useMemo<ExportParams>(
        () => ({
            from: applied.range.from,
            to: applied.range.to,
            status: applied.status,
            location_id: applied.branch,
            doctor_id: applied.doctor,
            search: applied.search.trim(),
            columns: columns.length === dataset.columns.length ? '' : columns.join(','),
            sort: sort?.key,
            direction: sort?.direction,
        }),
        [applied, columns, sort, dataset.columns.length],
    );

    const preview = useExportPreview(columns.length > 0 ? dataset.key : null, {
        ...narrowing,
        page: String(page),
        per_page: String(perPage),
    });

    const result = preview.data;
    const total = result?.total ?? 0;
    const narrowed = Boolean(applied.range.from || applied.range.to || applied.status || applied.branch || applied.doctor || applied.search.trim());
    const dirty = JSON.stringify(draft) !== JSON.stringify(applied);

    const branchName = tab.branches.find((entry) => String(entry.id) === applied.branch)?.name;
    const scope = branchName ?? (tab.all_branches ? 'All branches' : tab.branches.map((entry) => entry.name).join(', '));

    async function take(format: ExportFormat, only?: number[]) {
        exportMenu.setOpen(false);
        setBusy(format);

        try {
            const ids = only ?? selected;
            await downloadExport(dataset.key, { ...narrowing, ids: ids.length > 0 ? ids.join(',') : undefined }, format);
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        } finally {
            setBusy(null);
        }
    }

    const disabled = columns.length === 0 || total === 0 || busy !== null;

    return (
        <>
            <section className="exc-card exc-filter-card">
                <header className="exc-head">
                    <span className="exc-head-icon" aria-hidden="true">
                        <i className={dataset.icon} />
                    </span>
                    <div className="exc-head-text">
                        <h2>{dataset.label}</h2>
                        <p>{dataset.description}</p>
                    </div>

                    <div className="exc-head-actions">
                        <span className="exc-scope" title="Whose records this reaches">
                            <i className="ti ti-building-hospital" aria-hidden="true" />
                            <span>{scope}</span>
                        </span>

                        <div className="exc-export" ref={exportMenu.ref}>
                            <button
                                type="button"
                                className="btn btn-primary exc-export-trigger"
                                onClick={() => exportMenu.setOpen((was) => !was)}
                                disabled={disabled}
                                aria-haspopup="menu"
                                aria-expanded={exportMenu.open}
                            >
                                <i className={busy ? 'ti ti-loader-2 exc-spin' : 'ti ti-download'} aria-hidden="true" />
                                {busy ? 'Preparing…' : 'Export'}
                                <i className="ti ti-chevron-down" aria-hidden="true" />
                            </button>

                            {exportMenu.open && (
                                <div className="exc-pop exc-export-pop" role="menu">
                                    <p className="exc-export-scope">
                                        {selected.length > 0
                                            ? `${selected.length} selected ${selected.length === 1 ? 'record' : 'records'}`
                                            : `${narrowed ? '' : 'All '}${total.toLocaleString('en-IN')} ${dataset.noun}${narrowed ? ' matching your filters' : ''}`}
                                    </p>
                                    {FORMATS.map((entry) => (
                                        <button key={entry.format} type="button" role="menuitem" onClick={() => void take(entry.format)}>
                                            <i className={cn(entry.icon, `is-${entry.format}`)} aria-hidden="true" />
                                            <span>
                                                <b>{entry.label}</b>
                                                <small>
                                                    {entry.format === 'pdf' && total > pdfLimit && selected.length === 0
                                                        ? `First ${pdfLimit.toLocaleString('en-IN')} records`
                                                        : `.${entry.format}`}
                                                </small>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                <form
                    className="exc-filters"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply();
                    }}
                >
                    <div className="exc-filter-fields">
                        {dataset.date_label && (
                            <div className="exc-field is-range">
                                <span className="exc-label">{dataset.date_label}</span>
                                <DateRangeField
                                    value={draft.range}
                                    onChange={(range) => set({ range })}
                                    defaultPeriod={dataset.default_period}
                                    label={dataset.date_label}
                                />
                            </div>
                        )}

                        {dataset.statuses.length > 0 && (
                            <div className="exc-field">
                                <span className="exc-label">{dataset.status_label}</span>
                                <SearchableSelect
                                    value={draft.status}
                                    onChange={(status) => set({ status })}
                                    options={[{ value: '', label: 'All' }, ...dataset.statuses]}
                                    ariaLabel={dataset.status_label}
                                />
                            </div>
                        )}

                        {tab.branches.length > 1 && (
                            <div className="exc-field">
                                <span className="exc-label">Branch</span>
                                <SearchableSelect
                                    value={draft.branch}
                                    onChange={(branch) => set({ branch })}
                                    options={[
                                        { value: '', label: tab.all_branches ? 'All branches' : 'All my branches' },
                                        ...tab.branches.map((entry) => ({ value: String(entry.id), label: entry.name })),
                                    ]}
                                    ariaLabel="Branch"
                                />
                            </div>
                        )}

                        {dataset.doctor_filter && doctors.length > 0 && (
                            <div className="exc-field">
                                <span className="exc-label">Doctor</span>
                                <SearchableSelect
                                    value={draft.doctor}
                                    onChange={(doctor) => set({ doctor })}
                                    options={[
                                        { value: '', label: 'All doctors' },
                                        ...doctors.map((entry) => ({ value: String(entry.id), label: entry.name })),
                                    ]}
                                    ariaLabel="Doctor"
                                />
                            </div>
                        )}

                        {dataset.search_hint && (
                            <div className="exc-field is-search">
                                <label className="exc-label" htmlFor={`exc-search-${dataset.key}`}>
                                    Search
                                </label>
                                <span className="exc-search">
                                    <i className="ti ti-search" aria-hidden="true" />
                                    <input
                                        id={`exc-search-${dataset.key}`}
                                        type="search"
                                        value={draft.search}
                                        onChange={(event) => set({ search: event.target.value })}
                                        placeholder={dataset.search_hint}
                                    />
                                </span>
                            </div>
                        )}
                    </div>

                    {/* The card's foot: pinned to its bottom edge, whatever height the list beside sets. */}
                    <div className="exc-filter-actions">
                        <button type="submit" className={cn('btn btn-primary', dirty && 'is-dirty')}>
                            <i className="ti ti-filter" aria-hidden="true" /> Apply filters
                        </button>
                        <button type="button" className="btn exc-btn-quiet" onClick={reset}>
                            <i className="ti ti-refresh" aria-hidden="true" /> Reset
                        </button>
                        {dirty && <span className="exc-hint">Filters changed — apply to update the records.</span>}

                        {/* Which columns the table and the file carry — applied at once, no Apply needed. */}
                        <ColumnPicker columns={dataset.columns} chosen={columns} onChange={setColumns} />
                    </div>
                </form>
            </section>

            <section className="exc-card exc-results" aria-busy={preview.isFetching}>
                <header className="exc-results-head">
                    <div className="exc-results-count">
                        <h3>
                            {result ? total.toLocaleString('en-IN') : '…'} <span>records</span>
                        </h3>
                        {selected.length > 0 ? (
                            <p className="is-selected">
                                <i className="ti ti-square-check" aria-hidden="true" /> {selected.length} selected — downloads contain only
                                these.{' '}
                                <button type="button" onClick={() => setSelected([])}>
                                    Clear
                                </button>
                            </p>
                        ) : (
                            <p>
                                Showing all {dataset.noun}
                                {narrowed ? ' matching your filters' : ' you can export'}
                            </p>
                        )}
                    </div>

                    <div className="exc-results-formats">
                        {FORMATS.map((entry) => (
                            <button
                                key={entry.format}
                                type="button"
                                className={cn('exc-format-btn', `is-${entry.format}`)}
                                onClick={() => void take(entry.format)}
                                disabled={disabled}
                            >
                                <i className={busy === entry.format ? 'ti ti-loader-2 exc-spin' : entry.icon} aria-hidden="true" />
                                {entry.label}
                            </button>
                        ))}
                    </div>
                </header>

                {columns.length === 0 ? (
                    <div className="exc-empty">
                        <i className="ti ti-columns-off" aria-hidden="true" />
                        <b>No columns chosen</b>
                        <span>Pick at least one column above to see the records.</span>
                    </div>
                ) : preview.isLoading && !result ? (
                    <div className="exc-loading">
                        {Array.from({ length: 6 }, (_, index) => (
                            <span key={index} />
                        ))}
                    </div>
                ) : preview.isError ? (
                    <div className="exc-empty">
                        <i className="ti ti-alert-triangle" aria-hidden="true" />
                        <b>Could not load the records</b>
                        <span>{resolveErrorMessage(preview.error)}</span>
                    </div>
                ) : total === 0 || !result ? (
                    <div className="exc-empty">
                        <i className="ti ti-filter-off" aria-hidden="true" />
                        <b>Nothing matches these filters</b>
                        <span>Widen the dates or clear a filter to see {dataset.noun} here.</span>
                    </div>
                ) : (
                    <>
                        <RecordsTable
                            columns={result.columns}
                            rows={result.rows}
                            sortable={dataset.sortable}
                            sort={sort}
                            onSort={(key) => {
                                setSort((current) =>
                                    current?.key === key
                                        ? current.direction === 'desc'
                                            ? { key, direction: 'asc' }
                                            : null
                                        : { key, direction: 'desc' },
                                );
                                setPage(1);
                            }}
                            selected={selected}
                            onSelect={(ids, on) =>
                                setSelected((current) =>
                                    on ? [...new Set([...current, ...ids])] : current.filter((id) => !ids.includes(id)),
                                )
                            }
                            refreshing={preview.isFetching}
                            onDownloadRow={(id, format) => void take(format, [id])}
                        />

                        <Pager
                            page={result.meta.current_page}
                            lastPage={result.meta.last_page}
                            perPage={result.meta.per_page}
                            total={total}
                            from={result.meta.from}
                            to={result.meta.to}
                            onPage={setPage}
                            onPerPage={(size) => {
                                setPerPage(size);
                                setPage(1);
                            }}
                        />
                    </>
                )}
            </section>
        </>
    );
}
