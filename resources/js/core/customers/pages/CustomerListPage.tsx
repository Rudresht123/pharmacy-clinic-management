import { useCallback, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { Tabs } from '@/shared/components/ui/Tabs';
import { useServerTable, type TableQueryParams } from '@/shared/hooks/useServerTable';
import { useEntityLabel } from '@/core/field-settings/api';
import { CustomerDashboard } from '../components/CustomerDashboard';
import { CustomerTable } from '../components/CustomerTable';
import { useCustomerFields, useCustomerStats } from '../api';

type View = 'overview' | 'listing';

/** Everything the filter panel can set, keyed by the query name it sends. */
export type CustomerFilters = Record<string, string>;

/**
 * One screen, two jobs.
 *
 * Understanding the whole book of people and finding one of them are
 * different tasks, and a dashboard with a table bolted underneath serves
 * neither: the charts get scrolled past, and the table starts halfway down
 * the page. Tabs give each its own room without splitting the URL.
 *
 * The selection rides the query string rather than the path, so a refresh
 * and the back button both land where the reader was — and, because the
 * pathname does not change, switching tabs does not raise the navigation
 * loader over a screen that never left.
 */
export default function CustomerListPage() {
    const navigate = useNavigate();

    // Pharmacies call them customers, clinics patients — one record either way.
    const label = useEntityLabel('customer');

    const [searchParams, setSearchParams] = useSearchParams();
    const view: View = searchParams.get('view') === 'listing' ? 'listing' : 'overview';

    /*
     * One bag rather than a useState per filter. The panel grows — six today,
     * more when the clinic module lands — and every new one would otherwise
     * mean another piece of state, another prop and another line in the
     * params memo.
     */
    const [filters, setFilters] = useState<CustomerFilters>({});

    const setFilter = useCallback((name: string, value: string) => {
        setFilters((current) => {
            const next = { ...current };

            // A blank value is the absence of a filter, not a filter for
            // blank — so it leaves rather than being sent as "".
            if (value === '') {
                delete next[name];
            } else {
                next[name] = value;
            }

            return next;
        });
    }, []);

    const clearFilters = useCallback(() => setFilters({}), []);

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });

    const params: TableQueryParams = useMemo(
        () => ({ ...table.params, ...filters }),
        [table.params, filters],
    );

    const { data: stats, isLoading: statsLoading } = useCustomerStats();
    const { data: fields } = useCustomerFields();

    const show = useCallback(
        (next: View) => {
            // replace, so flipping between tabs does not stack history
            // entries the back button then has to walk out of one by one.
            setSearchParams(next === 'overview' ? {} : { view: next }, { replace: true });
        },
        [setSearchParams],
    );

    const drillDown = useCallback(
        (next: 'active' | 'inactive') => {
            setFilter('status', filters.status === next ? '' : next);
            show('listing');
        },
        [filters.status, setFilter, show],
    );

    const labels = useMemo(
        () => ({ singular: label.singular, plural: label.plural }),
        [label.singular, label.plural],
    );

    return (
        <>
            <PageHeader
                title={label.plural}
                subtitle="One record per person, shared by every branch in your organization."
                icon="ti ti-users"
                tone="sky"
                crumbs={[{ label: label.plural }]}
                actions={
                    <Button icon="ti ti-plus" onClick={() => navigate('/customers/create')}>
                        Add {label.singular}
                    </Button>
                }
            />

            <Tabs<View>
                label={`${label.plural} views`}
                value={view}
                onChange={show}
                tabs={[
                    { value: 'overview', label: 'Overview', icon: 'ti ti-chart-donut' },
                    {
                        value: 'listing',
                        label: 'Listing',
                        icon: 'ti ti-list',
                        badge: stats?.total,
                    },
                ]}
            />

            {view === 'overview' ? (
                <CustomerDashboard
                    stats={stats}
                    loading={statsLoading}
                    fields={fields}
                    labels={labels}
                    onDrillDown={drillDown}
                />
            ) : (
                <CustomerTable
                    table={table}
                    params={params}
                    fields={fields}
                    stats={stats}
                    labels={labels}
                    filters={filters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                />
            )}
        </>
    );
}
