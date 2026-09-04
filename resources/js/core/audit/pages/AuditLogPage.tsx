import { useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { HistoryTimeline } from '@/shared/components/ui/HistoryTimeline';
import { Pagination } from '@/shared/components/ui/Pagination';
import { useAuditFilters, useAuditLog } from '../api';

/** What "recent" means in the window filter, in days. */
const WINDOWS = [
    { value: '1', label: 'Last 24 hours' },
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 3 months' },
];

/**
 * Everything that has been done on the platform.
 *
 * A timeline rather than a table. The rows are not comparable to one another
 * — a rename and a module assignment share no columns worth lining up — and
 * what a reader wants from each one is a sentence: who, what, and what it
 * used to be.
 *
 * Paged rather than infinite, because the question is almost always "what
 * happened recently", and a page that grows as you scroll makes it hard to
 * say where you have got to.
 */
export default function AuditLogPage() {
    const [filters, setFilters] = useState<Record<string, string>>({});
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');

    const { data: options } = useAuditFilters();

    const params = useMemo(() => {
        const { window, ...rest } = filters;

        return {
            page,
            per_page: 30,
            ...(search ? { search } : {}),
            ...rest,
            // The control offers a span; the API takes a date.
            ...(window
                ? { since: new Date(Date.now() - Number(window) * 86_400_000).toISOString() }
                : {}),
        };
    }, [filters, page, search]);

    const { data, isLoading, isError, refetch, isFetching } = useAuditLog(params);

    function setFilter(name: string, value: string) {
        setPage(1);

        setFilters((current) => {
            const next = { ...current };

            if (value === '') {
                delete next[name];
            } else {
                next[name] = value;
            }

            return next;
        });
    }

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'entity_type',
                label: 'Record type',
                anyLabel: 'Anything',
                value: filters.entity_type ?? '',
                options: (options?.entity_types ?? []).map((type) => ({
                    value: type,
                    label: type,
                })),
            },
            {
                kind: 'select',
                name: 'action',
                label: 'Event',
                anyLabel: 'Any event',
                value: filters.action ?? '',
                options: (options?.actions ?? []).map((action) => ({
                    value: action,
                    label: action.charAt(0).toUpperCase() + action.slice(1),
                })),
            },
            {
                kind: 'text',
                name: 'actor',
                label: 'Done by',
                placeholder: 'Name of an administrator…',
                value: filters.actor ?? '',
            },
            {
                kind: 'select',
                name: 'window',
                label: 'When',
                anyLabel: 'Any time',
                value: filters.window ?? '',
                options: WINDOWS,
            },
        ],
        [options, filters],
    );

    const entries = data?.data ?? [];
    const meta = data?.meta;

    return (
        <>
            <PageHeader
                title="Audit Log"
                subtitle="Every change made on the platform, field by field. Append-only — the database refuses to let these rows be edited or removed."
                icon="ti ti-history"
                tone="indigo"
                crumbs={[{ label: 'Audit Log' }]}
            />

            <FilterPanel
                fields={filterFields}
                onChange={setFilter}
                onClear={() => {
                    setFilters({});
                    setPage(1);
                }}
            />

            <Card
                title={meta ? `${meta.total} changes` : 'Changes'}
                icon="ti ti-list-details"
                actions={
                    <div className="ht-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            placeholder="Search by record or person…"
                            aria-label="Search the audit log"
                            value={search}
                            onChange={(event) => {
                                setPage(1);
                                setSearch(event.target.value);
                            }}
                        />
                    </div>
                }
            >
                {isLoading ? (
                    <LoadingBlock label="Loading the log…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : (
                    <>
                        <div className={`ht-scroll${isFetching ? ' ht-fading' : ''}`}>
                            <HistoryTimeline
                                entries={entries}
                                showSubject
                                empty={
                                    Object.keys(filters).length > 0 || search
                                        ? 'Nothing matches these filters.'
                                        : 'Nothing has been changed yet.'
                                }
                            />
                        </div>

                        {meta && (
                            <Pagination
                                page={meta.current_page}
                                pageCount={meta.last_page}
                                total={meta.total}
                                perPage={meta.per_page}
                                onChange={setPage}
                            />
                        )}
                    </>
                )}
            </Card>
        </>
    );
}
