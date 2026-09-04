import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { createColumnHelper } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatTiles } from '@/shared/components/ui/StatTiles';
import { Modal } from '@/shared/components/ui/Modal';
import { Tabs } from '@/shared/components/ui/Tabs';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { ModuleAssignPanel } from '@/core/organizations/components/ModuleAssignPanel';
import { useModuleCatalogue, useModuleOrganizations } from '../api';
import type { CatalogueModule } from '../types';

const GROUP_LABELS: Record<string, string> = {
    foundation: 'Foundation',
    pharmacy: 'Pharmacy',
    commerce: 'Commerce',
    clinical: 'Clinical',
};

type View = 'assign' | 'catalogue';

/**
 * Module access, platform-wide.
 *
 * Two views of one subject, and the order matters: the job is giving an
 * organization what it has paid for, so that comes first. The catalogue is
 * the reference behind it — what exists, and what each one grants — which
 * is read occasionally rather than acted on.
 */
export default function ModuleAccessPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const view: View = searchParams.get('view') === 'catalogue' ? 'catalogue' : 'assign';

    const { data: catalogue, isLoading: catalogueLoading } = useModuleCatalogue();

    const tiles = useMemo(() => {
        const all = catalogue?.modules ?? [];
        const optional = all.filter((module) => !module.is_core);

        return [
            {
                label: 'Modules',
                value: all.length,
                icon: 'ti ti-puzzle',
                tone: 'indigo' as const,
                hint: `${all.length - optional.length} included with every organization`,
            },
            {
                label: 'Sold separately',
                value: optional.length,
                icon: 'ti ti-shopping-cart',
                tone: 'sky' as const,
            },
            {
                label: 'Permissions',
                value: catalogue?.capability_count ?? 0,
                icon: 'ti ti-key',
                tone: 'violet' as const,
                hint: 'What organizations can grant their own roles',
            },
            {
                label: 'Ending within 30 days',
                value: optional.reduce((sum, module) => sum + (module.expiring_soon ?? 0), 0),
                icon: 'ti ti-clock-exclamation',
                tone: 'amber' as const,
                hint: 'Subscriptions to chase',
            },
        ];
    }, [catalogue]);

    return (
        <>
            <PageHeader
                title="Modules"
                subtitle="Give an organization the modules it has paid for. Each one carries the permissions that organization can then grant its own roles."
                icon="ti ti-puzzle"
                tone="indigo"
                crumbs={[{ label: 'Modules' }]}
            />

            <StatTiles tiles={tiles} loading={catalogueLoading} />

            <Tabs<View>
                label="Module views"
                value={view}
                onChange={(next) => {
                    // The chosen organization survives a trip to the
                    // catalogue and back — losing it would mean finding it
                    // again every time somebody checks what a module grants.
                    const params = new URLSearchParams(searchParams);

                    if (next === 'assign') {
                        params.delete('view');
                    } else {
                        params.set('view', next);
                    }

                    setSearchParams(params, { replace: true });
                }}
                tabs={[
                    {
                        value: 'assign',
                        label: 'Assign to organizations',
                        icon: 'ti ti-building-store',
                    },
                    { value: 'catalogue', label: 'Catalogue', icon: 'ti ti-list-details' },
                ]}
            />

            {view === 'assign' ? <AssignView /> : <CatalogueView />}
        </>
    );
}

/**
 * Pick an organization on the left, give it modules on the right.
 *
 * One screen for one job. The alternative — a list that sends you somewhere
 * else to do the actual work — makes assigning five organizations five round
 * trips, and the rail keeps the rest of the book in view while you decide.
 *
 * The selection rides the query string so a particular organization can be
 * linked to, and so a refresh lands back on it.
 */
