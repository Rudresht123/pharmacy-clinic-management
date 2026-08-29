import { useEffect, useMemo } from 'react';
import { createColumnHelper } from '@tanstack/react-table';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { notify } from '@/shared/utils/notify';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { FormModal } from '@/shared/components/ui/FormModal';
import { FormError, SwitchField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useModal } from '@/shared/hooks/useModal';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate } from '@/shared/utils/format';
import { organizationTypesHooks, toggleOrganizationTypeStatus } from '../api';
import type { OrganizationType } from '@/core/organizations/types';

const column = createColumnHelper<OrganizationType>();

// A `type` rather than an `interface`: only type aliases get an implicit
// index signature, which the resource API's Record<string, unknown> payload
// requires.
type TypeFormValues = {
    name: string;
    slug: string;
    is_active: boolean;
};

export default function OrganizationTypeListPage() {
    const confirm = useConfirm();
    const queryClient = useQueryClient();

    const modal = useModal<OrganizationType>();

    // Paging, sorting and search all happen on the server. The default sort
    // is the first real column — the one right after the serial number.
    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });
    const {
        data: page,
        isLoading,
        isFetching,
        isError,
        refetch,
    } = organizationTypesHooks.useTable(table.params);

    const rows = page?.data ?? [];
    const create = organizationTypesHooks.useCreate();
    const update = organizationTypesHooks.useUpdate();
    const remove = organizationTypesHooks.useRemove();

    const toggle = useMutation({
        mutationFn: toggleOrganizationTypeStatus,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: organizationTypesHooks.keys.all });
            notify.success('Status updated');
        },
    });

    const {
        register,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<TypeFormValues>({
        defaultValues: { name: '', slug: '', is_active: true },
    });

    // Editing refetches the record rather than trusting the row in the table,
    // which may have gone stale since the page loaded.
    const { data: editing, isLoading: loadingRecord } = organizationTypesHooks.useDetail(
        modal.data?.id,
    );

    useEffect(() => {
        if (editing) {
            reset({ name: editing.name, slug: editing.slug, is_active: editing.is_active });
        }
    }, [editing, reset]);

    function openCreate() {
        reset({ name: '', slug: '', is_active: true });
        modal.openCreate();
    }

    function openEdit(type: OrganizationType) {
        // Fields are filled by the effect above once the fetch resolves.
        modal.openEdit(type);
    }

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, async () =>
            modal.data
                ? update.mutateAsync({ id: modal.data.id, payload: values })
                : create.mutateAsync(values),
        );

        if (result) {
            modal.close();
        }
    });

    async function handleDelete(type: OrganizationType) {
        const confirmed = await confirm({
            title: 'Delete organization type?',
            message: `“${type.name}” will be removed.`,
            confirmLabel: 'Delete',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(type.id);
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
            column.accessor('name', {
                header: 'Organization Type',
                cell: (info) => <span className="fw-semibold">{info.getValue()}</span>,
            }),
            column.accessor('slug', {
                header: 'Slug',
                cell: (info) => <code className="fs-13">{info.getValue()}</code>,
            }),
            column.accessor('is_active', {
                header: 'Status',
                cell: (info) => (
                    <button
                        type="button"
                        className="btn btn-sm p-0 border-0 bg-transparent"
                        title="Toggle status"
                        onClick={() => toggle.mutate(info.row.original.id)}
                    >
                        <StatusBadge active={info.getValue()} />
                    </button>
                ),
            }),
            column.accessor('created_at', {
                header: 'Created',
                cell: (info) => formatDate(info.getValue()),
            }),
            column.display({
                id: 'actions',
                header: 'Action',
                size: 90,
                cell: (info) => (
                    <RowActions
                        onEdit={() => openEdit(info.row.original)}
                        onDelete={() => handleDelete(info.row.original)}
                        editTitle="Edit Organization Type"
                        deleteTitle="Delete Organization Type"
                    />
                ),
            }),
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [toggle, table.pageIndex, table.pageSize],
    );

    return (
        <>
            <PageHeader
                title="Organization Types"
                icon="ti ti-category"
                tone="teal"
                crumbs={[{ label: 'Global Settings' }, { label: 'Organization Types' }]}
                actions={
                    <Button icon="ti ti-plus" onClick={openCreate}>
                        Add Type
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
                    searchPlaceholder="Search types…"
                    emptyIcon="ti ti-category"
                    emptyTone="teal"
                    emptyTitle="No organization types yet"
                    emptyDescription="Types classify the organizations you create — add your first one to get started."
                    emptyAction={
                        <Button size="sm" onClick={openCreate}>
                            Add Type
                        </Button>
                    }
                />
            </Card>

            <FormModal
                open={modal.open}
                onClose={modal.close}
                title={modal.isEditing ? 'Edit Organization Type' : 'Add Organization Type'}
                subtitle={
                    modal.isEditing
                        ? 'Update this category.'
                        : 'Categories used to classify organizations.'
                }
                onSubmit={onSubmit}
                submitting={isSubmitting}
                loading={modal.isEditing && loadingRecord}
                submitLabel={modal.isEditing ? 'Update' : 'Create'}
            >
                <FormError message={errors.root?.message} />

                <TextField
                    name="name"
                    label="Name"
                    required
                    register={register}
                    errors={errors}
                    placeholder="Retail Pharmacy"
                    autoFocus
                />

                <TextField
                    name="slug"
                    label="Slug"
                    required
                    register={register}
                    errors={errors}
                    placeholder="retail-pharmacy"
                    hint="Used in URLs and integrations."
                />

                <SwitchField
                    name="is_active"
                    label="Active"
                    description="Active"
                    register={register}
                    errors={errors}
                />
            </FormModal>
        </>
    );
}
