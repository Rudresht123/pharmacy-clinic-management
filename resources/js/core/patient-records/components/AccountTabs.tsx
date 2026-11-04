import { Fragment, useState } from 'react';
import { Link } from 'react-router-dom';
import { INVOICE_KIND_LABELS, PAYMENT_METHOD_LABELS, useInvoices, usePayments } from '@/core/billing/api';
import { DocumentsPanel } from '@/core/documents/components/DocumentsPanel';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { usePatientPharmacy } from '../api';
import { clock, day, invoicePill, rupees, salePill } from '../labels';
import type { PatientRecordSummary } from '../types';
import { Empty, Loading, Pager, Pill, RecordCard, TableFrame } from './Bits';

const PER_PAGE = 10;

/*
|--------------------------------------------------------------------------
| Pharmacy
|--------------------------------------------------------------------------
*/

/** What the patient bought at the counter, newest first, with what was on each bill. */
export function PharmacyTab({ patientId }: { patientId: number }) {
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState<number | null>(null);
    const { data, isLoading, isFetching } = usePatientPharmacy(patientId, { page, per_page: PER_PAGE });

    const rows = data?.data ?? [];

    return (
        <RecordCard title="Pharmacy History" icon="ti ti-pill">
            {isLoading ? (
                <Loading />
            ) : rows.length === 0 ? (
                <Empty
                    icon="ti ti-shopping-cart-off"
                    title="No pharmacy purchases"
                    line="Bills raised for this patient at your pharmacy counters appear here."
                />
            ) : (
                <div className={cn(isFetching && 'is-refreshing')}>
                    <TableFrame>
                        <thead>
                            <tr>
                                <th>Bill</th>
                                <th>Date</th>
                                <th>Store</th>
                                <th>Items</th>
                                <th className="ptr-num">Amount</th>
                                <th className="ptr-num">Due</th>
                                <th>Status</th>
                                <th aria-label="Open" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((sale) => (
                                <Fragment key={sale.id}>
                                    <tr
                                        className="ptr-openable"
                                        onClick={() => setOpen((current) => (current === sale.id ? null : sale.id))}
                                    >
                                        <td className="ptr-strong">{sale.sale_number}</td>
                                        <td className="ptr-date">
                                            {day(sale.sale_date)}
                                            <small>{clock(sale.sale_date)}</small>
                                        </td>
                                        <td>{sale.store_name ?? '—'}</td>
                                        <td>{sale.items_count ?? sale.items?.length ?? '—'}</td>
                                        <td className="ptr-num ptr-strong">{rupees(sale.total_amount)}</td>
                                        <td className={cn('ptr-num', sale.amount_due > 0 && 'ptr-owed')}>
                                            {sale.amount_due > 0 ? rupees(sale.amount_due) : '—'}
                                        </td>
                                        <td>
                                            <Pill pill={salePill(sale.status, sale.payment_status)} />
                                        </td>
                                        <td className="ptr-end">
                                            <i
                                                className={cn('ti ti-chevron-down ptr-chevron', open === sale.id && 'is-open')}
                                                aria-hidden="true"
                                            />
                                        </td>
                                    </tr>
                                    {open === sale.id && (
                                        <tr className="ptr-detail-row">
                                            <td colSpan={8}>
                                                <ul className="ptr-lines">
                                                    {(sale.items ?? []).map((item) => (
                                                        <li key={item.id}>
                                                            <b>{item.item_name}</b>
                                                            {item.quantity} × {rupees(item.unit_price)}
                                                            {item.batch_number && <small> · Batch {item.batch_number}</small>}
                                                            <span className="ptr-line-total">{rupees(item.line_total)}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                                {sale.doctor_name && <p className="ptr-note">Prescribed by {sale.doctor_name}</p>}
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </TableFrame>

                    <Pager
                        page={page}
                        pageCount={data?.meta.last_page ?? 1}
                        onChange={setPage}
                        perPage={PER_PAGE}
                        total={data?.meta.total}
                    />
                </div>
            )}
        </RecordCard>
    );
}

/*
|--------------------------------------------------------------------------
| Billing and payments — Billing's own lists, for one patient
|--------------------------------------------------------------------------
*/

export function BillingTab({ record }: { record: PatientRecordSummary }) {
    const { can } = useTenantAuth();
    const patientId = record.patient.id;
    const [invoicePage, setInvoicePage] = useState(1);
    const [paymentPage, setPaymentPage] = useState(1);

    const invoices = useInvoices({
        customer_id: patientId,
        page: invoicePage,
        per_page: PER_PAGE,
        sort: 'invoice_date',
        direction: 'desc',
    });
    // The Payments screen's own tick: a role without it sees the bills but not the register.
    const seesPayments = can('billing.payments_view');
    const payments = usePayments({ customer_id: patientId, page: paymentPage, per_page: PER_PAGE }, seesPayments);

    // Invoices carry a branch id; the record already knows this patient's branches by name.
    const branch = new Map(record.branches.map((entry) => [entry.id, entry.name]));

    const money = record.billing;
    const figures = [
        { label: 'Total billed', value: money?.billed, tone: 'blue', icon: 'ti ti-file-invoice' },
        { label: 'Paid', value: money?.paid, tone: 'green', icon: 'ti ti-circle-check' },
        { label: 'Outstanding', value: money?.outstanding, tone: 'rose', icon: 'ti ti-clock-dollar' },
        ...(record.pharmacy_due !== null
            ? [{ label: 'Pharmacy due', value: record.pharmacy_due, tone: 'amber', icon: 'ti ti-pill' }]
            : []),
    ];

    const invoiceRows = invoices.data?.data ?? [];
    const paymentRows = payments.data?.data ?? [];

    return (
        <div className="ptr-stack">
            <div className={`ptr-money is-${figures.length}`}>
                {figures.map((figure) => (
                    <div key={figure.label} className={`ptr-money-tile is-${figure.tone}`}>
                        <span aria-hidden="true">
                            <i className={figure.icon} />
                        </span>
                        <div>
                            <small>{figure.label}</small>
                            <b>{rupees(figure.value ?? 0)}</b>
                        </div>
                    </div>
                ))}
            </div>

            <RecordCard title="Invoices" icon="ti ti-file-invoice">
                {invoices.isLoading ? (
                    <Loading />
                ) : invoiceRows.length === 0 ? (
                    <Empty
                        icon="ti ti-receipt-off"
                        title="No invoices yet"
                        line="Bills for visits, tests and registration appear here as they are raised."
                    />
                ) : (
                    <div className={cn(invoices.isFetching && 'is-refreshing')}>
                        <TableFrame>
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Branch</th>
                                    <th className="ptr-num">Amount</th>
                                    <th className="ptr-num">Paid</th>
                                    <th className="ptr-num">Due</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoiceRows.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="ptr-strong">
                                            <Link to={`/billing/invoices/${invoice.id}`}>{invoice.invoice_number}</Link>
                                        </td>
                                        <td className="ptr-date">{day(invoice.invoice_date)}</td>
                                        <td>
                                            {INVOICE_KIND_LABELS[invoice.kind] ?? invoice.kind}
                                            {invoice.doctor_name && <small>{invoice.doctor_name}</small>}
                                        </td>
                                        <td>{branch.get(invoice.location_id) ?? '—'}</td>
                                        <td className="ptr-num ptr-strong">{rupees(invoice.total_amount)}</td>
                                        <td className="ptr-num">{rupees(invoice.paid_amount)}</td>
                                        <td className={cn('ptr-num', invoice.outstanding > 0 && 'ptr-owed')}>
                                            {invoice.outstanding > 0 ? rupees(invoice.outstanding) : '—'}
                                        </td>
                                        <td>
                                            <Pill pill={invoicePill(invoice.status)} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </TableFrame>

                        <Pager
                            page={invoicePage}
                            pageCount={invoices.data?.meta.last_page ?? 1}
                            onChange={setInvoicePage}
                            perPage={PER_PAGE}
                            total={invoices.data?.meta.total}
                        />
                    </div>
                )}
            </RecordCard>

            {seesPayments && (
            <RecordCard title="Payment History" icon="ti ti-cash">
                {payments.isLoading ? (
                    <Loading />
                ) : paymentRows.length === 0 ? (
                    <Empty icon="ti ti-cash-off" title="No payments yet" line="Money taken against this patient's bills appears here." />
                ) : (
                    <div className={cn(payments.isFetching && 'is-refreshing')}>
                        <TableFrame>
                            <thead>
                                <tr>
                                    <th>Receipt</th>
                                    <th>Date</th>
                                    <th>Invoice</th>
                                    <th>Method</th>
                                    <th className="ptr-num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {paymentRows.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="ptr-strong">
                                            {payment.receipt_number}
                                            {payment.is_refund && <small>Refund</small>}
                                        </td>
                                        <td className="ptr-date">
                                            {day(payment.paid_at)}
                                            <small>{clock(payment.paid_at)}</small>
                                        </td>
                                        <td>
                                            <Link to={`/billing/invoices/${payment.invoice_id}`}>
                                                {payment.invoice_number ?? '—'}
                                            </Link>
                                        </td>
                                        <td>
                                            {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}
                                            {payment.reference && <small>{payment.reference}</small>}
                                        </td>
                                        <td className={cn('ptr-num ptr-strong', payment.is_refund && 'ptr-owed')}>
                                            {payment.is_refund ? `−${rupees(payment.amount)}` : rupees(payment.amount)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </TableFrame>

                        <Pager
                            page={paymentPage}
                            pageCount={payments.data?.meta.last_page ?? 1}
                            onChange={setPaymentPage}
                            perPage={PER_PAGE}
                            total={payments.data?.meta.total}
                        />
                    </div>
                )}
            </RecordCard>
            )}
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Documents — the documents module's own panel, as is
|--------------------------------------------------------------------------
*/

export function DocumentsTab({ patientId }: { patientId: number }) {
    return (
        <RecordCard title="Files & Documents" icon="ti ti-files">
            <DocumentsPanel customerId={patientId} />
        </RecordCard>
    );
}
