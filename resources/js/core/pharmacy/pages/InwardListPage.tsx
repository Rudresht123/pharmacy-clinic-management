import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate, orDash } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import {
    INWARD_TYPE_LABELS,
    useCancelInward,
    useInward,
    useInwards,
    type StockInward,
} from '../inventory';

/**
 * Goods received at a store, newest first.
 *
 * A note is posted when it is saved and cancelled, never deleted. Cancelling
 * takes its stock back off the books, and is refused once any of it has left.
 */
export default function InwardListPage() {
    const navigate = useNavigate();

    const { can } = useTenantAuth();
    const canReceive = can('pharmacy.inward');
    const canCancel = can('pharmacy.adjust');

    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    const table = useServerTable({ pageSize: 25, sort: 'created_at', direction: 'desc' });
    const { data: page, isLoading, isFetching, isError, refetch } = useInwards(store?.id, table.params);

    const [openId, setOpenId] = useState<number>();
    const { data: open, isLoading: openLoading } = useInward(openId);

    const cancel = useCancelInward();
    const [cancelling, setCancelling] = useState<StockInward>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const columns = useMemo(() => {
        const column = createColumnHelper<StockInward>();

        return [
            column.display({
                id: 'inward_number',
                header: 'Note',
                meta: { label: 'Note' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.inward_number}</b>
                        <span className="dr-sub">{INWARD_TYPE_LABELS[info.row.original.inward_type]}</span>
                    </div>
                ),
            }),
            column.display({
                id: 'received_date',
                header: 'Received',
                meta: { label: 'Received' },
                cell: (info) => formatDate(info.row.original.received_date),
            }),
            column.display({
                id: 'supplier',
                header: 'Supplier',
                meta: { label: 'Supplier' },
                cell: (info) => (
                    <div>
                        {orDash(info.row.original.supplier_name ?? null)}
                        {info.row.original.supplier_invoice_no && (
                            <span className="dr-sub d-block">Invoice {info.row.original.supplier_invoice_no}</span>
                        )}
                    </div>
                ),
            }),
            column.display({
                id: 'items_count',
                header: 'Lines',
                meta: { label: 'Lines' },
                cell: (info) => info.row.original.items_count ?? 0,
            }),
            column.display({
                id: 'total_amount',
                header: 'Total',
                meta: { label: 'Total' },
                cell: (info) => <span className="tabular-nums">₹{info.row.original.total_amount}</span>,
            }),
            column.display({
                id: 'status',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) =>
                    info.row.original.status === 'cancelled' ? (
                        <span className="badge bg-danger-subtle text-danger">Cancelled</span>
                    ) : (
                        <span className="badge bg-success-subtle text-success">Posted</span>
                    ),
            }),
            column.display({
                id: 'actions',
                header: '',
                size: 90,
                cell: (info) => (
                    <Button size="sm" variant="light" onClick={() => setOpenId(info.row.original.id)}>
                        View
                    </Button>
                ),
            }),
        ] as ColumnDef<StockInward, unknown>[];
    }, []);

    async function confirmCancel(reason: string) {
        if (!cancelling) {
            return;
        }

        try {
            await cancel.mutateAsync({ id: cancelling.id, reason });
            setCancelling(undefined);
            setOpenId(undefined);
        } catch (failure) {
            setRefusal(resolveErrorMessage(failure));
        }
    }

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    return (
        <>
            <PageHeader
                title="Receive Goods"
                subtitle="Purchases, opening balances and returns, as goods received notes."
                icon="ti ti-truck-delivery"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Receive Goods' }]}
                actions={
                    canReceive && store ? (
                        <Button
                            icon="ti ti-plus"
                            onClick={() => navigate(`/pharmacy/inwards/create?store=${store.id}`)}
                        >
                            Receive Goods
                        </Button>
                    ) : undefined
                }
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
                            searchPlaceholder="Search by note or invoice number…"
                            emptyIcon="ti ti-truck-delivery"
                            emptyTone="teal"
                            emptyTitle="Nothing received here yet"
                            emptyDescription="Record a delivery, or an opening balance for what is already on the shelf."
                        />
                    </Card>
                </>
            )}

            <Modal
                open={openId !== undefined}
                onClose={() => setOpenId(undefined)}
                title={open ? `${open.inward_number} · ${INWARD_TYPE_LABELS[open.inward_type]}` : 'Goods received'}
                size="lg"
            >
                {openLoading || !open ? (
                    <LoadingBlock label="Loading…" />
                ) : (
                    <>
                        <dl className="row mb-3 fs-13">
                            <dt className="col-4">Received</dt>
                            <dd className="col-8">
                                {formatDate(open.received_date)} by {open.created_by_name ?? '—'}
                            </dd>
                            <dt className="col-4">Supplier</dt>
                            <dd className="col-8">
                                {open.supplier_name ?? '—'}
                                {open.supplier_invoice_no && ` · invoice ${open.supplier_invoice_no}`}
                            </dd>
                            {open.status === 'cancelled' && (
                                <>
                                    <dt className="col-4">Cancelled</dt>
                                    <dd className="col-8 text-danger">{open.cancellation_reason}</dd>
                                </>
                            )}
                        </dl>

                        <div className="pf-table-wrap">
                            <table className="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th>Batch</th>
                                        <th>Expiry</th>
                                        <th className="text-end">Qty + free</th>
                                        <th className="text-end">MRP / unit</th>
                                        <th className="text-end">Line total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(open.items ?? []).map((item) => (
                                        <tr key={item.id}>
                                            <td>{item.medicine_name}</td>
                                            <td>{item.batch_number}</td>
                                            <td>{formatDate(item.expiry_date)}</td>
                                            <td className="text-end tabular-nums">
                                                {item.quantity}
                                                {item.free_quantity > 0 && ` + ${item.free_quantity}`}
                                            </td>
                                            <td className="text-end tabular-nums">₹{item.mrp}</td>
                                            <td className="text-end tabular-nums">₹{item.line_total}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="d-flex justify-content-between align-items-center mt-3">
                            <b>Total ₹{open.total_amount}</b>

                            {canCancel && open.status === 'posted' && (
                                <Button
                                    variant="light"
                                    icon="ti ti-ban"
                                    onClick={() => {
                                        setRefusal(null);
                                        setCancelling(open);
                                    }}
                                >
                                    Cancel this receipt
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </Modal>

            <ReasonDialog
                open={cancelling !== undefined}
                title={`Cancel ${cancelling?.inward_number ?? ''}?`}
                subtitle="Its stock comes back off the books. Refused if any of it has already left the store."
                submitLabel="Cancel receipt"
                danger
                submitting={cancel.isPending}
                error={refusal}
                onClose={() => setCancelling(undefined)}
                onSubmit={confirmCancel}
            />
        </>
    );
}
