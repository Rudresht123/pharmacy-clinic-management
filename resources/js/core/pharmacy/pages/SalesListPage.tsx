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
import { ViewTabs, type PharmacyView } from '../components/ReportSummary';
import { StoreReportDashboard } from '../components/reports/StoreReportDashboard';
import { PAYMENT_STATUS_LABELS, useCancelSale, useSale, useSales, type Sale } from '../sales';
import { CollectSaleDialog, ReturnSaleDialog } from '../components/SaleMoneyDialogs';
import { SaleBillView } from '../components/SaleBillView';

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
    // Whoever works the till may take the rest of a bill left owing.
    const canCollect = canSell || can('pharmacy.dispense');

    const [collecting, setCollecting] = useState<Sale>();
    const [returning, setReturning] = useState<Sale>();

    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    /*
     * Two ways to read the same screen: the shape of it, or the rows.
     * Kept in the URL beside the store, so a refresh — or a link sent to
     * somebody — lands on the view it was left on.
     */
    const [params, setParams] = useSearchParams();
    /*
     * Dashboard first, table second.
     *
     * The shape of a screen is what somebody wants on arriving; the rows are
     * what they drill into once a figure raises a question. PharmacyReportsPage
     * already read it this way, and the rest disagreeing meant the same control
     * opened differently depending on which screen you were on.
     */
    const view: PharmacyView = params.get('view') === 'table' ? 'table' : 'dashboard';

    function show(next: PharmacyView) {
        const updated = new URLSearchParams(params);
        updated.set('view', next);
        setParams(updated, { replace: true });
    }

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
                        <Button icon="ti ti-building-store" onClick={() => navigate(`/pharmacy/pos?store=${store.id}`)}>
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
                        <StoreReportDashboard storeId={store.id} report="sales" onShowTable={() => show('table')} />
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

            {/*
             * Steps aside while one of its own dialogs is open — payment,
             * return or cancellation — rather than sitting half-covered under
             * it, and comes back afterwards showing the bill as it now stands.
             */}
            <Modal
                open={openId !== undefined && !collecting && !returning && !cancelling}
                onClose={() => setOpenId(undefined)}
                title={open ? open.sale_number : 'Bill'}
                subtitle={open ? `Pharmacy bill · ${formatDate(open.sale_date)}` : undefined}
                icon={<i className="ti ti-receipt" />}
                size="xl"
                footer={
                    open && open.status === 'completed' ? (
                        <div className="d-flex justify-content-end gap-2 flex-wrap w-100">
                            {canCancel && (
                                <Button variant="outline" icon="ti ti-ban" className="me-auto sb-cancel" onClick={() => setCancelling(open)}>
                                    Cancel this bill
                                </Button>
                            )}
                            {canCancel &&
                                open.payment_status !== 'billed_via_invoice' &&
                                (open.items ?? []).some((item) => item.quantity > (item.returned_quantity ?? 0)) && (
                                    <Button variant="outline" icon="ti ti-arrow-back-up" onClick={() => setReturning(open)}>
                                        Take a return
                                    </Button>
                                )}
                            {canCollect && open.amount_due > 0 && open.payment_status !== 'billed_via_invoice' && (
                                <Button icon="ti ti-cash" onClick={() => setCollecting(open)}>
                                    Take payment · {money(open.amount_due)}
                                </Button>
                            )}
                        </div>
                    ) : undefined
                }
            >
                {openLoading || !open ? <LoadingBlock label="Loading…" /> : <SaleBillView sale={open} />}
            </Modal>

            <CollectSaleDialog sale={collecting} onClose={() => setCollecting(undefined)} />
            <ReturnSaleDialog sale={returning} onClose={() => setReturning(undefined)} />

            <ReasonDialog
                open={cancelling !== undefined}
                title={`Cancel ${cancelling?.sale_number ?? ''}`}
                subtitle="Everything on it goes back on the shelf, and any money taken is recorded as handed back. The bill stays on the record, marked cancelled."
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