function AssignView() {
    const [searchParams, setSearchParams] = useSearchParams();
    const selected = searchParams.get('org');

    const [search, setSearch] = useState('');

    /*
     * The rail asks for a generous page rather than paging: it is a picker,
     * and paging a picker means hunting. Search narrows it server-side, and
     * the footer says plainly when there are more than fit.
     */
    const { data: page, isLoading } = useModuleOrganizations({
        page: 1,
        per_page: 100,
        search: search || undefined,
        sort: 'organization_name',
        direction: 'asc',
    });

    const organizations = page?.data ?? [];
    const total = page?.meta.total ?? 0;

    function select(uuid: string) {
        const next = new URLSearchParams(searchParams);
        next.set('org', uuid);
        setSearchParams(next, { replace: true });
    }

    const current = organizations.find((organization) => organization.uuid === selected);

    return (
        <div className="ma-split">
            <aside className="ma-rail">
                <div className="ma-rail-search">
                    <i className="ti ti-search" aria-hidden="true" />
                    {/*
                     * Deliberately no `form-control` class. The theme styles
                     * that with `.app-content .form-control`, whose padding
                     * outranks anything a single-class rule here can say —
                     * which is exactly how the icon ended up sitting on top
                     * of the placeholder. The table's search input does the
                     * same, and looks right for the same reason.
                     */}
                    <input
                        type="search"
                        placeholder="Search organizations…"
                        aria-label="Search organizations"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>

                <div className="ma-rail-list">
                    {isLoading && <p className="ma-rail-empty">Loading…</p>}

                    {!isLoading && organizations.length === 0 && (
                        <p className="ma-rail-empty">No organizations match that.</p>
                    )}

                    {organizations.map((organization) => (
                        <button
                            type="button"
                            key={organization.uuid}
                            className={`ma-rail-item${
                                organization.uuid === selected ? ' is-active' : ''
                            }`}
                            onClick={() => select(organization.uuid)}
                        >
                            <span className="ma-rail-name">{organization.name}</span>

                            <span className="ma-rail-sub">
                                {organization.modules === 0
                                    ? 'Core only'
                                    : `${organization.modules} module${
                                          organization.modules === 1 ? '' : 's'
                                      }`}
                                {organization.expiring_soon > 0 && (
                                    <i
                                        className="ti ti-clock-exclamation ma-rail-warn"
                                        title={`${organization.expiring_soon} ending soon`}
                                    />
                                )}
                            </span>
                        </button>
                    ))}
                </div>

                {total > organizations.length && (
                    <p className="ma-rail-more">
                        Showing {organizations.length} of {total}. Search to narrow it.
                    </p>
                )}
            </aside>

            <div className="ma-stage">
                {selected ? (
                    <ModuleAssignPanel
                        key={selected}
                        uuid={selected}
                        heading={
                            <h5>
                                {current?.name ?? 'Organization'}
                                {current?.type && (
                                    <span className="ma-head-type">{current.type}</span>
                                )}
                            </h5>
                        }
                    />
                ) : (
                    <div className="ma-blank">
                        <i className="ti ti-arrow-left" aria-hidden="true" />
                        <b>Pick an organization</b>
                        <span>
                            Choose one on the left to see what it has been sold, and to give it
                            more.
                        </span>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * What the software can be sold as, and what each one grants.
 *
 * A table rather than a grid of cards: the catalogue grows, and at forty
 * rows cards cannot be sorted, compared or scanned. The capability list that
 * would make each card tall lives in a dialog, read on demand.
 */
function CatalogueView() {
    const { data, isLoading } = useModuleCatalogue();

    const [filters, setFilters] = useState<Record<string, string>>({});
    const [viewing, setViewing] = useState<CatalogueModule | null>(null);

    const rows = useMemo(() => {
        return (data?.modules ?? []).filter((module) => {
            if (filters.group && module.group !== filters.group) {
                return false;
            }

            if (filters.type === 'core' && !module.is_core) {
                return false;
            }

            if (filters.type === 'optional' && module.is_core) {
                return false;
            }

            return true;
        });
    }, [data, filters]);

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'group',
                label: 'Group',
                anyLabel: 'Any group',
                value: filters.group ?? '',
                options: (data?.groups ?? []).map((key) => ({
                    value: key,
                    label: GROUP_LABELS[key] ?? key,
                })),
            },
            {
                kind: 'select',
                name: 'type',
                label: 'Type',
                anyLabel: 'Any type',
                value: filters.type ?? '',
                options: [
                    { value: 'optional', label: 'Sold separately' },
                    { value: 'core', label: 'Included with every organization' },
                ],
            },
        ],
        [data, filters],
    );

    const columns = useMemo(() => {
        const column = createColumnHelper<CatalogueModule>();

        return [
            column.accessor('name', {
                header: 'Module',
                meta: { label: 'Module' },
                cell: (info) => {
                    const module = info.row.original;

                    return (
                        <div className="cat-cell">
                            <span className="cat-icon" aria-hidden="true">
                                <i className={module.icon ?? 'ti ti-puzzle'} />
                            </span>

                            <div>
                                <b>{module.name}</b>
                                <span>{module.description}</span>
                            </div>
                        </div>
                    );
                },
            }),

            column.accessor('group', {
                header: 'Group',
                meta: { label: 'Group' },
                cell: (info) => GROUP_LABELS[info.getValue()] ?? info.getValue(),
            }),

            column.accessor('is_core', {
                header: 'Type',
                meta: { label: 'Type' },
                cell: (info) =>
                    info.getValue() ? (
                        <span className="cat-tag is-core">
                            <i className="ti ti-lock" />
                            Included
                        </span>
                    ) : (
                        <span className="cat-tag">Sold separately</span>
                    ),
            }),

            column.accessor('organizations', {
                header: 'Using it',
                meta: { label: 'Using it' },
                cell: (info) =>
                    /*
                     * Core modules have no rows to count — every organization
                     * has them by virtue of being one — so a zero here would
                     * read as "nobody", the opposite of the truth.
                     */
                    info.getValue() === null ? (
                        <span className="text-muted">Everyone</span>
                    ) : (
                        <b className="cat-count">{info.getValue()}</b>
                    ),
            }),

            column.accessor('expiring_soon', {
                header: 'Ending soon',
                meta: { label: 'Ending soon' },
                cell: (info) => {
                    const count = info.getValue();

                    // Only worth ink when there is something to chase.
                    if (!count) {
                        return <span className="text-muted">—</span>;
                    }

                    return (
                        <span className="cat-tag is-warn">
                            <i className="ti ti-clock-exclamation" />
                            {count}
                        </span>
                    );
                },
            }),

            column.display({
                id: 'capabilities',
                header: 'Permissions',
                meta: { label: 'Permissions' },
                cell: (info) => (
                    <button
                        type="button"
                        className="cat-caps-btn"
                        onClick={() => setViewing(info.row.original)}
                    >
                        {info.row.original.capabilities.length}
                        <i className="ti ti-arrow-up-right" />
                    </button>
                ),
            }),
        ];
    }, []);

    const filtered = Object.keys(filters).length > 0;

    return (
        <>
            <FilterPanel
                fields={filterFields}
                onChange={(name, value) =>
                    setFilters((current) => {
                        const next = { ...current };

                        if (value === '') {
                            delete next[name];
                        } else {
                            next[name] = value;
                        }

                        return next;
                    })
                }
                onClear={() => setFilters({})}
            />

            <Card>
                <DataTable
                    data={rows}
                    columns={columns}
                    loading={isLoading}
                    pageSize={25}
                    searchPlaceholder="Search modules by name…"
                    emptyIcon="ti ti-puzzle"
                    emptyTone="indigo"
                    emptyTitle={filtered ? 'No modules match these filters' : 'No modules yet'}
                    emptyDescription={
                        filtered
                            ? 'Try widening or clearing them.'
                            : 'Run php artisan modules:sync to build the catalogue.'
                    }
                />
            </Card>

            <Modal
                open={viewing !== null}
                onClose={() => setViewing(null)}
                title={viewing?.name ?? ''}
                subtitle="An organization holding this module can grant these to its own roles."
                size="md"
                icon={<i className={viewing?.icon ?? 'ti ti-puzzle'} />}
            >
                <ul className="cat-caps">
                    {(viewing?.capabilities ?? []).map((capability) => (
                        <li key={capability.key}>
                            <span>{capability.name}</span>
                            <code>{capability.key}</code>
                        </li>
                    ))}
                </ul>
            </Modal>
        </>
    );
}
