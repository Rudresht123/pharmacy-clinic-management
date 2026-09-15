import { useMemo, useState } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDateTime, orDash } from '@/shared/utils/format';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { MOVEMENT_LABELS, useMovements, type StockMovement } from '../inventory';

/**
 * A store's ledger: every quantity change, newest first.
 *
 * Read-only by design. A wrong row is answered by a correcting row, which
 * shows here beside the one it corrects.
 */
export default function MovementsPage() {
    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    const [filters, setFilters] = useState<Record<string, string>>({});
    const table = useServerTable({ pageSize: 50, sort: 'id', direction: 'desc' });

    const { data: page, isLoading, isFetching, isError, refetch } = useMovements(store?.id, {
        ...table.params,
        ...filters,
    });

    const columns = useMemo(() => {
        const column = createColumnHelper<StockMovement>();

        return [
            column.display({
                id: 'movement_date',
                header: 'When',
                meta: { label: 'When' },
                cell: (info) => formatDateTime(info.row.original.movement_date),
            }),
            column.display({
                id: 'medicine',
                header: 'Medicine',
                meta: { label: 'Medicine' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.medicine_name}</b>
                        <span className="dr-sub">Batch {info.row.original.batch_number ?? '—'}</span>
                    </div>
                ),
            }),
            column.display({
                id: 'movement_type',
                header: 'What',
                meta: { label: 'What' },
                cell: (info) => (
                    <div>
                        {MOVEMENT_LABELS[info.row.original.movement_type] ?? info.row.original.movement_type}
                        {info.row.original.notes && (
                            <span className="dr-sub d-block">{info.row.original.notes}</span>
                        )}
                    </div>
                ),
            }),
            column.display({
                id: 'quantity',
                header: 'Quantity',
                meta: { label: 'Quantity' },
                cell: (info) => (
                    <span
                        className={`fw-semibold tabular-nums ${info.row.original.quantity > 0 ? 'text-success' : 'text-danger'}`}
                    >
                        {info.row.original.quantity > 0 ? '+' : '−'}
                        {Math.abs(info.row.original.quantity)}
                    </span>
                ),
            }),
            column.display({
                id: 'balance',
                header: 'Balance',
                meta: { label: 'Balance' },
                cell: (info) => (
                    <span className="tabular-nums text-muted">
                        {info.row.original.quantity_before} → <b>{info.row.original.quantity_after}</b>
                    </span>
                ),
            }),
            column.display({
                id: 'reason',
                header: 'Why',
                meta: { label: 'Why' },
                cell: (info) => orDash(info.row.original.reason),
            }),
            column.display({
                id: 'performed_by_name',
                header: 'By',
                meta: { label: 'By' },
                cell: (info) => info.row.original.performed_by_name ?? 'System',
            }),
        ] as ColumnDef<StockMovement, unknown>[];
    }, []);

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'movement_type',
                label: 'What',
                anyLabel: 'Every movement',
                value: filters.movement_type ?? '',
                options: Object.entries(MOVEMENT_LABELS).map(([value, label]) => ({ value, label })),
            },
        ],
        [filters],
    );

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    return (
        <>
            <PageHeader
                title="Stock Movements"
                subtitle="Every change to a store's stock, and what caused it."
                icon="ti ti-list-numbers"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Movements' }]}
            />

            {!store ? (
                <Card>
                    <NoStores />
                </Card>
            ) : (
                <>
                    <div className="mb-3">
                        <StorePicker stores={stores} value={store} onChange={choose} />
                    </div>

                    <FilterPanel
                        fields={filterFields}
                        onChange={(name, value) =>
                            setFilters((current) => {
                                const next = { ...current };

                                if (value === '') {
                                    delete next[name];
                                } else {
                                    next[name] = value;
                                }

                                return next;
                            })
                        }
                        onClear={() => setFilters({})}
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
                            emptyIcon="ti ti-list-numbers"
                            emptyTone="teal"
                            emptyTitle="No movements yet"
                            emptyDescription="Receiving, adjusting, transferring and dispensing all write here."
                        />
                    </Card>
                </>
            )}
        </>
    );
}
