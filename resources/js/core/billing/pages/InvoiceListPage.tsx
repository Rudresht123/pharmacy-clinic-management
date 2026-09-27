import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Button } from '@/shared/components/ui/Button';
import { Tabs } from '@/shared/components/ui/Tabs';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate } from '@/shared/utils/format';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { openDocument, useGenerateDocument } from '@/core/documents/api';
import {
    INVOICE_STATUS_LABELS,
    PAYMENT_STATUS_LABELS,
    useFinalizeInvoice,
    useInvoices,
    type Invoice,
} from '../api';
import { PaymentDialog } from '../components/PaymentDialog';

const money = (value: number) => `₹${value.toFixed(2)}`;

/**
 * What the billing counter works from.
 *
 * A COLLECTION screen, not a creation one. Invoices are produced by the
 * clinic's own events — a consultation finishing, a lab signing off, the
 * counter dispensing — and consolidated onto one bill per visit by the
 * server. Nobody at this desk types a consultation line by hand.
 *
 * The three views are the three questions somebody at the till actually
 * asks: who owes me money, what is still open, and what did we take today.
 *
 *   OUTSTANDING  finalized bills with money left on them — the work queue
 *   OPEN         visits still collecting charges, so the desk can see who is
 *                mid-visit and close a bill when the patient is ready to go
 *   ALL          the register
 */
type View = 'outstanding' | 'open' | 'all';

const VIEWS: { value: View; label: string }[] = [
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'open', label: 'Open visits' },
    { value: 'all', label: 'All' },
];

function isView(value: string | null): value is View {
    return value === 'outstanding' || value === 'open' || value === 'all';
}

