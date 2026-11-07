import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import type { Control, FieldErrors, UseFormRegister } from 'react-hook-form';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SelectField, SwitchField, TextField, TextareaField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { orDash } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { locationsHooks } from '@/core/locations/api';
import {
    useLabMaster,
    useLoadLabStarter,
    useRemoveLabMasterRow,
    useSaveLabMasterRow,
} from '../api';
import type { MasterDefinition, MasterField, MasterKey, MasterRow } from '../types';

type Values = Record<string, unknown>;

/** `department_id` → `department_name`: what the server puts beside an id. */
const nameKey = (field: MasterField) => field.name.replace(/_id$/, '_name');

/** The value a form starts with for a field, adding or editing. */
function initial(field: MasterField, row: MasterRow | null): unknown {
    const value = row ? row[field.name] : field.default;

    if (field.type === 'switch') {
        return Boolean(value ?? false);
    }

    return value === null || value === undefined ? '' : value;
}

/** What the server is sent: blanks as null, numbers as numbers, ids as ids. */
function payloadFrom(fields: MasterField[], values: Values): Values {
    const payload: Values = { is_active: Boolean(values.is_active) };

    for (const field of fields) {
        const value = values[field.name];

        if (field.type === 'switch') {
            payload[field.name] = Boolean(value);
        } else if (value === '' || value === undefined || value === null) {
            payload[field.name] = null;
        } else if (field.type === 'number' || field.type === 'select') {
            payload[field.name] = Number(value);
        } else {
            payload[field.name] = String(value);
        }
    }

    return payload;
}

/** A cap colour as the collector sees it: a dot and its name. */
function CapColour({ value, label }: { value: string; label: string }) {
    return (
        <span className="lab-cap">
            <span className={`lab-cap-dot is-${value}`} aria-hidden="true" />
            {label}
        </span>
    );
}

/** A select over another list (or the branches), loading only what it offers. */
function SourceSelect({
    field,
    control,
    errors,
}: {
    field: MasterField;
    control: Control<Values>;
    errors: FieldErrors<Values>;
}) {
    const isBranches = field.source === 'locations';
    const { data: rows, isLoading } = useLabMaster(isBranches ? undefined : (field.source as MasterKey), true);
    const { data: branches, isLoading: branchesLoading } = locationsHooks.useList(
        { all: 1 },
        { enabled: isBranches },
    );

    const options = isBranches
        ? (branches ?? []).map((branch) => ({ value: branch.id, label: branch.name }))
        : (rows ?? []).map((row) => ({ value: row.id, label: row.label }));

    return (
        <SelectField
            className={`col-md-${field.span}`}
            name={field.name}
            label={field.label}
            control={control}
            errors={errors}
            required={field.required}
            hint={field.hint ?? undefined}
            options={options}
            loading={isBranches ? branchesLoading : isLoading}
        />
    );
}

/** One field of a list's form, drawn from the server's description of it. */
function FieldInput({
    field,
    register,
    control,
    errors,
}: {
    field: MasterField;
    register: UseFormRegister<Values>;
    control: Control<Values>;
    errors: FieldErrors<Values>;
}) {
    const width = `col-md-${field.span}`;
    const hint = field.hint ?? undefined;

    switch (field.type) {
        case 'textarea':
            return (
                <TextareaField
                    className={width}
                    name={field.name}
                    label={field.label}
                    register={register}
                    errors={errors}
                    rows={2}
                    hint={hint}
                    placeholder={field.placeholder ?? undefined}
                />
            );
        case 'switch':
            return (
                <div className={width}>
                    <SwitchField
                        name={field.name}
                        label={field.label}
                        register={register}
                        errors={errors}
                        layout="row"
                        hint={hint}
                    />
                </div>
            );
        case 'choice':
            return (
                <SelectField
                    className={width}
                    name={field.name}
                    label={field.label}
                    control={control}
                    errors={errors}
                    required={field.required}
                    hint={hint}
                    options={field.options ?? []}
                />
            );
        case 'select':
            return <SourceSelect field={field} control={control} errors={errors} />;
        default:
            return (
                <TextField
                    className={width}
                    name={field.name}
                    label={field.label}
                    register={register}
                    errors={errors}
                    required={field.required}
                    hint={hint}
                    placeholder={field.placeholder ?? undefined}
                    type={field.type === 'email' ? 'email' : field.type === 'number' ? 'number' : field.type === 'phone' ? 'tel' : 'text'}
                />
            );
    }
}

/**
 * One of the laboratory's lists: its table, and the dialog that adds or edits a
 * row. Drawn entirely from the list's description, so all nine lists look and
 * behave the same and none can drift from what the server checks.
 */
