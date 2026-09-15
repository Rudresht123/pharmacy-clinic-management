import { useMemo, useState } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Button } from '@/shared/components/ui/Button';
import { Tabs } from '@/shared/components/ui/Tabs';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { BatchStatusBadge, ExpiryNote } from '../components/BatchStatusBadge';
import { AdjustDialog, TransferDialog } from '../components/StockDialogs';
import {
    useBatches,
    useBatchStatus,
    useRemoveBatch,
    useStock,
    type MedicineBatch,
    type StockRow,
} from '../inventory';

type View = 'medicines' | 'batches';

type StatusAction = { batch: MedicineBatch; to: 'blocked' | 'recalled' | 'active' | 'remove' };

const ACTION_COPY: Record<StatusAction['to'], { title: string; label: string; danger: boolean; note: string }> = {
    blocked: {
        title: 'Block batch',
        label: 'Block',
        danger: true,
        note: 'It stays on the books and stops being dispensed or transferred until it is unblocked.',
    },
    recalled: {
        title: 'Recall batch',
        label: 'Recall',
        danger: true,
        note: 'For a manufacturer or regulator recall. It stops being dispensed at once.',
    },
    active: {
        title: 'Unblock batch',
        label: 'Unblock',
        danger: false,
        note: 'It can be dispensed again, unless it has run out or expired.',
    },
    remove: {
        title: 'Remove batch',
        label: 'Remove',
        danger: true,
        note: 'Only an empty batch can be removed. Its history stays.',
    },
};

/**
 * A store's stock.
 *
 * Two readings: per medicine — how much there is, how much of it can
 * actually be dispensed, and whether it is low — and per batch, where the
 * actions are. Every action goes through the ledger; nothing on this screen
 * types a quantity into a batch.
 */
