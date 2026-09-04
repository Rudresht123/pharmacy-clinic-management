import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useServerTable, type TableQueryParams } from '@/shared/hooks/useServerTable';
import { orDash } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useFieldColumns } from '@/core/field-settings/useFieldColumns';
import { useEntityLabel } from '@/core/field-settings/api';
import { locationsHooks } from '@/core/locations/api';
import { doctorsHooks, useDoctorFields } from '../api';
import type { Doctor } from '../types';

/**
 * The doctors an organization's patients are seen by.
 *
 * The branch filter asks a question a doctor row cannot answer on its own —
 * a doctor has no branch, only sittings — so the server resolves it through
 * their schedules.
 */
export default function DoctorListPage() {
    const confirm = useConfirm();
    const navigate = useNavigate();

    const label = useEntityLabel('doctor');

    // Anyone signed in may look; only the owner may change.
    const { user } = useTenantAuth();
    const canManage = user?.role === 'owner';

    const [filters, setFilters] = useState<Record<string, string>>({});

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });

    const params: TableQueryParams = useMemo(
        () => ({ ...table.params, ...filters }),
        [table.params, filters],
    );

    const { data: page, isLoading, isFetching, isError, refetch } = doctorsHooks.useTable(params);
    const { data: fields } = useDoctorFields();
    const { data: branches } = locationsHooks.useList({ all: 1 });

    const remove = doctorsHooks.useRemove();

    async function handleDelete(doctor: Doctor) {
        const confirmed = await confirm({
            title: `Remove ${doctor.name}?`,
            message: 'Their sittings go with them. Past appointments are untouched.',
            confirmLabel: 'Remove',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(doctor.id);
        }
    }

    // Only the cells that are special here; the rest falls back to the
    // shared rendering driven by the field registry.
    const renderers = useMemo(
        () => ({
            name: (row: Doctor) => (
                <div>
                    <b className="d-block">{row.name}</b>
                    {row.qualification && <span className="dr-sub">{row.qualification}</span>}
                </div>
            ),
            code: (row: Doctor) =>
                row.code ? <code className="fs-13">{row.code}</code> : orDash(null),
            is_active: (row: Doctor) => <StatusBadge active={row.is_active} />,
            default_consultation_fee: (row: Doctor) =>
                row.default_consultation_fee ? `₹${row.default_consultation_fee}` : orDash(null),
        }),
        [],
    );

    const actions = useMemo(
        () =>
            canManage
                ? (row: Doctor) => (
                      <RowActions
                          editTo={`/doctors/${row.id}/edit`}
                          onDelete={() => handleDelete(row)}
                          editTitle="Edit doctor"
                          deleteTitle="Remove doctor"
                      />
                  )
                : undefined,
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canManage],
    );

    const columns = useFieldColumns<Doctor>({
        fields,
        pageIndex: table.pageIndex,
        pageSize: table.pageSize,
        renderers,
        actions,
    });

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'location_id',
                label: 'Sits at',
                anyLabel: 'Any branch',
                value: filters.location_id ?? '',
                // Answered through the schedules — a doctor has no branch of
                // their own, so this is the only place the question lands.
                options: (branches ?? []).map((branch) => ({
                    value: String(branch.id),
                    label: branch.name,
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
        [branches, filters],
    );

    const filtered = Object.keys(filters).length > 0;

    return (
        <>
            <PageHeader
                title={label.plural || 'Doctors'}
                subtitle="Who your patients are seen by, and where each of them sits."
                icon="ti ti-stethoscope"
                tone="violet"
                crumbs={[{ label: label.plural || 'Doctors' }]}
                actions={
                    canManage ? (
                        <Button icon="ti ti-plus" onClick={() => navigate('/doctors/create')}>
                            Add {label.singular || 'Doctor'}
                        </Button>
                    ) : undefined
                }
            />

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
                    searchPlaceholder="Search by name, code, specialisation or phone…"
                    emptyIcon={filtered ? 'ti ti-filter-off' : 'ti ti-stethoscope'}
                    emptyTone="violet"
                    emptyTitle={filtered ? 'No doctors match these filters' : 'No doctors yet'}
                    emptyDescription={
                        filtered
                            ? 'Try widening or clearing them.'
                            : 'Add the doctors your patients are seen by, then give each one their timings.'
                    }
                    emptyAction={
                        canManage ? (
                            <Button size="sm" onClick={() => navigate('/doctors/create')}>
                                Add {label.singular || 'Doctor'}
                            </Button>
                        ) : undefined
                    }
                />
            </Card>
        </>
    );
}
