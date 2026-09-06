import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { ExpiryBadge, StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { useFieldColumns } from '@/core/field-settings/useFieldColumns';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { locationsHooks, useLocationFields } from '../api';
import type { Location } from '../types';

export default function LocationListPage() {
    const confirm = useConfirm();
    const navigate = useNavigate();

    // Only the owner may change the network's shape; staff can look.
    const { can } = useTenantAuth();
    /*
     * The capability, not the role.
     *
     * This asked whether the signed-in person was the OWNER, while the route
     * behind it asks for branches.create / .edit / .delete. A branch admin holds that
     * capability and was still shown a screen with no way to act on it — the
     * server would have allowed the write the button was never offered for.
     *
     * The rule the navigation already follows: every control names the
     * capability its endpoint demands, so the two cannot disagree.
     */
    const canManage = can('branches.create') || can('branches.edit');

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });
    const {
        data: page,
        isLoading,
        isFetching,
        isError,
        refetch,
    } = locationsHooks.useTable(table.params);

    const rows = page?.data ?? [];
    const remove = locationsHooks.useRemove();

    // Types come from the server so the reserved CLINIC value never has to be
    // spelled out here.
    const { data: fields } = useLocationFields();

    const typeLabels = useMemo(() => {
        const options = fields?.find((field) => field.key === 'type')?.options ?? [];

        return Object.fromEntries(options.map((option) => [option.value, option.label]));
    }, [fields]);

    async function handleDelete(location: Location) {
        const confirmed = await confirm({
            title: 'Delete location?',
            message: `“${location.name}” will be removed. Its code becomes available again.`,
            confirmLabel: 'Delete',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(location.id);
        }
    }

    // Only the cells that are special here; everything else falls back to
    // the shared rendering.
    const renderers = useMemo(
        () => ({
            name: (row: Location) => <span className="fw-semibold">{row.name}</span>,
            code: (row: Location) => <code className="fs-13">{row.code}</code>,
            type: (row: Location) => typeLabels[row.type] ?? row.type,
            is_active: (row: Location) => <StatusBadge active={row.is_active} />,
            // A licence may be expired — a compliance record has to be able
            // to say so. The badge is what makes it impossible to miss.
            drug_license_expiry_date: (row: Location) => (
                <ExpiryBadge
                    date={row.drug_license_expiry_date}
                    expired={row.has_expired_licence}
                />
            ),
        }),
        [typeLabels],
    );

    const actions = useMemo(
        () =>
            canManage
                ? (row: Location) => (
                      <RowActions
                          editTo={`/locations/${row.id}/edit`}
                          onDelete={() => handleDelete(row)}
                          editTitle="Edit Location"
                          deleteTitle="Delete Location"
                      />
                  )
                : undefined,
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canManage],
    );

    const columns = useFieldColumns<Location>({
        fields,
        pageIndex: table.pageIndex,
        pageSize: table.pageSize,
        renderers,
        actions,
    });

    return (
        <>
            <PageHeader
                title="Locations"
                subtitle="The stores, warehouses and sites your organization operates."
                icon="ti ti-building-store"
                tone="indigo"
                crumbs={[{ label: 'Locations' }]}
                actions={
                    canManage ? (
                        <Button icon="ti ti-plus" onClick={() => navigate('/locations/create')}>
                            Add Location
                        </Button>
                    ) : undefined
                }
            />

            <Card>
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
                    searchPlaceholder="Search by name, code or city…"
                    emptyIcon="ti ti-building-store"
                    emptyTone="indigo"
                    emptyTitle="No locations yet"
                    emptyDescription="Add the first store, warehouse or site your organization operates."
                    emptyAction={
                        canManage ? (
                            <Button size="sm" onClick={() => navigate('/locations/create')}>
                                Add Location
                            </Button>
                        ) : undefined
                    }
                />
            </Card>
        </>
    );
}