export function MasterPanel({ definition, editable }: { definition: MasterDefinition; editable: boolean }) {
    const { data: rows, isLoading, isFetching, isError, refetch } = useLabMaster(definition.key);

    const save = useSaveLabMasterRow(definition.key);
    const remove = useRemoveLabMasterRow(definition.key);
    const starter = useLoadLabStarter(definition.key);

    // undefined = closed, null = adding, a row = editing it.
    const [editing, setEditing] = useState<MasterRow | null>();
    const [removing, setRemoving] = useState<MasterRow>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { is_active: true } });

    useEffect(() => {
        if (editing === undefined) {
            return;
        }

        const values: Values = { is_active: editing?.is_active ?? true };

        for (const field of definition.fields) {
            values[field.name] = initial(field, editing);
        }

        reset(values);
    }, [editing, definition, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, () =>
            save.mutateAsync({ id: editing?.id, payload: payloadFrom(definition.fields, values) }),
        );

        if (result) {
            setEditing(undefined);
        }
    });

    const columns = useMemo(() => {
        const column = createColumnHelper<MasterRow>();
        const [first, ...rest] = definition.fields.filter((field) => field.list);

        return [
            column.display({
                id: first.name,
                header: first.label,
                meta: { label: first.label },
                cell: (info) => <b className="d-block">{info.row.original.label}</b>,
            }),
            ...rest.map((field) =>
                column.display({
                    id: field.name,
                    header: field.label,
                    meta: { label: field.label },
                    cell: (info) => {
                        const row = info.row.original;

                        if (field.type === 'select') {
                            return orDash(row[nameKey(field)] as string | null);
                        }

                        if (field.type === 'switch') {
                            return row[field.name] ? 'Yes' : 'No';
                        }

                        if (field.type === 'choice') {
                            const option = field.options?.find((choice) => choice.value === row[field.name]);

                            return option ? (
                                field.name === 'cap_colour' ? (
                                    <CapColour value={option.value} label={option.label} />
                                ) : (
                                    option.label
                                )
                            ) : (
                                '—'
                            );
                        }

                        if (field.type === 'code') {
                            return row[field.name] ? <code className="lab-code">{String(row[field.name])}</code> : '—';
                        }

                        return orDash(row[field.name] as string | number | null);
                    },
                }),
            ),
            column.display({
                id: 'is_active',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => <StatusBadge active={info.row.original.is_active} />,
            }),
            ...(editable
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
                                  editTitle={`Edit ${definition.singular}`}
                                  deleteTitle={`Remove ${definition.singular}`}
                              />
                          ),
                      }),
                  ]
                : []),
        ] as ColumnDef<MasterRow, unknown>[];
    }, [definition, editable]);

    const empty = !isLoading && (rows?.length ?? 0) === 0;

    return (
        <>
            <Card
                className="lab-panel"
                title={definition.plural}
                icon={definition.icon}
                description={definition.description}
                actions={
                    editable ? (
                        <div className="lab-panel-actions">
                            {definition.starter && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    icon="ti ti-list-check"
                                    loading={starter.isPending}
                                    onClick={() => starter.mutate()}
                                >
                                    Load starter list
                                </Button>
                            )}
                            <Button size="sm" icon="ti ti-plus" onClick={() => setEditing(null)}>
                                Add {definition.singular}
                            </Button>
                        </div>
                    ) : undefined
                }
            >
                <DataTable
                    data={rows ?? []}
                    columns={columns}
                    loading={isLoading}
                    fetching={isFetching}
                    error={isError}
                    onRetry={refetch}
                    pageSize={25}
                    searchPlaceholder={`Search ${definition.plural.toLowerCase()}…`}
                    hideSearch={empty}
                    emptyIcon={definition.icon}
                    emptyTone="violet"
                    emptyTitle={`No ${definition.plural.toLowerCase()} yet`}
                    emptyDescription={
                        definition.starter
                            ? 'Add your own, or load the starter list of common names and edit it to suit your lab.'
                            : `Add the ${definition.plural.toLowerCase()} your lab uses.`
                    }
                    emptyAction={
                        editable ? (
                            <Button size="sm" onClick={() => setEditing(null)}>
                                Add {definition.singular}
                            </Button>
                        ) : undefined
                    }
                />
            </Card>

            <FormModal
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? `Edit ${editing.label}` : `Add ${definition.singular}`}
                size="md"
                submitLabel={editing ? 'Save changes' : `Add ${definition.singular}`}
                submitting={isSubmitting}
                onSubmit={onSubmit}
                className="lab-modal"
            >
                {errors.root?.message && (
                    <div className="alert alert-danger py-2 px-3 fs-13">{errors.root.message}</div>
                )}

                <div className="row">
                    {definition.fields.map((field) => (
                        <FieldInput
                            key={field.name}
                            field={field}
                            register={register}
                            control={control}
                            errors={errors}
                        />
                    ))}
                </div>

                <SwitchField
                    name="is_active"
                    label="In use"
                    description="In use"
                    hint="Switched off, it stays on everything that already names it but is no longer offered."
                    layout="row"
                    register={register}
                    errors={errors}
                />
            </FormModal>

            <ReasonDialog
                open={removing !== undefined}
                title={`Remove ${removing?.label ?? ''}?`}
                subtitle="It leaves the list. Anything that already names it keeps the name."
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
