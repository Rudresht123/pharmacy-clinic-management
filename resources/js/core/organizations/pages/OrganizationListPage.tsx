import { useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { createColumnHelper } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Avatar } from '@/shared/components/ui/Avatar';
import { RowActions } from '@/shared/components/ui/RowActions';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate, orDash } from '@/shared/utils/format';
import { OrganizationStatusBadge } from '../components/OrganizationStatusBadge';
import { organizationsHooks } from '../api';
import type { Organization } from '../types';

const column = createColumnHelper<Organization>();

export default function OrganizationListPage() {
    const navigate = useNavigate();
    const confirm = useConfirm();

    // Paging, sorting and search all happen on the server. The default sort
    // is the first real column — the one right after the serial number.
    const table = useServerTable({ pageSize: 25, sort: 'organization_name', direction: 'asc' });
    const {
        data: page,
        isLoading,
        isFetching,
        isError,
        refetch,
    } = organizationsHooks.useTable(table.params);

    const rows = page?.data ?? [];
    const remove = organizationsHooks.useRemove();

    async function handleDelete(organization: Organization) {
        const confirmed = await confirm({
            title: 'Delete organization?',
            message: `“${organization.organization_name}” will be removed from the list. Its tenant database is kept.`,
            confirmLabel: 'Delete',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(organization.uuid);
        }
    }

    const columns = useMemo(
        () => [
            column.display({
                id: 'serial',
                header: '#',
                size: 60,
                // row.index is page-local, so the page offset is added back.
                cell: (info) => table.pageIndex * table.pageSize + info.row.index + 1,
            }),
            column.accessor('organization_name', {
                header: 'Organization',
                size: 260,
                cell: (info) => {
                    const row = info.row.original;

                    return (
                        <div className="dt-media">
                            <Avatar
                                name={row.organization_name}
                                src={row.profile_image_url}
                                hasImage={row.profile_image_id !== null}
                                preview
                            />

                            <span className="dt-lede">
                                {/* The name is the way into the detail screen —
                                    §17 puts everything about one tenant there. */}
                                <Link
                                    to={`/organizations/${row.uuid}`}
                                    className="dt-ellipsis dt-link"
                                    title={info.getValue()}
                                >
                                    <b>{info.getValue()}</b>
                                </Link>
                                <small className="dt-ellipsis">{row.subdomain}</small>
                            </span>
                        </div>
                    );
                },
            }),
            column.accessor('organization_code', {
                header: 'Code',
                size: 120,
                cell: (info) => <span className="dt-muted">{info.getValue()}</span>,
            }),
            column.accessor((row) => row.organization_type?.name ?? '', {
                id: 'type',
                header: 'Type',
                size: 140,
                cell: (info) => orDash(info.getValue()),
            }),
            column.accessor('contact_person_name', {
                header: 'Contact',
                size: 160,
                cell: (info) => <span className="dt-ellipsis">{orDash(info.getValue())}</span>,
            }),
            column.accessor('email', {
                header: 'Email',
                size: 220,
                cell: (info) => (
                    <span className="dt-ellipsis dt-muted" title={info.getValue() ?? undefined}>
                        {orDash(info.getValue())}
                    </span>
                ),
            }),
            column.accessor('status', {
                header: 'Status',
                size: 130,
                // The lifecycle state, not the is_active flag — §9 has six
                // states and "active/inactive" cannot express them.
                cell: (info) => <OrganizationStatusBadge status={info.getValue()} />,
            }),
            column.accessor('created_at', {
                header: 'Created',
                size: 130,
                cell: (info) => <span className="dt-muted">{formatDate(info.getValue())}</span>,
            }),
            column.display({
                id: 'actions',
                header: 'Action',
                size: 90,
                cell: (info) => (
                    <RowActions
                        editTo={`/organizations/${info.row.original.uuid}/edit`}
                        onDelete={() => handleDelete(info.row.original)}
                        editTitle="Edit Organization"
                        deleteTitle="Delete Organization"
                    />
                ),
            }),
        ],
        // handleDelete closes over confirm/remove, both stable enough for this list.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [navigate, table.pageIndex, table.pageSize],
    );

    return (
        <>
            <PageHeader
                title="Organizations"
                icon="ti ti-building-store"
                tone="violet"
                crumbs={[{ label: 'Global Settings' }, { label: 'Organizations' }]}
                actions={
                    <Link to="/organizations/create" className="btn btn-primary">
                        <i className="ti ti-plus me-1" />
                        Add Organization
                    </Link>
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
                    searchPlaceholder="Search organizations…"
                    emptyIcon="ti ti-building-store"
                    emptyTone="violet"
                    emptyTitle="No organizations yet"
                    emptyDescription="Each organization gets its own database and an emailed setup link. Create the first one to get started."
                    emptyAction={
                        <Link to="/organizations/create" className="btn btn-primary btn-sm">
                            Add Organization
                        </Link>
                    }
                />
            </Card>
        </>
    );
}
