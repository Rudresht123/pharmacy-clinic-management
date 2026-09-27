import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Tabs } from '@/shared/components/ui/Tabs';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { formatDate } from '@/shared/utils/format';
import { PAYMENT_METHOD_LABELS, usePayments, type PaymentRow } from '../api';

const money = (value: number) => `₹${value.toFixed(2)}`;

/** The tender tabs. `all` is not a method — it is the absence of the filter. */
const METHODS = [
    { value: 'all', label: 'All payments' },
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'upi', label: 'UPI' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'other', label: 'Other' },
];

const METHOD_ICONS: Record<string, string> = {
    cash: 'ti ti-cash',
    card: 'ti ti-credit-card',
    upi: 'ti ti-device-mobile',
    bank_transfer: 'ti ti-building-bank',
    cheque: 'ti ti-file-text',
    online: 'ti ti-world',
    other: 'ti ti-dots',
};

/**
 * Every payment taken, newest first — the register a till reconciles from.
 *
 * REFUNDS ARE ROWS HERE, marked rather than hidden. A register that omits
 * them cannot be reconciled against a drawer: the money left, and the sheet
 * has to say so.
 *
 * There is no "Record payment" button. A payment belongs to an invoice, and
 * taking one starts from the bill being settled — on Outstanding, or on the
 * invoice itself. A payment recorded against nothing is how a till and a
 * ledger come to disagree.
 */
export default function PaymentsPage() {
    const [method, setMethod] = useState('all');

    const table = useServerTable({ pageSize: 25, sort: 'paid_at', direction: 'desc' });

    const { data: page, isLoading, isFetching, isError, refetch } = usePayments({
        ...table.params,
        ...(method === 'all' ? {} : { method }),
    });

    const columns = useMemo(() => {
        const column = createColumnHelper<PaymentRow>();

        return [
            column.display({
                id: 'paid_at',
                header: 'Date & time',
                meta: { label: 'Date & time' },
                cell: (info) => (
                    <span className="dr-sub">{formatDate(info.row.original.paid_at)}</span>
                ),
            }),
            column.display({
                id: 'invoice',
                header: 'Invoice #',
                meta: { label: 'Invoice #' },
                cell: (info) => (
                    <Link to={`/billing/invoices/${info.row.original.invoice_id}`}>
                        <b>{info.row.original.invoice_number ?? '—'}</b>
                    </Link>
                ),
            }),
            column.display({
                id: 'patient',
                header: 'Patient',
                meta: { label: 'Patient' },
                cell: (info) => info.row.original.patient_name ?? '—',
            }),
            column.display({
                id: 'amount',
                header: 'Amount',
                meta: { label: 'Amount' },
                cell: (info) => {
                    const row = info.row.original;

                    return (
                        <span className={`tabular-nums fw-bold ${row.is_refund ? 'text-danger' : ''}`}>
                            {row.is_refund ? '−' : ''}
                            {money(row.amount)}
                        </span>
                    );
                },
            }),
            column.display({
                id: 'method',
                header: 'Method',
                meta: { label: 'Method' },
                cell: (info) => {
                    const row = info.row.original;

                    return (
                        <span className="badge bg-light text-dark">
                            <i className={`${METHOD_ICONS[row.method] ?? 'ti ti-dots'} me-1`} />
                            {PAYMENT_METHOD_LABELS[row.method] ?? row.method}
                        </span>
                    );
                },
            }),
            column.display({
                id: 'reference',
                header: 'Reference',
                meta: { label: 'Reference' },
                cell: (info) => info.row.original.reference ?? '—',
            }),
            column.display({
                id: 'kind',
                header: '',
                size: 90,
                cell: (info) =>
                    info.row.original.is_refund ? (
                        <span className="badge bg-danger-subtle text-danger">Refund</span>
                    ) : null,
            }),
        ] as ColumnDef<PaymentRow, unknown>[];
    }, []);

    return (
        <>
            <PageHeader
                title="Payments"
                subtitle="All payment transactions collected against invoices."
                icon="ti ti-cash"
                tone="teal"
                crumbs={[{ label: 'Billing' }, { label: 'Payments' }]}
            />

            <div className="ph-bar">
                <Tabs
                    label="Which tender to show"
                    tabs={METHODS}
                    value={method}
                    onChange={setMethod}
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
                    emptyIcon="ti ti-cash"
                    emptyTone="teal"
                    emptyTitle="No payments yet"
                    emptyDescription="Payments appear here as the counter collects against invoices."
                />
            </Card>
        </>
    );
}
