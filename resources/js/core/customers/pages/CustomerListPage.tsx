import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { StatTiles } from '@/shared/components/ui/StatTiles';
import { FilterBar } from '@/shared/components/ui/FilterBar';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { orDash } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useFieldColumns } from '@/core/field-settings/useFieldColumns';
import { useEntityLabel } from '@/core/field-settings/api';
import { customersHooks, useCustomerFields, useCustomerStats } from '../api';
import type { Customer } from '../types';

export default function CustomerListPage() {
    const confirm = useConfirm();
    const navigate = useNavigate();

    // Anyone signed in may add or edit; only the owner may remove.
    // Pharmacies call them customers, clinics patients — one record either way.
    const label = useEntityLabel('customer');

    const { user } = useTenantAuth();
    const canRemove = user?.role === 'owner';

    const [status, setStatus] = useState('');
    const [locationId, setLocationId] = useState('');

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });

    const params = useMemo(
        () => ({
            ...table.params,
            ...(status ? { status } : {}),
            ...(locationId ? { registered_location_id: locationId } : {}),
        }),
        [table.params, status, locationId],
    );

    const { data: page, isLoading, isFetching, isError, refetch } = customersHooks.useTable(params);
    const { data: stats, isLoading: statsLoading } = useCustomerStats();
    const { data: fields } = useCustomerFields();

    const rows = page?.data ?? [];
    const remove = customersHooks.useRemove();

    // The branch list the filter offers comes from the same registry field
    // the form uses, so the two can never drift apart.
    const locationOptions = useMemo(
        () => fields?.find((field) => field.key === 'registered_location_id')?.options ?? [],
        [fields],
    );

    async function handleDelete(customer: Customer) {
        const confirmed = await confirm({
            title: `Remove this ${label.singular.toLowerCase()}?`,
            message: `“${customer.name}” will be removed. Their history stays on file.`,
            confirmLabel: 'Remove',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(customer.id);
        }
    }

    // Only the cells that are special to this screen; everything else falls
    // back to the shared rendering.
    const renderers = useMemo(
        () => ({
            name: (row: Customer) => <span className="fw-semibold">{row.name}</span>,
            phone: (row: Customer) =>
                // The counter's usual way in, so it reads as a handle.
                row.phone ? <code className="fs-13">{row.phone}</code> : orDash(null),
            is_active: (row: Customer) => <StatusBadge active={row.is_active} />,
            date_of_birth: (row: Customer) =>
                row.age !== null ? `${row.age} yrs` : orDash(row.date_of_birth),
            registered_location_id: (row: Customer) =>
                row.registered_location ? (
                    <span className="badge bg-light text-muted">{row.registered_location.name}</span>
                ) : (
                    orDash(null)
                ),
        }),
        [],
    );

    const actions = useMemo(
        () => (row: Customer) => (
            <RowActions
                editTo={`/customers/${row.id}/edit`}
                onDelete={canRemove ? () => handleDelete(row) : undefined}
                editTitle="Edit Customer"
                deleteTitle="Remove Customer"
            />
        ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canRemove],
    );

    const columns = useFieldColumns<Customer>({
        fields,
        pageIndex: table.pageIndex,
        pageSize: table.pageSize,
        renderers,
        actions,
    });

    const tiles = useMemo(() => {
        const branchSplit = (stats?.by_location ?? [])
            .slice()
            .sort((a, b) => b.total - a.total);

        const topBranch = branchSplit.find((entry) => entry.location_id !== null);

        return [
            {
                label: `Total ${label.plural.toLowerCase()}`,
                value: stats?.total ?? 0,
                icon: 'ti ti-users',
                tone: 'sky' as const,
                hint: 'Across every branch',
            },
            {
                label: 'Active',
                value: stats?.active ?? 0,
                icon: 'ti ti-user-check',
                tone: 'emerald' as const,
                // Doubles as a filter, which is why the tile is clickable.
                onClick: () => setStatus(status === 'active' ? '' : 'active'),
                active: status === 'active',
            },
            {
                label: 'Inactive',
                value: stats?.inactive ?? 0,
                icon: 'ti ti-user-off',
                tone: 'rose' as const,
                onClick: () => setStatus(status === 'inactive' ? '' : 'inactive'),
                active: status === 'inactive',
            },
            {
                label: 'Added in 30 days',
                value: stats?.recent ?? 0,
                icon: 'ti ti-user-plus',
                tone: 'violet' as const,
                hint: topBranch ? `Most from ${topBranch.label}` : undefined,
            },
        ];
    }, [stats, status]);

    return (
        <>
            <PageHeader
                title={label.plural}
                subtitle={`One record per person, shared by every store in your organization.`}
                icon="ti ti-users"
                tone="sky"
                crumbs={[{ label: label.plural }]}
                actions={
                    <Button icon="ti ti-plus" onClick={() => navigate('/customers/create')}>
                        Add {label.singular}
                    </Button>
                }
            />

            <StatTiles tiles={tiles} loading={statsLoading} />

            <Card>
                <FilterBar
                    filters={[
                        {
                            name: 'registered_location_id',
                            label: 'Registered at',
                            value: locationId,
                            anyLabel: 'Any branch',
                            options: locationOptions,
                            onChange: setLocationId,
                        },
                        {
                            name: 'status',
                            label: 'Status',
                            value: status,
                            anyLabel: 'Any status',
                            options: [
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ],
                            onChange: setStatus,
                        },
                    ]}
                    onClear={() => {
                        setStatus('');
                        setLocationId('');
                    }}
                />

                <DataTable
                    data={rows}
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
                    searchPlaceholder="Search by name, phone, email or city…"
                    emptyIcon="ti ti-users"
                    emptyTone="sky"
                    emptyTitle={`No ${label.plural.toLowerCase()} yet`}
                    emptyDescription="Add the first person your organization serves."
                    emptyAction={
                        <Button size="sm" onClick={() => navigate('/customers/create')}>
                            Add {label.singular}
                        </Button>
                    }
                />
            </Card>
        </>
    );
}
