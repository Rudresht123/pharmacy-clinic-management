import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Avatar } from '@/shared/components/ui/Avatar';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { LabTabs } from '../../shared/LabTabs';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { useLabMaster } from '../../settings/api';
import { useLabOrderCounts, useLabOrders, type OrderFilters } from '../api';
import { LabStatus, ORDER_STATUS_LABELS, shortStamp } from '../status';
import type { LabOrder, OrderStatus } from '../types';

type Tab = 'all' | OrderStatus;
type Period = 'all' | 'today' | '7d' | '30d' | 'month';

const TABS: Tab[] = ['all', 'pending', 'in_progress', 'completed', 'cancelled'];

const PERIODS: { value: Period; label: string }[] = [
    { value: 'all', label: 'Any date' },
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'month', label: 'This month' },
];

const iso = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** The dates a period covers, in the browser's own day. */
function datesOf(period: Period): Pick<OrderFilters, 'date_from' | 'date_to'> {
    const today = new Date();
    const back = (days: number) => new Date(today.getFullYear(), today.getMonth(), today.getDate() - days);

    switch (period) {
        case 'today':
            return { date_from: iso(today), date_to: iso(today) };
        case '7d':
            return { date_from: iso(back(6)), date_to: iso(today) };
        case '30d':
            return { date_from: iso(back(29)), date_to: iso(today) };
        case 'month':
            return { date_from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), date_to: iso(today) };
        default:
            return {};
    }
}

/**
 * Laboratory Tests: every test requested for a patient, and where it stands.
 *
 * One row per request (an order may carry several tests). Tabs split by status
 * with live counts; the department and date filters and the search (patient,
 * ID, phone, test or order number) narrow both the tabs and the list. A row
 * opens the request's own page. New requests are raised from a consultation
 * today; the lab desk's own "Add test" arrives with walk-in orders.
 */
export default function LabOrderListPage() {
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const tab = (TABS.includes(params.get('status') as Tab) ? params.get('status') : 'all') as Tab;

    const [department, setDepartment] = useState('');
    const [period, setPeriod] = useState<Period>('all');

    const table = useServerTable({ pageSize: 25, sort: 'created_at', direction: 'desc' });

    const filters: Omit<OrderFilters, 'status'> = {
        ...(department ? { department_id: Number(department) } : {}),
        ...datesOf(period),
        ...(table.search ? { search: table.search } : {}),
    };

    const { data: page, isLoading, isFetching, isError, refetch } = useLabOrders({
        ...table.params,
        ...filters,
        ...(tab === 'all' ? {} : { status: tab }),
    });
    const { data: counts } = useLabOrderCounts(filters);
    const { data: departments } = useLabMaster('departments');

    const columns = useMemo(() => {
        const column = createColumnHelper<LabOrder>();
        const offset = (page?.meta.current_page ? page.meta.current_page - 1 : 0) * (page?.meta.per_page ?? 25);

        return [
            column.display({
                id: 'no',
                header: '#',
                size: 48,
                meta: { label: '#' },
                cell: (info) => <span className="lab-row-no">{offset + info.row.index + 1}</span>,
            }),
            column.display({
                id: 'patient',
                header: 'Patient',
                meta: { label: 'Patient' },
                cell: (info) => {
                    const order = info.row.original;

                    return (
                        <Link to={`/lab/orders/${order.id}`} className="lab-person">
                            <Avatar name={order.patient_name ?? ''} src={order.patient_photo_url ?? undefined} size={34} preview={false} />
                            <span>
                                <b>{order.patient_name ?? '—'}</b>
                                <small>{order.patient_code ?? order.order_number}</small>
                            </span>
                        </Link>
                    );
                },
            }),
            column.display({
                id: 'tests',
                header: 'Test Name',
                meta: { label: 'Test Name' },
                cell: (info) => {
                    const items = info.row.original.items ?? [];
                    const [first, ...rest] = items;

                    return (
                        <div className="lab-tests-cell">
                            <span>{first?.test_name ?? '—'}</span>
                            {rest.length > 0 && (
                                <small title={rest.map((item) => item.test_name).join(', ')}>+{rest.length} more</small>
                            )}
                        </div>
                    );
                },
            }),
            column.display({
                id: 'department',
                header: 'Department',
                meta: { label: 'Department' },
                cell: (info) => info.row.original.departments?.join(', ') || <span className="text-muted">Not set</span>,
            }),
            column.display({
                id: 'status',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => <LabStatus status={info.row.original.status} />,
            }),
            column.display({
                id: 'sample',
                header: 'Sample Type',
                meta: { label: 'Sample Type' },
                cell: (info) => info.row.original.sample_types?.join(', ') || '—',
            }),
            column.display({
                id: 'ordered',
                header: 'Ordered On',
                meta: { label: 'Ordered On' },
                cell: (info) => <span className="text-nowrap">{shortStamp(info.row.original.created_at)}</span>,
            }),
            column.display({
                id: 'action',
                header: 'Action',
                size: 80,
                cell: (info) => (
                    <button
                        type="button"
                        className="action-badge"
                        title="Open test details"
                        onClick={() => navigate(`/lab/orders/${info.row.original.id}`)}
                    >
                        <i className="ti ti-eye" aria-hidden="true" />
                    </button>
                ),
            }),
        ] as ColumnDef<LabOrder, unknown>[];
    }, [navigate, page?.meta.current_page, page?.meta.per_page]);

    const filtered = department !== '' || period !== 'all' || table.search !== '';

    return (
        <>
            <PageHeader
                title="Laboratory Tests"
                subtitle="Every test requested for a patient, and where it stands."
                icon="ti ti-test-pipe"
                tone="violet"
                crumbs={[{ label: 'Laboratory' }, { label: 'Tests' }]}
            />

            <div className="inv-tabs-row lab-tabs-row">
                <LabTabs
                    label="Test status"
                    value={tab}
                    onChange={(next) => {
                        setParams(next === 'all' ? {} : { status: next }, { replace: true });
                        table.setPageIndex(0);
                    }}
                    tabs={TABS.map((value) => ({
                        value,
                        label: value === 'all' ? 'All' : ORDER_STATUS_LABELS[value],
                        count: counts ? counts[value] : undefined,
                    }))}
                />
            </div>

            <Card className="lab-orders">
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
                    searchPlaceholder="Search by patient, ID, test or order no…"
                    toolbar={
                        <div className="lab-filters">
                            <SearchableSelect
                                compact
                                ariaLabel="Department"
                                value={department}
                                onChange={(value) => {
                                    setDepartment(value);
                                    table.setPageIndex(0);
                                }}
                                placeholder="All departments"
                                clearable
                                options={(departments ?? []).map((row) => ({ value: String(row.id), label: row.label }))}
                            />
                            <SearchableSelect
                                compact
                                ariaLabel="When"
                                value={period}
                                onChange={(value) => {
                                    setPeriod((value || 'all') as Period);
                                    table.setPageIndex(0);
                                }}
                                options={PERIODS}
                            />
                        </div>
                    }
                    emptyIcon="ti ti-test-pipe"
                    emptyTone="violet"
                    emptyTitle={filtered || tab !== 'all' ? 'No tests match' : 'No tests requested yet'}
                    emptyDescription={
                        filtered || tab !== 'all'
                            ? 'Try another status, department, date or search.'
                            : 'Tests a doctor orders at a consultation appear here.'
                    }
                />
            </Card>
        </>
    );
}
