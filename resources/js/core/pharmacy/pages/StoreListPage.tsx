import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { Tabs } from '@/shared/components/ui/Tabs';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { useServerTable, type TableQueryParams } from '@/shared/hooks/useServerTable';
import { formatDateTime, orDash } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import {
    storesHooks,
    useRemoveStore,
    useRemovedStores,
    useRestoreStore,
    useStoreFormOptions,
} from '../api';
import { STORE_TYPE_LABELS, type PharmacyStore } from '../types';

type View = 'stores' | 'removed';

/**
 * The pharmacy stores at the branches this person works at.
 *
 * Every branch has one default store — the one a doctor sees stock from —
 * and the list says which. Stock arrives in the next phase; for now a store
 * is where it will be kept, and what it will keep.
 */
export default function StoreListPage() {
    const navigate = useNavigate();

    const { can } = useTenantAuth();
    const canManage = can('pharmacy.stores');
    const canRestore = can('pharmacy.restore');

    const [view, setView] = useState<View>('stores');
    const [filters, setFilters] = useState<Record<string, string>>({});

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });
    const removedTable = useServerTable({ pageSize: 25, sort: 'deleted_at', direction: 'desc' });

    const params: TableQueryParams = useMemo(
        () => ({ ...table.params, ...filters }),
        [table.params, filters],
    );

    const { data: page, isLoading, isFetching, isError, refetch } = storesHooks.useTable(params);
    const removed = useRemovedStores(removedTable.params, canRestore && view === 'removed');

    // The branch filter reads the caller's branches from the managers' form
    // endpoint; everybody else filters by type and status.
    const { data: options } = useStoreFormOptions(canManage);

    const remove = useRemoveStore();
    const restore = useRestoreStore();

    const [asking, setAsking] = useState<{ store: PharmacyStore; action: 'remove' | 'restore' }>();
    const [refusal, setRefusal] = useState<string | null>(null);

    function ask(store: PharmacyStore, action: 'remove' | 'restore') {
        setRefusal(null);
        setAsking({ store, action });
    }

    async function answer(reason: string) {
        if (!asking) {
            return;
        }

        const mutation = asking.action === 'remove' ? remove : restore;

        try {
            await mutation.mutateAsync({ id: asking.store.id, reason });
            setAsking(undefined);
        } catch (error) {
            setRefusal(resolveErrorMessage(error));
        }
    }

    const columns = useMemo(() => {
        const column = createColumnHelper<PharmacyStore>();

        return [
            column.display({
                id: 'name',
                header: 'Store',
                meta: { label: 'Store' },
                cell: (info) => (
                    <div>
                        <b className="d-block">
                            {info.row.original.name}
                            {info.row.original.is_default && (
                                <span className="badge bg-primary-subtle text-primary ms-2">
                                    Default
                                </span>
                            )}
                        </b>
                        <span className="dr-sub">{info.row.original.code}</span>
                    </div>
                ),
            }),
            column.display({
                id: 'location_name',
                header: 'Branch',
                meta: { label: 'Branch' },
                cell: (info) => orDash(info.row.original.location_name ?? null),
            }),
            column.display({
                id: 'store_type',
                header: 'Type',
                meta: { label: 'Type' },
                cell: (info) =>
                    STORE_TYPE_LABELS[info.row.original.store_type] ?? info.row.original.store_type,
            }),
            column.display({
                id: 'pharmacist_name',
                header: 'Pharmacist',
                meta: { label: 'Pharmacist' },
                cell: (info) => orDash(info.row.original.pharmacist_name ?? null),
            }),
            column.display({
                id: 'medicines_count',
                header: 'Medicines',
                meta: { label: 'Medicines' },
                cell: (info) => info.row.original.medicines_count ?? 0,
            }),
            column.display({
                id: 'is_active',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => <StatusBadge active={info.row.original.is_active} />,
            }),
            column.display({
                id: 'actions',
                header: 'Action',
                size: 130,
                cell: (info) => (
                    <div className="d-flex align-items-center justify-content-end gap-1">
                        <Link
                            className="btn btn-sm btn-light"
                            to={`/pharmacy/stores/${info.row.original.id}/medicines`}
                            title="What this store stocks"
                            aria-label="What this store stocks"
                        >
                            <i className="ti ti-pill" aria-hidden="true" />
                        </Link>

                        {canManage && (
                            <RowActions
                                editTo={`/pharmacy/stores/${info.row.original.id}/edit`}
                                onDelete={() => ask(info.row.original, 'remove')}
                                editTitle="Edit store"
                                deleteTitle="Remove store"
                            />
                        )}
                    </div>
                ),
            }),
        ] as ColumnDef<PharmacyStore, unknown>[];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [canManage]);

    const removedColumns = useMemo(() => {
        const column = createColumnHelper<PharmacyStore>();

        return [
            column.display({
                id: 'name',
                header: 'Store',
                meta: { label: 'Store' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.name}</b>
                        <span className="dr-sub">
                            {info.row.original.code} · {info.row.original.location_name ?? '—'}
                        </span>
                    </div>
                ),
            }),
            column.display({
                id: 'deletion_reason',
                header: 'Why it was removed',
                meta: { label: 'Why it was removed' },
                cell: (info) => orDash(info.row.original.deletion_reason ?? null),
            }),
            column.display({
                id: 'deleted_by_name',
                header: 'Removed by',
                meta: { label: 'Removed by' },
                cell: (info) => orDash(info.row.original.deleted_by_name ?? null),
            }),
            column.display({
                id: 'deleted_at',
                header: 'Removed on',
                meta: { label: 'Removed on' },
                cell: (info) => formatDateTime(info.row.original.deleted_at),
            }),
            column.display({
                id: 'actions',
                header: 'Action',
                size: 110,
                cell: (info) => (
                    <Button
                        size="sm"
                        variant="light"
                        icon="ti ti-restore"
                        onClick={() => ask(info.row.original, 'restore')}
                    >
                        Restore
                    </Button>
                ),
            }),
        ] as ColumnDef<PharmacyStore, unknown>[];
    }, []);

    const filterFields = useMemo<FilterField[]>(
        () => [
            ...((options?.branches.length ?? 0) > 1
                ? [
                      {
                          kind: 'select' as const,
                          name: 'location_id',
                          label: 'Branch',
                          anyLabel: 'Any branch',
                          value: filters.location_id ?? '',
                          options: (options?.branches ?? []).map((branch) => ({
                              value: String(branch.id),
                              label: branch.name,
                          })),
                      },
                  ]
                : []),
            {
                kind: 'select',
                name: 'store_type',
                label: 'Type',
                anyLabel: 'Any type',
                value: filters.store_type ?? '',
                options: Object.entries(STORE_TYPE_LABELS).map(([value, label]) => ({
                    value,
                    label,
                })),
            },
            {
                kind: 'select',
                name: 'status',
                label: 'Status',
                anyLabel: 'Any status',
                value: filters.status ?? '',
                options: [
                    { value: 'active', label: 'Active' },
                    { value: 'inactive', label: 'Inactive' },
                ],
            },
        ],
        [options, filters],
    );

    const filtered = Object.keys(filters).length > 0;

    return (
        <>
            <PageHeader
                title="Pharmacy Stores"
                subtitle="Where stock is kept and dispensed, branch by branch."
                icon="ti ti-building-warehouse"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Stores' }]}
                actions={
                    canManage ? (
                        <Button icon="ti ti-plus" onClick={() => navigate('/pharmacy/stores/create')}>
                            Add Store
                        </Button>
                    ) : undefined
                }
            />

            {canRestore && (
                <Tabs<View>
                    label="Store lists"
                    value={view}
                    onChange={setView}
                    tabs={[
                        { value: 'stores', label: 'Stores', icon: 'ti ti-building-warehouse' },
                        { value: 'removed', label: 'Removed', icon: 'ti ti-trash' },
                    ]}
                />
            )}

            {view === 'stores' ? (
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
                            data={page?.data ?? []}
                            columns={columns}
                            loading={isLoading}
                            fetching={isFetching}
                            error={isError}
                            onRetry={refetch}
                            server={{
                                ...table,
                                total: page?.meta.total ?? 0,
                                pageCount: page?.meta.last_page ?? 1,
                            }}
                            searchPlaceholder="Search by store name or code…"
                            emptyIcon={filtered ? 'ti ti-filter-off' : 'ti ti-building-warehouse'}
                            emptyTone="teal"
                            emptyTitle={filtered ? 'No stores match these filters' : 'No stores yet'}
                            emptyDescription={
                                filtered
                                    ? 'Try widening or clearing them.'
                                    : 'Add the counter or store room each branch dispenses from. The first one at a branch becomes its default.'
                            }
                            emptyAction={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => navigate('/pharmacy/stores/create')}
                                    >
                                        Add Store
                                    </Button>
                                ) : undefined
                            }
                        />
                    </Card>
                </>
            ) : (
                <Card>
                    <DataTable
                        data={removed.data?.data ?? []}
                        columns={removedColumns}
                        loading={removed.isLoading}
                        fetching={removed.isFetching}
                        error={removed.isError}
                        onRetry={removed.refetch}
                        server={{
                            ...removedTable,
                            total: removed.data?.meta.total ?? 0,
                            pageCount: removed.data?.meta.last_page ?? 1,
                        }}
                        searchPlaceholder="Search removed stores…"
                        emptyIcon="ti ti-trash-off"
                        emptyTone="teal"
                        emptyTitle="Nothing has been removed"
                        emptyDescription="A removed store appears here, with who removed it and why, until it is restored."
                    />
                </Card>
            )}

            <ReasonDialog
                open={asking !== undefined}
                title={
                    asking?.action === 'restore'
                        ? `Restore ${asking.store.name}?`
                        : `Remove ${asking?.store.name ?? ''}?`
                }
                subtitle={
                    asking?.action === 'restore'
                        ? 'It comes back with what it stocked. It becomes the default only if its branch has none.'
                        : asking?.store.is_default
                          ? 'This is its branch’s default store. The oldest active store left there becomes the default.'
                          : 'It leaves every working list. Its history stays.'
                }
                submitLabel={asking?.action === 'restore' ? 'Restore' : 'Remove'}
                danger={asking?.action === 'remove'}
                submitting={remove.isPending || restore.isPending}
                error={refusal}
                onClose={() => setAsking(undefined)}
                onSubmit={answer}
            />
        </>
    );
}