export default function InvoiceListPage() {
    const navigate = useNavigate();
    const { can } = useTenantAuth();
    const canCreate = can('billing.create');
    const canCollect = can('billing.collect_payment');
    const canPrint = can('documents.generate');

    const [params, setParams] = useSearchParams();
    const asked = params.get('view');
    const view: View = isView(asked) ? asked : 'outstanding';

    function show(next: View) {
        const updated = new URLSearchParams(params);

        if (next === 'outstanding') updated.delete('view');
        else updated.set('view', next);

        setParams(updated, { replace: true });
    }

    const table = useServerTable({ pageSize: 25, sort: 'invoice_date', direction: 'desc' });

    const filters = useMemo(() => {
        if (view === 'outstanding') return { outstanding_only: true };
        if (view === 'open') return { include_drafts: true, status: 'draft' };

        return { include_drafts: true };
    }, [view]);

    const { data: page, isLoading, isFetching, isError, refetch } = useInvoices({
        ...table.params,
        ...filters,
    });

    const [paying, setPaying] = useState<Invoice>();
    const [error, setError] = useState<string | null>(null);

    const finalize = useFinalizeInvoice();
    const generate = useGenerateDocument();

    async function finalizeOne(invoice: Invoice) {
        setError(null);

        try {
            const ready = await finalize.mutateAsync(invoice.id);
            // Straight into taking the money — the patient is at the desk.
            setPaying(ready);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    async function printOne(invoice: Invoice) {
        setError(null);

        try {
            const document_ = await generate.mutateAsync({
                document_type: 'clinic_invoice',
                subject_id: invoice.id,
            });

            await openDocument(document_);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    const columns = useMemo(() => {
        const column = createColumnHelper<Invoice>();

        return [
            column.display({
                id: 'invoice_number',
                header: 'Invoice',
                meta: { label: 'Invoice' },
                cell: (info) => {
                    const invoice = info.row.original;

                    return (
                        <div>
                            <b className="d-block">{invoice.invoice_number}</b>
                            <span className="dr-sub">
                                {formatDate(invoice.invoice_date)}
                                {invoice.kind !== 'visit' && (
                                    <span className="badge bg-secondary-subtle text-secondary ms-1">
                                        {invoice.kind === 'registration' ? 'Registration' : 'Manual'}
                                    </span>
                                )}
                            </span>
                        </div>
                    );
                },
            }),
            column.display({
                id: 'customer',
                header: 'Patient',
                meta: { label: 'Patient' },
                cell: (info) => (
                    <div>
                        {info.row.original.customer_name ?? '—'}
                        {info.row.original.is_walk_in && (
                            <span className="dr-sub d-block">Walk-in</span>
                        )}
                    </div>
                ),
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
                cell: (info) => {
                    const { outstanding } = info.row.original;

                    return (
                        <span className={`tabular-nums ${outstanding > 0 ? 'text-danger fw-bold' : ''}`}>
                            {money(outstanding)}
                        </span>
                    );
                },
            }),
            column.display({
                id: 'status',
                header: 'Status',
                meta: { label: 'Status' },
                cell: (info) => {
                    const invoice = info.row.original;

                    if (invoice.is_draft) {
                        return (
                            <span className="badge bg-warning-subtle text-warning">
                                Collecting charges
                            </span>
                        );
                    }

                    const tone =
                        invoice.status === 'cancelled'
                            ? 'danger'
                            : invoice.payment_status === 'paid'
                              ? 'success'
                              : invoice.payment_status === 'partial'
                                ? 'warning'
                                : 'secondary';

                    return (
                        <span className={`badge bg-${tone}-subtle text-${tone}`}>
                            {invoice.status === 'cancelled'
                                ? INVOICE_STATUS_LABELS.cancelled
                                : (PAYMENT_STATUS_LABELS[invoice.payment_status] ??
                                  invoice.payment_status)}
                        </span>
                    );
                },
            }),
            column.display({
                id: 'actions',
                header: '',
                size: 220,
                cell: (info) => {
                    const invoice = info.row.original;

                    return (
                        <div className="d-flex gap-1 justify-content-end">
                            <Button
                                size="sm"
                                variant="light"
                                onClick={() => navigate(`/billing/invoices/${invoice.id}`)}
                            >
                                View
                            </Button>

                            {/* A draft cannot be paid — it is closed first, and
                                the dialog opens on the total that results. */}
                            {invoice.is_draft && canCreate && (
                                <Button
                                    size="sm"
                                    icon="ti ti-lock-check"
                                    onClick={() => void finalizeOne(invoice)}
                                    disabled={finalize.isPending}
                                >
                                    Close bill
                                </Button>
                            )}

                            {!invoice.is_draft &&
                                canCollect &&
                                invoice.outstanding > 0 &&
                                invoice.status !== 'cancelled' && (
                                    <Button
                                        size="sm"
                                        icon="ti ti-cash"
                                        onClick={() => setPaying(invoice)}
                                    >
                                        Take payment
                                    </Button>
                                )}

                            {!invoice.is_draft && canPrint && invoice.status !== 'cancelled' && (
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-printer"
                                    aria-label="Print invoice"
                                    onClick={() => void printOne(invoice)}
                                    disabled={generate.isPending}
                                />
                            )}
                        </div>
                    );
                },
            }),
        ] as ColumnDef<Invoice, unknown>[];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [navigate, canCollect, canCreate, canPrint, finalize.isPending, generate.isPending]);

    return (
        <>
            <PageHeader
                title="Invoices"
                subtitle="Bills raised by the clinic's own work — consultations, tests, procedures and medicines on one bill per visit."
                icon="ti ti-file-invoice"
                tone="teal"
                crumbs={[{ label: 'Billing', to: '/billing' }, { label: 'Invoices' }]}
                actions={
                    canCreate ? (
                        /*
                         * SECONDARY, deliberately. Invoices come from the
                         * workflow; this is the exception hatch for a
                         * certificate or a records fee, not the way a
                         * consultation gets billed.
                         */
                        <Button
                            variant="light"
                            icon="ti ti-plus"
                            onClick={() => navigate('/billing/invoices/new')}
                        >
                            Manual bill
                        </Button>
                    ) : undefined
                }
            />

            {error && <div className="alert alert-danger py-2">{error}</div>}

            <div className="ph-bar">
                <Tabs
                    label="Which bills to show"
                    tabs={VIEWS.map((entry) => ({ value: entry.value, label: entry.label }))}
                    value={view}
                    onChange={(next) => show(next as View)}
                />
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
                    searchPlaceholder="Search by invoice number, name or phone…"
                    emptyIcon="ti ti-receipt"
                    emptyTone="teal"
                    emptyTitle={
                        view === 'outstanding'
                            ? 'Nothing outstanding'
                            : view === 'open'
                              ? 'No visits collecting charges'
                              : 'No invoices yet'
                    }
                    emptyDescription={
                        view === 'outstanding'
                            ? 'Every bill raised so far has been settled.'
                            : view === 'open'
                              ? 'A bill appears here while a visit is still producing charges.'
                              : 'Invoices appear here as the clinic bills its work.'
                    }
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
