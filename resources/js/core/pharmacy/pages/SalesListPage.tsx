import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { ReportSummaryView, ViewTabs, type PharmacyView } from '../components/ReportSummary';
import { useReportSummary } from '../reports';
import { PAYMENT_STATUS_LABELS, useCancelSale, useSale, useSales, type Sale } from '../sales';

const money = (value: number) => `₹${value.toFixed(2)}`;

/**
 * The bills a store has issued, newest first.
 *
 * A bill is never deleted. A mistake is cancelled, which puts its stock back
 * on the shelf as correcting ledger rows and leaves the bill on the record —
 * which is what makes the day's takings reconcilable afterwards.
 */
export default function SalesListPage() {
    const navigate = useNavigate();

    const { can } = useTenantAuth();
    const canSell = can('pharmacy.sell');
    const canCancel = can('pharmacy.sale_cancel');

    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    /*
     * Two ways to read the same screen: the shape of it, or the rows.
     * Kept in the URL beside the store, so a refresh — or a link sent to
     * somebody — lands on the view it was left on.
     */
    const [params, setParams] = useSearchParams();
    const view: PharmacyView = params.get('view') === 'dashboard' ? 'dashboard' : 'table';

    function show(next: PharmacyView) {
        const updated = new URLSearchParams(params);
        updated.set('view', next);
        setParams(updated, { replace: true });
    }

    const summary = useReportSummary(store?.id, 'sales', {});

    const table = useServerTable({ pageSize: 25, sort: 'sale_date', direction: 'desc' });
    const { data: page, isLoading, isFetching, isError, refetch } = useSales(store?.id, table.params);

    const [openId, setOpenId] = useState<number>();
    const { data: open, isLoading: openLoading } = useSale(openId);

    const cancel = useCancelSale();
    const [cancelling, setCancelling] = useState<Sale>();
    const [refusal, setRefusal] = useState<string | null>(null);

    const columns = useMemo(() => {
        const column = createColumnHelper<Sale>();

        return [
            column.display({
                id: 'sale_number',
                header: 'Bill',
                meta: { label: 'Bill' },
                cell: (info) => (
                    <div>
                        <b className="d-block">{info.row.original.sale_number}</b>
                        <span className="dr-sub">{formatDate(info.row.original.sale_date)}</span>
                    </div>
                ),
            }),
            column.display({
                id: 'customer',
                header: 'Sold to',
                meta: { label: 'Sold to' },
                cell: (info) => (
                    <div>
                        {info.row.original.customer_name}
                        {info.row.original.is_walk_in && <span className="dr-sub d-block">Walk-in</span>}
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
                cell: (info) => <span className="tabular-nums">{money(info.row.original.total_amount)}</span>,
            }),
            column.display({
                id: 'payment_status',
                header: 'Payment',
                meta: { label: 'Payment' },
                cell: (info) => {
                    const sale = info.row.original;
                    const tone = sale.payment_status === 'paid' ? 'success' : 'warning';

                    return (
                        <div>
                            <span className={`badge bg-${tone}-subtle text-${tone}`}>
                                {PAYMENT_STATUS_LABELS[sale.payment_status]}
                            </span>
                            {sale.amount_due > 0 && (
                                <span className="dr-sub d-block">{money(sale.amount_due)} owed</span>
                            )}
                        </div>
                    );
                },
            }),
            column.display({
                id: 'status',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) =>
                    info.row.original.status === 'cancelled' ? (
                        <span className="badge bg-danger-subtle text-danger">Cancelled</span>
                    ) : (
                        <span className="badge bg-success-subtle text-success">Completed</span>
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
        ] as ColumnDef<Sale, unknown>[];
    }, []);

    async function confirmCancel(reason: string) {
        if (!cancelling) {
            return;
        }

        setRefusal(null);

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
                title="Bills"
                subtitle="Everything sold at this store, counter sales and dispensings alike."
                icon="ti ti-receipt"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Bills' }]}
                actions={
                    canSell && store ? (
                        <Button icon="ti ti-cash-register" onClick={() => navigate(`/pharmacy/pos?store=${store.id}`)}>
                            Open the counter
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
                    <div className="ph-bar">
                        <ViewTabs value={view} onChange={show} />
                        <StorePicker stores={stores} value={store} onChange={choose} />
                    </div>

                    {view === 'dashboard' ? (
                        <ReportSummaryView
                            summary={summary.data}
                            isLoading={summary.isLoading}
                            isError={summary.isError}
                            onRetry={() => summary.refetch()}
                            chartTitle="Takings, day by day"
                        />
                    ) : (
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
                            searchPlaceholder="Search by bill number, name or phone…"
                            emptyIcon="ti ti-receipt"
                            emptyTone="teal"
                            emptyTitle="Nothing sold here yet"
                            emptyDescription="Bills appear here as soon as the counter starts selling."
                        />
                    </Card>
                    )}
                </>
            )}

            <Modal
                open={openId !== undefined}
                onClose={() => setOpenId(undefined)}
                title={open ? open.sale_number : 'Bill'}
                size="lg"
            >
                {openLoading || !open ? (
                    <LoadingBlock label="Loading…" />
                ) : (
                    <>
                        <dl className="row mb-3 fs-13">
                            <dt className="col-4">Sold</dt>
                            <dd className="col-8">
                                {formatDate(open.sale_date)} by {open.created_by_name ?? '—'}
                            </dd>
                            <dt className="col-4">Sold to</dt>
                            <dd className="col-8">
                                {open.customer_name}
                                {open.walk_in_phone && ` · ${open.walk_in_phone}`}
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
                                        <th>Item</th>
                                        <th>Batch</th>
                                        <th className="text-end">Qty</th>
                                        <th className="text-end">Price</th>
                                        <th className="text-end">GST</th>
                                        <th className="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(open.items ?? []).map((item) => (
                                        <tr key={item.id}>
                                            <td>
                                                {item.item_name}
                                                {item.hsn_code && <span className="dr-sub d-block">HSN {item.hsn_code}</span>}
                                            </td>
                                            <td>
                                                {item.batch_number}
                                                <span className="dr-sub d-block">{formatDate(item.expiry_date)}</span>
                                            </td>
                                            <td className="text-end tabular-nums">{item.quantity}</td>
                                            <td className="text-end tabular-nums">{money(item.unit_price)}</td>
                                            <td className="text-end tabular-nums">
                                                {money(item.tax_amount)}
                                                <span className="dr-sub d-block">{item.tax_rate}%</span>
                                            </td>
                                            <td className="text-end tabular-nums">{money(item.line_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <dl className="row mt-3 mb-0 fs-13">
                            <dt className="col-8 text-end">Goods</dt>
                            <dd className="col-4 text-end tabular-nums">{money(open.subtotal)}</dd>
                            <dt className="col-8 text-end">GST</dt>
                            <dd className="col-4 text-end tabular-nums">{money(open.tax_amount)}</dd>
                            {open.round_off !== 0 && (
                                <>
                                    <dt className="col-8 text-end">Round off</dt>
                                    <dd className="col-4 text-end tabular-nums">{money(open.round_off)}</dd>
                                </>
                            )}
                            <dt className="col-8 text-end fw-bold">Total</dt>
                            <dd className="col-4 text-end tabular-nums fw-bold">{money(open.total_amount)}</dd>
                            <dt className="col-8 text-end">Paid</dt>
                            <dd className="col-4 text-end tabular-nums">{money(open.paid_amount)}</dd>
                        </dl>

                        {canCancel && open.status === 'completed' && (
                            <div className="mt-3 text-end">
                                <Button variant="danger" size="sm" icon="ti ti-ban" onClick={() => setCancelling(open)}>
                                    Cancel this bill
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </Modal>

            <ReasonDialog
                open={cancelling !== undefined}
                title={`Cancel ${cancelling?.sale_number ?? ''}`}
                subtitle="Everything on it goes back on the shelf. The bill stays on the record, marked cancelled."
                submitLabel="Cancel bill"
                danger
                submitting={cancel.isPending}
                error={refusal}
                onClose={() => setCancelling(undefined)}
                onSubmit={(reason) => void confirmCancel(reason)}
            />
        </>
    );
}
