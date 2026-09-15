import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { Button } from '@/shared/components/ui/Button';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { notify } from '@/shared/utils/notify';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { medicinesHooks } from '@/core/medicines/api';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import {
    storesHooks,
    useRemoveStoreMedicine,
    useSaveStoreMedicines,
    useStoreMedicines,
} from '../api';
import type { StoreMedicine, StoreMedicineInput } from '../types';

/** The levels as typed — strings until they are saved. */
interface Levels {
    reorder_level: string;
    minimum_stock_level: string;
    maximum_stock_level: string;
    is_active: boolean;
}

type LevelKey = 'reorder_level' | 'minimum_stock_level' | 'maximum_stock_level';

const BLANK: Levels = {
    reorder_level: '0',
    minimum_stock_level: '0',
    maximum_stock_level: '',
    is_active: true,
};

function fromRow(row: StoreMedicine): Levels {
    return {
        reorder_level: String(row.reorder_level),
        minimum_stock_level: String(row.minimum_stock_level),
        maximum_stock_level: row.maximum_stock_level === null ? '' : String(row.maximum_stock_level),
        is_active: row.is_active,
    };
}

function toInput(medicineId: number, levels: Levels): StoreMedicineInput {
    return {
        medicine_id: medicineId,
        reorder_level: Number(levels.reorder_level) || 0,
        minimum_stock_level: Number(levels.minimum_stock_level) || 0,
        maximum_stock_level:
            levels.maximum_stock_level.trim() === '' ? null : Number(levels.maximum_stock_level),
        is_active: levels.is_active,
    };
}

/**
 * What one store stocks, and the levels that make each medicine "low".
 *
 * Configuration only — quantities arrive with batches in the next phase and
 * are never typed in here. A row is edited in place and saved on its own,
 * because a store can stock thousands of medicines and a person changes a
 * handful at a time.
 */
