import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { orDash } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useFieldColumns } from '@/core/field-settings/useFieldColumns';
import type { ConfigurableField } from '@/core/field-settings/types';
import type { TableQueryParams, useServerTable } from '@/shared/hooks/useServerTable';
import { customersHooks } from '../api';
import type { Customer, CustomerStats } from '../types';

interface CustomerTableProps {
    table: ReturnType<typeof useServerTable>;
    /** table.params plus whatever the filter bar adds. */
    params: TableQueryParams;
    fields?: ConfigurableField[];
    /** Age bands come from here, so the filter offers exactly what the chart plots. */
    stats?: CustomerStats;
    labels: { singular: string; plural: string };
    filters: Record<string, string>;
    onFilterChange: (name: string, value: string) => void;
    onClearFilters: () => void;
}

/**
 * Finding one person, which is a different job from understanding the whole
 * book of them — hence its own tab rather than a block under the charts.
 */
export function CustomerTable({
    table,
    params,
    fields,
    stats,
    labels,
    filters,
    onFilterChange,
    onClearFilters,
}: CustomerTableProps) {
    const confirm = useConfirm();
    const navigate = useNavigate();

    const { can } = useTenantAuth();
    /*
     * The capability, not the role.
     *
     * This asked whether the signed-in person was the OWNER, while the route
     * behind it asks for customers.delete. A branch admin holds that
     * capability and was still shown a screen with no way to act on it — the
     * server would have allowed the write the button was never offered for.
     *
     * The rule the navigation already follows: every control names the
     * capability its endpoint demands, so the two cannot disagree.
     */
    const canRemove = can('customers.delete');

    const { data: page, isLoading, isFetching, isError, refetch } = customersHooks.useTable(params);
    const remove = customersHooks.useRemove();

    const rows = page?.data ?? [];
    const filtered = Object.keys(filters).length > 0;

    /*
     * Every dropdown is built from the same schema the form renders from, so
     * a filter can never offer a branch or a gender the form does not — and
     * a field the organization switched off does not come back as a filter.
     */
    const filterFields = useMemo<FilterField[]>(() => {
        const byKey = new Map((fields ?? []).map((field) => [field.key, field]));
        const visible = (key: string) => {
            const field = byKey.get(key);

            return field && field.show_in_form !== false ? field : null;
        };

        const branch = visible('registered_location_id');
        const gender = visible('gender');
        const dob = visible('date_of_birth');
        const city = visible('city');

        const built: FilterField[] = [];

        if (branch) {
            built.push({
                kind: 'select',
                name: 'registered_location_id',
                label: branch.label,
                anyLabel: 'Any branch',
                options: branch.options ?? [],
                value: filters.registered_location_id ?? '',
            });
        }

        built.push({
            kind: 'select',
            name: 'status',
            label: 'Status',
            anyLabel: 'Any status',
            options: [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
            ],
            value: filters.status ?? '',
        });

        if (gender) {
            built.push({
                kind: 'select',
                name: 'gender',
                label: gender.label,
                anyLabel: `Any ${gender.label.toLowerCase()}`,
                options: gender.options ?? [],
                value: filters.gender ?? '',
            });
        }

        if (dob) {
            built.push({
                kind: 'select',
                name: 'age_band',
                label: 'Age',
                anyLabel: 'Any age',
                // The bands the server actually buckets by, so the filter and
                // the chart can never disagree about where 13 belongs.
                options: (stats?.by_age_band ?? []).map((band) => ({
                    value: band.key,
                    label: band.label,
                })),
                value: filters.age_band ?? '',
            });
        }

        if (city) {
            built.push({
                kind: 'text',
                name: 'city',
                label: city.label,
                // Free text rather than a dropdown: a town list is unbounded,
                // and a partial name should still find the rows.
                placeholder: city.placeholder ?? 'Type a town…',
                value: filters.city ?? '',
            });
        }

        built.push({
            kind: 'select',
            name: 'joined_within',
            label: 'Joined',
            anyLabel: 'Any time',
            options: [
                { value: '7', label: 'Last 7 days' },
                { value: '30', label: 'Last 30 days' },
                { value: '90', label: 'Last 3 months' },
                { value: '365', label: 'Last year' },
            ],
            value: filters.joined_within ?? '',
        });

        return built;
    }, [fields, stats, filters]);

    async function handleDelete(customer: Customer) {
        const confirmed = await confirm({
            title: `Remove this ${labels.singular.toLowerCase()}?`,
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
            /*
             * The number under the name rather than in a column of its own.
             * It is how somebody is told apart from the other Rahul Sharma,
             * which makes it part of identifying them — not a separate fact
             * competing for width on a narrow screen.
             */
            name: (row: Customer) => (
                <span className="pt-name">
                    <b>{row.name}</b>
                    {row.code && <code>{row.code}</code>}
                </span>
            ),
            phone: (row: Customer) =>
                // The counter's usual way in, so it reads as a handle.
                row.phone ? <code className="fs-13">{row.phone}</code> : orDash(null),
            is_active: (row: Customer) => <StatusBadge active={row.is_active} />,
            date_of_birth: (row: Customer) =>
                row.age !== null ? `${row.age} yrs` : orDash(row.date_of_birth),
            registered_location_id: (row: Customer) =>
                row.registered_location ? (
                    <span className="badge bg-light text-muted">
                        {row.registered_location.name}
                    </span>
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
                editTitle={`Edit ${labels.singular}`}
                deleteTitle={`Remove ${labels.singular}`}
            />
        ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canRemove, labels.singular],
    );

    const columns = useFieldColumns<Customer>({
        fields,
        pageIndex: table.pageIndex,
        pageSize: table.pageSize,
        renderers,
        actions,
    });

    return (
        <>
            <FilterPanel fields={filterFields} onChange={onFilterChange} onClear={onClearFilters} />

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
                    searchPlaceholder="Search by name, phone, email or city…"
                    /*
                     * An empty table under a filter is not an empty book.
                     * Telling somebody to add their first patient when they
                     * have four hundred and have simply filtered them all
                     * out is the kind of wrong that makes people distrust a
                     * screen.
                     */
                    emptyIcon={filtered ? 'ti ti-filter-off' : 'ti ti-users'}
                    emptyTone="sky"
                    emptyTitle={
                        filtered
                            ? `No ${labels.plural.toLowerCase()} match these filters`
                            : `No ${labels.plural.toLowerCase()} yet`
                    }
                    emptyDescription={
                        filtered
                            ? 'Try widening or clearing them.'
                            : 'Add the first person your organization serves.'
                    }
                    emptyAction={
                        filtered ? (
                            <Button size="sm" onClick={onClearFilters}>
                                Clear filters
                            </Button>
                        ) : (
                            <Button size="sm" onClick={() => navigate('/customers/create')}>
                                Add {labels.singular}
                            </Button>
                        )
                    }
                />
            </Card>
        </>
    );
}