export default function StockPage() {
    const { can } = useTenantAuth();
    const canAdjust = can('pharmacy.adjust');
    const canTransfer = can('pharmacy.transfer');
    const canBatches = can('pharmacy.batches');

    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    const [view, setView] = useState<View>('medicines');
    const [filters, setFilters] = useState<Record<string, string>>({ in_stock: '1' });

    const stockTable = useServerTable({ pageSize: 25 });
    const batchTable = useServerTable({ pageSize: 25, sort: 'expiry_date', direction: 'asc' });

    const stock = useStock(store?.id, stockTable.params, view === 'medicines');
    const batches = useBatches(store?.id, { ...batchTable.params, ...filters }, view === 'batches');

    const [adjusting, setAdjusting] = useState<MedicineBatch>();
    const [moving, setMoving] = useState<MedicineBatch>();
    const [acting, setActing] = useState<StatusAction>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const changeStatus = useBatchStatus();
    const remove = useRemoveBatch();

    async function act(reason: string) {
        if (!acting) {
            return;
        }

        try {
            if (acting.to === 'remove') {
                await remove.mutateAsync({ id: acting.batch.id, reason });
            } else {
                await changeStatus.mutateAsync({ id: acting.batch.id, status: acting.to, reason });
            }

            setActing(undefined);
        } catch (failure) {
            setRefusal(resolveErrorMessage(failure));
        }
    }

    function showBatchesOf(row: StockRow) {
        setFilters({ medicine_id: String(row.medicine_id) });
        setView('batches');
    }

    const stockColumns = useMemo(() => {
        const column = createColumnHelper<StockRow>();

        return [
            column.display({
                id: 'medicine',
                header: 'Medicine',
                meta: { label: 'Medicine' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.medicine_name ?? '—'}</b>
                        <span className="dr-sub">Counted in {info.row.original.base_unit ?? 'units'}</span>
                    </div>
                ),
            }),
            column.display({
                id: 'usable',
                header: 'Can dispense',
                meta: { label: 'Can dispense' },
                cell: (info) => (
                    <span className="fw-semibold tabular-nums">
                        {info.row.original.usable}
                        {info.row.original.is_low && (
                            <span className="badge bg-warning-subtle text-warning ms-2">Low</span>
                        )}
                    </span>
                ),
            }),
            column.display({
                id: 'on_hand',
                header: 'On the books',
                meta: { label: 'On the books' },
                // More than can be dispensed when some is blocked or expired.
                cell: (info) => <span className="tabular-nums">{info.row.original.on_hand}</span>,
            }),
            column.display({
                id: 'reorder_level',
                header: 'Reorder at',
                meta: { label: 'Reorder at' },
                cell: (info) => info.row.original.reorder_level ?? '—',
            }),
            column.display({
                id: 'next_expiry',
                header: 'Next expiry',
                meta: { label: 'Next expiry' },
                cell: (info) => formatDate(info.row.original.next_expiry),
            }),
            column.display({
                id: 'actions',
                header: '',
                size: 110,
                cell: (info) => (
                    <Button size="sm" variant="light" onClick={() => showBatchesOf(info.row.original)}>
                        Batches
                    </Button>
                ),
            }),
        ] as ColumnDef<StockRow, unknown>[];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const batchColumns = useMemo(() => {
        const column = createColumnHelper<MedicineBatch>();

        return [
            column.display({
                id: 'batch',
                header: 'Batch',
                meta: { label: 'Batch' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.batch_number}</b>
                        <span className="dr-sub">{info.row.original.medicine?.display_name}</span>
                    </div>
                ),
            }),
            column.display({
                id: 'expiry_date',
                header: 'Expiry',
                meta: { label: 'Expiry' },
                cell: (info) => (
                    <div>
                        {formatDate(info.row.original.expiry_date)}
                        <ExpiryNote days={info.row.original.days_to_expiry} />
                    </div>
                ),
            }),
            column.display({
                id: 'quantity_available',
                header: 'Available',
                meta: { label: 'Available' },
                cell: (info) => (
                    <span className="fw-semibold tabular-nums">{info.row.original.quantity_available}</span>
                ),
            }),
            column.display({
                id: 'mrp',
                header: 'MRP / unit',
                meta: { label: 'MRP / unit' },
                cell: (info) => <span className="tabular-nums">₹{info.row.original.mrp}</span>,
            }),
            column.display({
                id: 'status',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => (
                    <div>
                        <BatchStatusBadge batch={info.row.original} />
                        {info.row.original.blocked_reason && (
                            <small className="d-block text-muted">{info.row.original.blocked_reason}</small>
                        )}
                    </div>
                ),
            }),
            column.display({
                id: 'actions',
                header: 'Action',
                size: 230,
                cell: (info) => {
                    const batch = info.row.original;
                    const blocked = batch.status === 'blocked' || batch.status === 'recalled';

                    return (
                        <div className="d-flex flex-wrap justify-content-end gap-1">
                            {canTransfer && batch.is_usable && batch.quantity_available > 0 && (
                                <Button size="sm" variant="light" icon="ti ti-arrows-exchange" onClick={() => setMoving(batch)}>
                                    Move
                                </Button>
                            )}
                            {canAdjust && (
                                <Button size="sm" variant="light" icon="ti ti-adjustments" onClick={() => setAdjusting(batch)}>
                                    Adjust
                                </Button>
                            )}
                            {canBatches && !blocked && batch.status !== 'expired' && (
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-lock"
                                    onClick={() => {
                                        setRefusal(null);
                                        setActing({ batch, to: 'blocked' });
                                    }}
                                >
                                    Block
                                </Button>
                            )}
                            {canBatches && blocked && (
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-lock-open"
                                    onClick={() => {
                                        setRefusal(null);
                                        setActing({ batch, to: 'active' });
                                    }}
                                >
                                    Unblock
                                </Button>
                            )}
                            {canBatches && batch.status !== 'recalled' && batch.status !== 'expired' && (
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-alert-triangle"
                                    title="Recall"
                                    aria-label="Recall"
                                    onClick={() => {
                                        setRefusal(null);
                                        setActing({ batch, to: 'recalled' });
                                    }}
                                />
                            )}
                            {canBatches && batch.quantity_available === 0 && (
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-trash"
                                    title="Remove"
                                    aria-label="Remove"
                                    onClick={() => {
                                        setRefusal(null);
                                        setActing({ batch, to: 'remove' });
                                    }}
                                />
                            )}
                        </div>
                    );
                },
            }),
        ] as ColumnDef<MedicineBatch, unknown>[];
    }, [canAdjust, canTransfer, canBatches]);

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'status',
                label: 'Status',
                anyLabel: 'Any status',
                value: filters.status ?? '',
                options: [
                    { value: 'active', label: 'Active' },
                    { value: 'blocked', label: 'Blocked' },
                    { value: 'recalled', label: 'Recalled' },
                    { value: 'expired', label: 'Expired' },
                    { value: 'exhausted', label: 'Empty' },
                ],
            },
            {
                kind: 'select',
                name: 'expiring_within',
                label: 'Expiring',
                anyLabel: 'Any time',
                value: filters.expiring_within ?? '',
                options: [
                    { value: '30', label: 'Within 30 days' },
                    { value: '90', label: 'Within 90 days' },
                    { value: '180', label: 'Within 6 months' },
                ],
            },
            {
                kind: 'select',
                name: 'in_stock',
                label: 'Stock',
                anyLabel: 'Empty too',
                value: filters.in_stock ?? '',
                options: [{ value: '1', label: 'In stock only' }],
            },
        ],
        [filters],
    );

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    const copy = acting ? ACTION_COPY[acting.to] : undefined;

    return (
        <>
            <PageHeader
                title="Stock"
                subtitle="What each store holds, what can be dispensed, and what is running low."
                icon="ti ti-packages"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Stock' }]}
            />

            {!store ? (
                <Card>
                    <NoStores />
                </Card>
            ) : (
                <>
                    <div className="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
                        <StorePicker stores={stores} value={store} onChange={choose} />

                        <Tabs<View>
                            label="Stock views"
                            value={view}
                            onChange={setView}
                            tabs={[
                                { value: 'medicines', label: 'By medicine', icon: 'ti ti-pill' },
                                { value: 'batches', label: 'Batches', icon: 'ti ti-stack-2' },
                            ]}
                        />
                    </div>

                    {view === 'medicines' ? (
                        <Card>
                            <DataTable
                                data={stock.data?.data ?? []}
                                columns={stockColumns}
                                loading={stock.isLoading}
                                fetching={stock.isFetching}
                                error={stock.isError}
                                onRetry={stock.refetch}
                                server={{
                                    ...stockTable,
                                    total: stock.data?.meta.total ?? 0,
                                    pageCount: stock.data?.meta.last_page ?? 1,
                                }}
                                searchPlaceholder="Search by generic or brand…"
                                emptyIcon="ti ti-packages"
                                emptyTone="teal"
                                emptyTitle="Nothing in stock here yet"
                                emptyDescription="Stock arrives through Receive Goods: a purchase, or an opening balance for what is already on the shelf."
                            />
                        </Card>
                    ) : (
                        <>
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
                                    data={batches.data?.data ?? []}
                                    columns={batchColumns}
                                    loading={batches.isLoading}
                                    fetching={batches.isFetching}
                                    error={batches.isError}
                                    onRetry={batches.refetch}
                                    server={{
                                        ...batchTable,
                                        total: batches.data?.meta.total ?? 0,
                                        pageCount: batches.data?.meta.last_page ?? 1,
                                    }}
                                    searchPlaceholder="Search by batch number or medicine…"
                                    emptyIcon="ti ti-stack-2"
                                    emptyTone="teal"
                                    emptyTitle="No batches match"
                                    emptyDescription="Try widening or clearing the filters."
                                />
                            </Card>
                        </>
                    )}
                </>
            )}

            <AdjustDialog batch={adjusting} storeId={store?.id} onClose={() => setAdjusting(undefined)} />
            <TransferDialog batch={moving} stores={stores} onClose={() => setMoving(undefined)} />

            <ReasonDialog
                open={acting !== undefined}
                title={`${copy?.title ?? ''} ${acting?.batch.batch_number ?? ''}`}
                subtitle={copy?.note}
                submitLabel={copy?.label ?? 'Save'}
                danger={copy?.danger}
                submitting={changeStatus.isPending || remove.isPending}
                error={refusal}
                onClose={() => setActing(undefined)}
                onSubmit={act}
            />
        </>
    );
}