export default function StoreMedicinesPage() {
    const { id } = useParams();
    const storeId = Number(id);

    const { can } = useTenantAuth();
    const canConfigure = can('pharmacy.stores');
    // The picker reads the catalogue, which is its own capability.
    const canPick = canConfigure && can('medicines.view');

    const { data: store } = storesHooks.useDetail(id);

    const table = useServerTable({ pageSize: 25, sort: 'created_at', direction: 'desc' });
    const { data: page, isLoading, isFetching, isError, refetch } = useStoreMedicines(
        storeId,
        table.params,
    );

    // Active medicines to add from — the first 200 by name. A picker that
    // searches the server arrives with prescribing, which needs one anyway.
    const { data: catalogue } = medicinesHooks.useList(
        canPick
            ? { per_page: 200, status: 'active', sort: 'generic_name', direction: 'asc' }
            : undefined,
    );

    const save = useSaveStoreMedicines(storeId);
    const remove = useRemoveStoreMedicine(storeId);

    // Rows being edited, by id. A row with no draft shows what is saved.
    const [drafts, setDrafts] = useState<Record<number, Levels>>({});
    const [adding, setAdding] = useState<Levels & { medicine_id: string }>({
        ...BLANK,
        medicine_id: '',
    });
    const [removing, setRemoving] = useState<StoreMedicine>();

    const current = (row: StoreMedicine): Levels => drafts[row.id] ?? fromRow(row);

    function edit(row: StoreMedicine, patch: Partial<Levels>) {
        setDrafts((was) => ({ ...was, [row.id]: { ...current(row), ...patch } }));
    }

    async function persist(rows: StoreMedicineInput[]): Promise<boolean> {
        try {
            await save.mutateAsync(rows);

            return true;
        } catch (error) {
            // A 422 is not toasted by the client, so it is said here.
            notify.error(resolveErrorMessage(error));

            return false;
        }
    }

    async function saveRow(row: StoreMedicine) {
        if (await persist([toInput(row.medicine_id, current(row))])) {
            setDrafts((was) => {
                const next = { ...was };
                delete next[row.id];

                return next;
            });
        }
    }

    async function add() {
        if (adding.medicine_id === '') {
            notify.error('Choose a medicine to add.');

            return;
        }

        if (await persist([toInput(Number(adding.medicine_id), adding)])) {
            setAdding({ ...BLANK, medicine_id: '' });
        }
    }

    const columns = useMemo(() => {
        const column = createColumnHelper<StoreMedicine>();

        const level = (row: StoreMedicine, key: LevelKey, label: string) =>
            canConfigure ? (
                <input
                    type="number"
                    min={0}
                    className="form-control form-control-sm"
                    style={{ width: '6.5rem' }}
                    aria-label={`${label} for ${row.medicine?.display_name ?? 'this medicine'}`}
                    value={current(row)[key]}
                    onChange={(event) => edit(row, { [key]: event.target.value })}
                />
            ) : (
                (row[key] ?? '—')
            );

        return [
            column.display({
                id: 'medicine',
                header: 'Medicine',
                meta: { label: 'Medicine' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.medicine?.display_name}</b>
                        <span className="dr-sub">
                            Counted in {info.row.original.medicine?.base_unit ?? 'units'}
                            {info.row.original.medicine?.is_removed &&
                                ' · removed from the catalogue'}
                        </span>
                    </div>
                ),
            }),
            column.display({
                id: 'reorder_level',
                header: 'Reorder at',
                meta: { label: 'Reorder at' },
                cell: (info) => level(info.row.original, 'reorder_level', 'Reorder level'),
            }),
            column.display({
                id: 'minimum_stock_level',
                header: 'Minimum',
                meta: { label: 'Minimum' },
                cell: (info) => level(info.row.original, 'minimum_stock_level', 'Minimum level'),
            }),
            column.display({
                id: 'maximum_stock_level',
                header: 'Maximum',
                meta: { label: 'Maximum' },
                cell: (info) => level(info.row.original, 'maximum_stock_level', 'Maximum level'),
            }),
            column.display({
                id: 'is_active',
                header: 'Stocked',
                meta: { label: 'Stocked' },
                cell: (info) =>
                    canConfigure ? (
                        <div className="form-check form-switch m-0">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                aria-label="Still stocked here"
                                checked={current(info.row.original).is_active}
                                onChange={(event) =>
                                    edit(info.row.original, { is_active: event.target.checked })
                                }
                            />
                        </div>
                    ) : (
                        <StatusBadge active={info.row.original.is_active} />
                    ),
            }),
            ...(canConfigure
                ? [
                      column.display({
                          id: 'actions',
                          header: 'Action',
                          size: 150,
                          cell: (info) => (
                              <div className="d-flex align-items-center justify-content-end gap-1">
                                  {drafts[info.row.original.id] && (
                                      <Button
                                          size="sm"
                                          icon="ti ti-device-floppy"
                                          loading={save.isPending}
                                          onClick={() => saveRow(info.row.original)}
                                      >
                                          Save
                                      </Button>
                                  )}

                                  <Button
                                      size="sm"
                                      variant="light"
                                      icon="ti ti-trash"
                                      aria-label="Stop stocking here"
                                      title="Stop stocking here"
                                      onClick={() => setRemoving(info.row.original)}
                                  />
                              </div>
                          ),
                      }),
                  ]
                : []),
        ] as ColumnDef<StoreMedicine, unknown>[];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [canConfigure, drafts, save.isPending]);

    const title = store ? store.name : 'Store';

    return (
        <>
            <PageHeader
                title={`${title} · Medicines`}
                subtitle="What this store keeps, and the level at which each one counts as low."
                icon="ti ti-pill"
                tone="teal"
                crumbs={[
                    { label: 'Pharmacy' },
                    { label: 'Stores', to: '/pharmacy/stores' },
                    { label: title },
                ]}
                actions={
                    <Link className="btn btn-light" to="/pharmacy/stores">
                        <i className="ti ti-arrow-left me-1" aria-hidden="true" />
                        All stores
                    </Link>
                }
            />

            {canPick && (
                <Card
                    title="Add a medicine"
                    icon="ti ti-plus"
                    description="Levels are counted in each medicine's base unit. Adding one this store already has updates its levels."
                >
                    <div className="d-flex flex-wrap align-items-end gap-2">
                        <div style={{ flex: '1 1 16rem', minWidth: 0 }}>
                            <label className="form-label" htmlFor="add-medicine">
                                Medicine
                            </label>
                            <SearchableSelect
                                id="add-medicine"
                                value={adding.medicine_id}
                                onChange={(value) =>
                                    setAdding((was) => ({ ...was, medicine_id: value }))
                                }
                                options={(catalogue ?? []).map((medicine) => ({
                                    value: String(medicine.id),
                                    label: medicine.display_name,
                                    hint: medicine.manufacturer ?? undefined,
                                }))}
                                placeholder="Search the catalogue…"
                            />
                        </div>

                        {(
                            [
                                ['reorder_level', 'Reorder at'],
                                ['minimum_stock_level', 'Minimum'],
                                ['maximum_stock_level', 'Maximum'],
                            ] as const
                        ).map(([key, label]) => (
                            <div key={key}>
                                <label className="form-label" htmlFor={`add-${key}`}>
                                    {label}
                                </label>
                                <input
                                    id={`add-${key}`}
                                    type="number"
                                    min={0}
                                    className="form-control"
                                    style={{ width: '7rem' }}
                                    placeholder={key === 'maximum_stock_level' ? 'None' : '0'}
                                    value={adding[key]}
                                    onChange={(event) =>
                                        setAdding((was) => ({ ...was, [key]: event.target.value }))
                                    }
                                />
                            </div>
                        ))}

                        <Button icon="ti ti-plus" loading={save.isPending} onClick={add}>
                            Add
                        </Button>
                    </div>
                </Card>
            )}

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
                    searchPlaceholder="Search by generic, brand or code…"
                    emptyIcon="ti ti-pill"
                    emptyTone="teal"
                    emptyTitle="Nothing stocked here yet"
                    emptyDescription={
                        canPick
                            ? 'Add the medicines this store keeps, with the level at which each should be reordered.'
                            : 'Nobody has said what this store keeps yet.'
                    }
                />
            </Card>

            <ReasonDialog
                open={removing !== undefined}
                title={`Stop stocking ${removing?.medicine?.display_name ?? 'this medicine'} here?`}
                subtitle="Its levels stay in the history. Adding it again brings the same row back."
                submitLabel="Remove"
                danger
                submitting={remove.isPending}
                onClose={() => setRemoving(undefined)}
                onSubmit={async (reason) => {
                    if (!removing) {
                        return;
                    }

                    await remove.mutateAsync({ id: removing.id, reason });
                    setRemoving(undefined);
                }}
            />
        </>
    );
}
