import { useMemo, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { orDash } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { useLabMaster } from '../../settings/api';
import { useLabTests, useRemoveLabTest } from '../api';
import { CATALOGUE_PATH } from '../catalogue';
import { money } from '../money';
import type { LabTest } from '../types';

type StatusFilter = 'all' | 'active' | 'inactive';

/**
 * The Test Catalogue: every test the lab offers, filed by department.
 *
 * A tab of Settings › Laboratory rather than a menu row of its own. A row opens
 * the test's own page (parameters, ranges, branch prices) — adding and editing
 * are pages, not dialogs, because a test is too many fields for a modal.
 */
export function TestCataloguePanel() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();
    const canManage = can('laboratory.catalog_manage');

    const [department, setDepartment] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });
    const { data: page, isLoading, isFetching, isError, refetch } = useLabTests({
        ...table.params,
        ...(department ? { department_id: Number(department) } : {}),
        ...(status === 'all' ? {} : { active: status === 'active' ? 1 : 0 }),
    });

    const { data: departments } = useLabMaster('departments');
    const remove = useRemoveLabTest();
    const [removing, setRemoving] = useState<LabTest>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const columns = useMemo(() => {
        const column = createColumnHelper<LabTest>();

        return [
            column.display({
                id: 'name',
                header: 'Test',
                meta: { label: 'Test' },
                cell: (info) => {
                    const test = info.row.original;

                    return (
                        <Link to={`/settings/lab-tests/${test.id}`} className="lab-test-name">
                            <b>{test.name}</b>
                            <span>
                                <code className="lab-code">{test.code}</code>
                                {test.short_name && <em>{test.short_name}</em>}
                            </span>
                        </Link>
                    );
                },
            }),
            column.display({
                id: 'department',
                header: 'Department',
                meta: { label: 'Department' },
                cell: (info) => (
                    <div>
                        {orDash(info.row.original.department_name)}
                        {info.row.original.category_name && (
                            <span className="dr-sub d-block">{info.row.original.category_name}</span>
                        )}
                    </div>
                ),
            }),
            column.display({
                id: 'sample',
                header: 'Sample',
                meta: { label: 'Sample' },
                cell: (info) => orDash(info.row.original.sample_type_name),
            }),
            column.display({
                id: 'parameters',
                header: 'Parameters',
                meta: { label: 'Parameters' },
                cell: (info) =>
                    info.row.original.result_layout === 'narrative' && !info.row.original.parameters_count
                        ? 'Written report'
                        : (info.row.original.parameters_count ?? 0),
            }),
            column.display({
                id: 'tat',
                header: 'Turnaround',
                meta: { label: 'Turnaround' },
                cell: (info) => (info.row.original.tat_hours ? `${info.row.original.tat_hours} h` : '—'),
            }),
            column.display({
                id: 'price',
                header: 'Price',
                meta: { label: 'Price' },
                cell: (info) => (
                    <div>
                        {money(info.row.original.price)}
                        {(info.row.original.branch_prices_count ?? 0) > 0 && (
                            <span className="dr-sub d-block">
                                {info.row.original.branch_prices_count} branch price
                                {info.row.original.branch_prices_count === 1 ? '' : 's'}
                            </span>
                        )}
                    </div>
                ),
            }),
            column.display({
                id: 'is_active',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => (
                    <div className="lab-status-cell">
                        <StatusBadge active={info.row.original.is_active} />
                        {info.row.original.is_outsourced && <span className="lab-tag">Sent out</span>}
                    </div>
                ),
            }),
            ...(canManage
                ? [
                      column.display({
                          id: 'actions',
                          header: 'Action',
                          size: 90,
                          cell: (info) => (
                              <RowActions
                                  onEdit={() => navigate(`/settings/lab-tests/${info.row.original.id}`)}
                                  onDelete={() => {
                                      setRefusal(null);
                                      setRemoving(info.row.original);
                                  }}
                                  editTitle="Open test"
                                  deleteTitle="Remove test"
                              />
                          ),
                      }),
                  ]
                : []),
        ] as ColumnDef<LabTest, unknown>[];
    }, [canManage, navigate]);

    const filtered = department !== '' || status !== 'all' || table.search !== '';

    return (
        <>
            <Card
                className="lab-panel"
                title="Test Catalogue"
                icon="ti ti-test-pipe"
                description="Every test the lab offers: what it reads, what normal looks like, and what it costs."
                actions={
                    canManage ? (
                        <div className="lab-panel-actions">
                            <Button size="sm" icon="ti ti-plus" onClick={() => navigate('/settings/lab-tests/new')}>
                                Add Test
                            </Button>
                        </div>
                    ) : undefined
                }
            >
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
                    searchPlaceholder="Search by name or code…"
                    toolbar={
                        <div className="lab-filters">
                            <SearchableSelect
                                compact
                                ariaLabel="Department"
                                value={department}
                                onChange={setDepartment}
                                placeholder="All departments"
                                clearable
                                options={(departments ?? []).map((row) => ({ value: String(row.id), label: row.label }))}
                            />
                            <SearchableSelect
                                compact
                                ariaLabel="Status"
                                value={status}
                                onChange={(value) => setStatus((value || 'all') as StatusFilter)}
                                options={[
                                    { value: 'all', label: 'Any status' },
                                    { value: 'active', label: 'In use' },
                                    { value: 'inactive', label: 'Switched off' },
                                ]}
                            />
                        </div>
                    }
                    emptyIcon="ti ti-test-pipe"
                    emptyTone="violet"
                    emptyTitle={filtered ? 'No tests match' : 'No tests yet'}
                    emptyDescription={
                        filtered
                            ? 'Try another department, status or search.'
                            : 'Add the tests your lab runs. Each one says what is read off it, and qualified staff set what normal looks like.'
                    }
                    emptyAction={
                        canManage && !filtered ? (
                            <Button size="sm" onClick={() => navigate('/settings/lab-tests/new')}>
                                Add Test
                            </Button>
                        ) : undefined
                    }
                />
            </Card>

            <ReasonDialog
                open={removing !== undefined}
                title={`Remove ${removing?.name ?? ''}?`}
                subtitle="It stops being offered. Orders already placed keep its name and price."
                submitLabel="Remove"
                danger
                submitting={remove.isPending}
                error={refusal}
                onClose={() => setRemoving(undefined)}
                onSubmit={async (reason) => {
                    if (!removing) {
                        return;
                    }

                    try {
                        await remove.mutateAsync({ id: removing.id, reason });
                        setRemoving(undefined);
                    } catch (failure) {
                        setRefusal(resolveErrorMessage(failure));
                    }
                }}
            />
        </>
    );
}

/** The old address of the list: it is a tab of Settings › Laboratory now. */
export default function LabTestListPage() {
    return <Navigate to={CATALOGUE_PATH} replace />;
}
