import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
import { useFieldColumns } from '@/core/field-settings/useFieldColumns';
import { useEntityLabel } from '@/core/field-settings/api';
import {
    medicinesHooks,
    useMedicineFields,
    useRemoveMedicine,
    useRemovedMedicines,
    useRestoreMedicine,
} from '../api';
import { ReasonDialog } from '../components/ReasonDialog';
import type { Medicine } from '../types';

type View = 'catalogue' | 'removed';

/**
 * The medicine catalogue.
 *
 * Two readings of it: what is in use, and — for whoever may bring things
 * back — what has been removed, with who removed it and why.
 */
export default function MedicineListPage() {
    const navigate = useNavigate();
    const label = useEntityLabel('medicine');

    // Every control names the capability its endpoint demands.
    const { can } = useTenantAuth();
    const canManage = can('medicines.manage');
    const canRestore = can('pharmacy.restore');

    const [view, setView] = useState<View>('catalogue');
    const [filters, setFilters] = useState<Record<string, string>>({});

    const table = useServerTable({ pageSize: 25, sort: 'generic_name', direction: 'asc' });
    const removedTable = useServerTable({ pageSize: 25, sort: 'deleted_at', direction: 'desc' });

    const params: TableQueryParams = useMemo(
        () => ({ ...table.params, ...filters }),
        [table.params, filters],
    );

    const { data: page, isLoading, isFetching, isError, refetch } =
        medicinesHooks.useTable(params);
    const { data: fields } = useMedicineFields();

    const removed = useRemovedMedicines(removedTable.params, canRestore && view === 'removed');

    const remove = useRemoveMedicine();
    const restore = useRestoreMedicine();

    // The medicine a reason is being asked for, and for what.
    const [asking, setAsking] = useState<{ medicine: Medicine; action: 'remove' | 'restore' }>();
    const [refusal, setRefusal] = useState<string | null>(null);

    function ask(medicine: Medicine, action: 'remove' | 'restore') {
        setRefusal(null);
        setAsking({ medicine, action });
    }

    async function answer(reason: string) {
        if (!asking) {
            return;
        }

        const mutation = asking.action === 'remove' ? remove : restore;

        try {
            await mutation.mutateAsync({ id: asking.medicine.id, reason });
            setAsking(undefined);
        } catch (error) {
            // A restore is refused when a live medicine has taken its place;
            // the server's sentence says which one.
            setRefusal(resolveErrorMessage(error));
        }
    }

    /** The label an organization gave an option, not its stored value. */
    const optionLabel = useMemo(() => {
        const lookup = new Map<string, Map<string, string>>();

        (fields ?? []).forEach((field) => {
            lookup.set(
                field.key,
                new Map((field.options ?? []).map((option) => [option.value, option.label])),
            );
        });

        return (key: string, value: string | null) =>
            value ? (lookup.get(key)?.get(value) ?? value) : null;
    }, [fields]);

    const renderers = useMemo(
        () => ({
            generic_name: (row: Medicine) => (
                <div>
                    <b className="d-block">{row.generic_name}</b>
                    {row.medicine_code && <span className="dr-sub">{row.medicine_code}</span>}
                </div>
            ),
            brand_name: (row: Medicine) => orDash(row.brand_name),
            dosage_form: (row: Medicine) => optionLabel('dosage_form', row.dosage_form),
            route: (row: Medicine) => orDash(optionLabel('route', row.route)),
            base_unit: (row: Medicine) => optionLabel('base_unit', row.base_unit),
            schedule: (row: Medicine) =>
                row.schedule ? <code className="fs-13">{row.schedule}</code> : orDash(null),
            is_active: (row: Medicine) => <StatusBadge active={row.is_active} />,
        }),
        [optionLabel],
    );

    const actions = useMemo(
        () =>
            canManage
                ? (row: Medicine) => (
                      <RowActions
                          editTo={`/medicines/${row.id}/edit`}
                          onDelete={() => ask(row, 'remove')}
                          editTitle="Edit medicine"
                          deleteTitle="Remove medicine"
                      />
                  )
                : undefined,
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canManage],
    );

    const columns = useFieldColumns<Medicine>({
        fields,
        pageIndex: table.pageIndex,
        pageSize: table.pageSize,
        renderers,
        actions,
    });

    const removedColumns = useMemo(() => {
        const column = createColumnHelper<Medicine>();

        return [
            column.display({
                id: 'display_name',
                header: 'Medicine',
                meta: { label: 'Medicine' },
                cell: (info) => <b>{info.row.original.display_name}</b>,
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
        ] as ColumnDef<Medicine, unknown>[];
    }, []);

    const formOptions = (key: string) =>
        (fields?.find((field) => field.key === key)?.options ?? []).map((option) => ({
            value: option.value,
            label: option.label,
        }));

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'dosage_form',
                label: 'Form',
                anyLabel: 'Any form',
                value: filters.dosage_form ?? '',
                options: formOptions('dosage_form'),
            },
            {
                kind: 'select',
                name: 'schedule',
                label: 'Schedule',
                anyLabel: 'Any schedule',
                value: filters.schedule ?? '',
                options: formOptions('schedule'),
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [fields, filters],
    );

    const filtered = Object.keys(filters).length > 0;
    const plural = label.plural || 'Medicines';
    const singular = label.singular || 'Medicine';

    return (
        <>
            <PageHeader
                title={plural}
                subtitle="What your doctors prescribe from and your pharmacy stocks."
                icon="ti ti-pill"
                tone="emerald"
                crumbs={[{ label: plural }]}
                actions={
                    canManage ? (
                        <Button icon="ti ti-plus" onClick={() => navigate('/medicines/create')}>
                            Add {singular}
                        </Button>
                    ) : undefined
                }
            />

            {canRestore && (
                <Tabs<View>
                    label="Medicine lists"
                    value={view}
                    onChange={setView}
                    tabs={[
                        { value: 'catalogue', label: 'Catalogue', icon: 'ti ti-list' },
                        { value: 'removed', label: 'Removed', icon: 'ti ti-trash' },
                    ]}
                />
            )}

            {view === 'catalogue' ? (
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
                            searchPlaceholder="Search by generic, brand, code, strength or maker…"
                            emptyIcon={filtered ? 'ti ti-filter-off' : 'ti ti-pill'}
                            emptyTone="emerald"
                            emptyTitle={
                                filtered ? 'No medicines match these filters' : 'No medicines yet'
                            }
                            emptyDescription={
                                filtered
                                    ? 'Try widening or clearing them.'
                                    : 'Add the medicines your doctors prescribe. Stock comes later, with the pharmacy.'
                            }
                            emptyAction={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => navigate('/medicines/create')}
                                    >
                                        Add {singular}
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
                        searchPlaceholder="Search removed medicines…"
                        emptyIcon="ti ti-trash-off"
                        emptyTone="emerald"
                        emptyTitle="Nothing has been removed"
                        emptyDescription="A removed medicine appears here, with who removed it and why, until it is restored."
                    />
                </Card>
            )}

            <ReasonDialog
                open={asking !== undefined}
                title={
                    asking?.action === 'restore'
                        ? `Restore ${asking.medicine.display_name}?`
                        : `Remove ${asking?.medicine.display_name ?? ''}?`
                }
                subtitle={
                    asking?.action === 'restore'
                        ? 'It returns to the catalogue exactly as it was.'
                        : 'It leaves the catalogue and every search. Anything already written against it keeps its name.'
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
