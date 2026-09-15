import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SwitchField, TextField, TextareaField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { orDash } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { suppliersHooks, useRemoveSupplier, type Supplier } from '../inventory';

type SupplierValues = Record<string, unknown>;

/**
 * Who goods come from. One list for the whole organization.
 *
 * Added and edited in a dialog: a supplier is a handful of fields, and the
 * list is where somebody is when they need a new one.
 */
export default function SuppliersPage() {
    const { can } = useTenantAuth();
    const canManage = can('pharmacy.stores');

    const table = useServerTable({ pageSize: 25, sort: 'name', direction: 'asc' });
    const { data: page, isLoading, isFetching, isError, refetch } = suppliersHooks.useTable(table.params);

    const create = suppliersHooks.useCreate();
    const update = suppliersHooks.useUpdate();
    const remove = useRemoveSupplier();

    // undefined = closed, null = adding, a supplier = editing it.
    const [editing, setEditing] = useState<Supplier | null>();
    const [removing, setRemoving] = useState<Supplier>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const {
        register,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<SupplierValues>({ defaultValues: { is_active: true } });

    useEffect(() => {
        if (editing === undefined) {
            return;
        }

        reset({
            name: editing?.name ?? '',
            code: editing?.code ?? '',
            gstin: editing?.gstin ?? '',
            contact_person: editing?.contact_person ?? '',
            phone: editing?.phone ?? '',
            email: editing?.email ?? '',
            drug_license_no: editing?.drug_license_no ?? '',
            drug_license_expiry_date: editing?.drug_license_expiry_date ?? '',
            address: editing?.address ?? '',
            is_active: editing?.is_active ?? true,
        });
    }, [editing, reset]);

    const onSubmit = handleSubmit(async (payload) => {
        const result = await submit(payload, async () =>
            editing ? update.mutateAsync({ id: editing.id, payload }) : create.mutateAsync(payload),
        );

        if (result) {
            setEditing(undefined);
        }
    });

    const columns = useMemo(() => {
        const column = createColumnHelper<Supplier>();

        return [
            column.display({
                id: 'name',
                header: 'Supplier',
                meta: { label: 'Supplier' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.name}</b>
                        {info.row.original.code && <span className="dr-sub">{info.row.original.code}</span>}
                    </div>
                ),
            }),
            column.display({
                id: 'gstin',
                header: 'GSTIN',
                meta: { label: 'GSTIN' },
                cell: (info) =>
                    info.row.original.gstin ? <code className="fs-13">{info.row.original.gstin}</code> : '—',
            }),
            column.display({
                id: 'contact',
                header: 'Contact',
                meta: { label: 'Contact' },
                cell: (info) => (
                    <div>
                        {orDash(info.row.original.contact_person)}
                        {info.row.original.phone && <span className="dr-sub d-block">{info.row.original.phone}</span>}
                    </div>
                ),
            }),
            column.display({
                id: 'drug_license_no',
                header: 'Drug licence',
                meta: { label: 'Drug licence' },
                cell: (info) => orDash(info.row.original.drug_license_no),
            }),
            column.display({
                id: 'is_active',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => <StatusBadge active={info.row.original.is_active} />,
            }),
            ...(canManage
                ? [
                      column.display({
                          id: 'actions',
                          header: 'Action',
                          size: 90,
                          cell: (info) => (
                              <RowActions
                                  onEdit={() => setEditing(info.row.original)}
                                  onDelete={() => {
                                      setRefusal(null);
                                      setRemoving(info.row.original);
                                  }}
                                  editTitle="Edit supplier"
                                  deleteTitle="Remove supplier"
                              />
                          ),
                      }),
                  ]
                : []),
        ] as ColumnDef<Supplier, unknown>[];
    }, [canManage]);

    return (
        <>
            <PageHeader
                title="Suppliers"
                subtitle="Who your goods come from, with their GSTIN and drug licence."
                icon="ti ti-truck"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Suppliers' }]}
                actions={
                    canManage ? (
                        <Button icon="ti ti-plus" onClick={() => setEditing(null)}>
                            Add Supplier
                        </Button>
                    ) : undefined
                }
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
                    searchPlaceholder="Search by name, code, GSTIN or phone…"
                    emptyIcon="ti ti-truck"
                    emptyTone="teal"
                    emptyTitle="No suppliers yet"
                    emptyDescription="Add the distributors you buy from. A purchase names its supplier."
                    emptyAction={
                        canManage ? (
                            <Button size="sm" onClick={() => setEditing(null)}>
                                Add Supplier
                            </Button>
                        ) : undefined
                    }
                />
            </Card>

            <FormModal
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? `Edit ${editing.name}` : 'Add Supplier'}
                size="lg"
                submitLabel={editing ? 'Update Supplier' : 'Add Supplier'}
                submitting={isSubmitting}
                onSubmit={onSubmit}
            >
                {errors.root?.message && (
                    <div className="alert alert-danger py-2 px-3 fs-13">{errors.root.message}</div>
                )}

                <div className="row">
                    <TextField className="col-md-8" name="name" label="Name" required register={register} errors={errors} />
                    <TextField className="col-md-4" name="code" label="Code" register={register} errors={errors} />
                    <TextField
                        className="col-md-6"
                        name="gstin"
                        label="GSTIN"
                        register={register}
                        errors={errors}
                        placeholder="27AAPFU0939F1ZV"
                    />
                    <TextField className="col-md-6" name="contact_person" label="Contact person" register={register} errors={errors} />
                    <TextField className="col-md-6" name="phone" label="Phone" register={register} errors={errors} />
                    <TextField className="col-md-6" name="email" label="Email" type="email" register={register} errors={errors} />
                    <TextField className="col-md-6" name="drug_license_no" label="Drug licence number" register={register} errors={errors} />
                    <TextField
                        className="col-md-6"
                        name="drug_license_expiry_date"
                        label="Licence expires on"
                        type="date"
                        register={register}
                        errors={errors}
                    />
                </div>

                <TextareaField name="address" label="Address" rows={2} register={register} errors={errors} />
                <SwitchField name="is_active" label="Active" description="We still buy from them" register={register} errors={errors} />
            </FormModal>

            <ReasonDialog
                open={removing !== undefined}
                title={`Remove ${removing?.name ?? ''}?`}
                subtitle="Receipts and batches keep naming them. They leave the supplier list."
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
