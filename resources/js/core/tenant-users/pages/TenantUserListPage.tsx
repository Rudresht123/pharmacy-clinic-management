import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { createColumnHelper } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDateTime, orDash } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import type { TenantUser } from '@/core/tenant-auth/api';
import { tenantUsersHooks } from '../api';

const column = createColumnHelper<TenantUser>();

export default function TenantUserListPage() {
    const confirm = useConfirm();
    const { user: signedIn } = useTenantAuth();
    const navigate = useNavigate();

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });
    const {
        data: page,
        isLoading,
        isFetching,
        isError,
        refetch,
    } = tenantUsersHooks.useTable(table.params);

    const rows = page?.data ?? [];
    const remove = tenantUsersHooks.useRemove();

    async function handleDelete(person: TenantUser) {
        const confirmed = await confirm({
            title: 'Remove this person?',
            message: `“${person.name}” will lose access. Their email becomes available again.`,
            confirmLabel: 'Remove',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(person.id);
        }
    }

    const columns = useMemo(
        () => [
            column.display({
                id: 'serial',
                header: '#',
                size: 60,
                cell: (info) => table.pageIndex * table.pageSize + info.row.index + 1,
            }),
            column.accessor('name', {
                header: 'Name',
                cell: (info) => (
                    <div>
                        <span className="fw-semibold d-block">
                            {info.getValue()}
                            {info.row.original.id === signedIn?.id && (
                                <span className="badge bg-light text-muted ms-2 fs-11">you</span>
                            )}
                        </span>
                        <span className="fs-13 text-muted">{info.row.original.email}</span>
                    </div>
                ),
            }),
            column.accessor('role', {
                header: 'Role',
                /*
                 * An owner has no role and needs none — they bypass level
                 * three. For staff the role's own name is shown, since "Staff"
                 * stopped being the answer the moment roles became something
                 * the owner writes.
                 */
                cell: (info) =>
                    info.getValue() === 'owner' ? (
                        <span className="badge bg-primary-transparent">Owner</span>
                    ) : (
                        <span className="badge bg-light text-muted">
                            {info.row.original.role_name ?? 'No role'}
                        </span>
                    ),
            }),
            column.accessor('last_login_at', {
                header: 'Last signed in',
                cell: (info) => (info.getValue() ? formatDateTime(info.getValue()) : orDash(null)),
            }),
            column.accessor('is_active', {
                header: 'Status',
                cell: (info) => <StatusBadge active={info.getValue()} />,
            }),
            column.display({
                id: 'actions',
                header: 'Action',
                size: 90,
                cell: (info) => (
                    <RowActions
                        editTo={`/people/${info.row.original.id}/edit`}
                        onDelete={() => handleDelete(info.row.original)}
                        editTitle="Edit User"
                        deleteTitle="Remove User"
                    />
                ),
            }),
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [table.pageIndex, table.pageSize, signedIn?.id],
    );

    return (
        <>
            <PageHeader
                title="People"
                subtitle="Who can sign in to your workspace, and what they can do."
                icon="ti ti-users-group"
                tone="emerald"
                crumbs={[{ label: 'People' }]}
                actions={
                    <Button icon="ti ti-plus" onClick={() => navigate('/people/create')}>
                        Add Person
                    </Button>
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
                    searchPlaceholder="Search by name or email…"
                    emptyIcon="ti ti-users-group"
                    emptyTone="emerald"
                    emptyTitle="Nobody else yet"
                    emptyDescription="Add the people who work with you so they can sign in."
                    emptyAction={
                        <Button size="sm" onClick={() => navigate('/people/create')}>
                            Add Person
                        </Button>
                    }
                />
            </Card>
        </>
    );
}
