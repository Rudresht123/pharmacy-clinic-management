import { useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { HistoryTimeline } from '@/shared/components/ui/HistoryTimeline';
import { Pagination } from '@/shared/components/ui/Pagination';
import { useTenantHistory, useTenantHistoryFilters } from '../api';

/** What "recent" means in the window filter, in days. */
const WINDOWS = [
    { value: '1', label: 'Last 24 hours' },
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 3 months' },
];

/** How the log's raw model names read to somebody who did not write them. */
const ENTITY_LABELS: Record<string, string> = {
    Customer: 'Customers',
    Location: 'Branches',
    User: 'People',
    EntityFieldSetting: 'Field settings',
};

/**
 * What this organization's own people have changed.
 *
 * Its own database, its own staff. A super administrator's platform actions
 * live in a different log entirely — they are not this organization's
 * business, and this organization's records are not theirs.
 */
export default function TenantHistoryPage() {
    const [filters, setFilters] = useState<Record<string, string>>({});
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');

    const { data: options } = useTenantHistoryFilters();

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

    const { data, isLoading, isError, refetch, isFetching } = useTenantHistory(params);

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
                    label: ENTITY_LABELS[type] ?? type,
                })),
            },
            {
                kind: 'select',
                name: 'event',
                label: 'Event',
                anyLabel: 'Any event',
                value: filters.event ?? '',
                options: (options?.actions ?? []).map((action) => ({
                    value: action,
                    label: action.charAt(0).toUpperCase() + action.slice(1),
                })),
            },
            {
                kind: 'text',
                name: 'actor',
                label: 'Done by',
                placeholder: 'Name of a staff member…',
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

    const meta = data?.meta;

    return (
        <>
            <PageHeader
                title="Activity"
                subtitle="Every change your team has made, field by field. These entries cannot be edited or removed, by anyone."
                icon="ti ti-history"
                tone="violet"
                crumbs={[{ label: 'Activity' }]}
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
                            aria-label="Search the activity log"
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
                    <LoadingBlock label="Loading activity…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : (
                    <>
                        <div className={`ht-scroll${isFetching ? ' ht-fading' : ''}`}>
                            <HistoryTimeline
                                entries={data?.data ?? []}
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
