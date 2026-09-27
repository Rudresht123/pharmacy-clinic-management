import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Button } from '@/shared/components/ui/Button';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { PAYMENT_STATUS_LABELS, useInvoices, type Invoice } from '../api';
import { PaymentDialog } from '../components/PaymentDialog';

const money = (value: number) => `₹${value.toFixed(2)}`;

/** How long the money has been owed — the column that drives chasing. */
function daysSince(iso: string | null): number {
    if (!iso) return 0;

    const then = new Date(iso).getTime();

    return Math.max(0, Math.floor((Date.now() - then) / 86_400_000));
}

/**
 * The work queue: bills with money still on them.
 *
 * Sorted oldest-first by default rather than newest, because this is the
 * screen somebody works THROUGH — the debt that has been sitting longest is
 * the one worth a phone call, and a list that buries it under today's
 * part-payments is a list nobody finishes.
 *
 * Drafts never appear. A visit still collecting charges owes nothing yet;
 * putting it here would send somebody to chase a patient who has not been
 * billed.
 */
export default function OutstandingPage() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();
    const canCollect = can('billing.collect_payment');

    const [paying, setPaying] = useState<Invoice>();

    const table = useServerTable({ pageSize: 25, sort: 'invoice_date', direction: 'asc' });

    const { data: page, isLoading, isFetching, isError, refetch } = useInvoices({
        ...table.params,
        outstanding_only: true,
    });

    const columns = useMemo(() => {
        const column = createColumnHelper<Invoice>();

        return [
            column.display({
                id: 'invoice_number',
                header: 'Invoice #',
                meta: { label: 'Invoice #' },
                cell: (info) => (
                    <Link to={`/billing/invoices/${info.row.original.id}`}>
                        <b>{info.row.original.invoice_number}</b>
                    </Link>
                ),
            }),
            column.display({
                id: 'invoice_date',
                header: 'Date',
                meta: { label: 'Date' },
                cell: (info) => (
                    <span className="dr-sub">{formatDate(info.row.original.invoice_date)}</span>
                ),
            }),
            column.display({
                id: 'patient',
                header: 'Patient',
                meta: { label: 'Patient' },
                cell: (info) => info.row.original.customer_name ?? '—',
            }),
            column.display({
                id: 'total_amount',
                header: 'Total',
                meta: { label: 'Total' },
                cell: (info) => (
                    <span className="tabular-nums">{money(info.row.original.total_amount)}</span>
                ),
            }),
            column.display({
                id: 'paid_amount',
                header: 'Paid',
                meta: { label: 'Paid' },
                cell: (info) => (
                    <span className="tabular-nums">{money(info.row.original.paid_amount)}</span>
                ),
            }),
            column.display({
                id: 'outstanding',
                header: 'Outstanding',
                meta: { label: 'Outstanding' },
                cell: (info) => (
                    <span className="tabular-nums fw-bold text-danger">
                        {money(info.row.original.outstanding)}
                    </span>
                ),
            }),
            column.display({
                id: 'days',
                header: 'Days',
                meta: { label: 'Days' },
                cell: (info) => {
                    const days = daysSince(info.row.original.invoice_date);
                    /* A week is the point at which a desk starts chasing. */
                    const tone = days >= 7 ? 'danger' : days >= 3 ? 'warning' : 'secondary';

                    return (
                        <span className={`badge bg-${tone}-subtle text-${tone}`}>{days}</span>
                    );
                },
            }),
            column.display({
                id: 'status',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => {
                    const invoice = info.row.original;
                    const tone = invoice.payment_status === 'partial' ? 'warning' : 'danger';

                    return (
                        <span className={`badge bg-${tone}-subtle text-${tone}`}>
                            {PAYMENT_STATUS_LABELS[invoice.payment_status] ??
                                invoice.payment_status}
                        </span>
                    );
                },
            }),
            column.display({
                id: 'actions',
                header: '',
                size: 190,
                cell: (info) => (
                    <div className="d-flex gap-1 justify-content-end">
                        {canCollect && (
                            <Button
                                size="sm"
                                icon="ti ti-cash"
                                onClick={() => setPaying(info.row.original)}
                            >
                                Take payment
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="light"
                            onClick={() => navigate(`/billing/invoices/${info.row.original.id}`)}
                        >
                            View
                        </Button>
                    </div>
                ),
            }),
        ] as ColumnDef<Invoice, unknown>[];
    }, [navigate, canCollect]);

    const owed = (page?.data ?? []).reduce((sum, invoice) => sum + invoice.outstanding, 0);

    return (
        <>
            <PageHeader
                title="Outstanding"
                subtitle="Invoices with pending or partially paid amounts."
                icon="ti ti-clock-dollar"
                tone="amber"
                crumbs={[{ label: 'Billing' }, { label: 'Outstanding' }]}
                actions={
                    owed > 0 ? (
                        <span className="badge bg-danger-subtle text-danger fs-6">
                            {money(owed)} on this page
                        </span>
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
                    searchPlaceholder="Search by patient, phone or invoice…"
                    emptyIcon="ti ti-circle-check"
                    emptyTone="emerald"
                    emptyTitle="Nothing outstanding"
                    emptyDescription="Every bill raised so far has been settled."
                />
            </Card>

            <PaymentDialog
                invoice={paying}
                onClose={() => setPaying(undefined)}
                onPaid={() => {
                    setPaying(undefined);
                    void refetch();
                }}
            />
        </>
    );
}
