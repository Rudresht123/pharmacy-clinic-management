import { Link } from 'react-router-dom';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { formatDate, formatDateTime } from '@/shared/utils/format';
import { PAYMENT_STATUS_LABELS, type Sale } from '../sales';
import { PAYMENT_METHOD_LABELS } from '../types';

const money = (value: number) => `${value < 0 ? '−' : ''}₹${Math.abs(value).toFixed(2)}`;

const METHOD_ICONS: Record<string, string> = {
    cash: 'ti ti-cash',
    card: 'ti ti-credit-card',
    upi: 'ti ti-qrcode',
    bank_transfer: 'ti ti-building-bank',
    cheque: 'ti ti-file-text',
    online: 'ti ti-world',
    other: 'ti ti-dots',
};

const STATUS_TONES: Record<string, string> = {
    paid: 'is-paid',
    partial: 'is-part',
    unpaid: 'is-unpaid',
    billed_via_invoice: 'is-moved',
};

/**
 * One pharmacy bill, read whole: who it is for, what was on it, and every
 * rupee in and out since.
 *
 * WHO FIRST. Somebody opening a bill at the counter is usually answering
 * "is this the right person's?" — a name alone does not answer it when two
 * Priya Sharmas buy on the same day, so the patient's ID, age, phone and town
 * sit beside it, with the way into their file.
 */
export function SaleBillView({ sale }: { sale: Sale }) {
    const customer = sale.customer ?? null;
    const returned = sale.returned_amount ?? 0;
    const place = customer ? [customer.city, customer.state].filter(Boolean).join(', ') : '';
    const gender = customer?.gender ? customer.gender.charAt(0).toUpperCase() + customer.gender.slice(1) : null;
    const payments = sale.payments ?? [];
    const returns = sale.returns ?? [];

    return (
        <div className="sb">
            <div className="sb-top">
                <section className="sb-patient" aria-label="Patient">
                    <PersonPhoto src={null} name={sale.customer_name} className="sb-face" />

                    <div className="sb-who">
                        <div className="sb-name">
                            {sale.customer_name}
                            {sale.is_walk_in && <em className="sb-tag">Walk-in</em>}
                        </div>

                        {customer?.code && <div className="pf-code">Patient ID: {customer.code}</div>}

                        <ul className="pf-facts sb-facts">
                            {customer?.age != null && (
                                <li>
                                    <i className="ti ti-cake" aria-hidden="true" />
                                    {customer.age} years
                                </li>
                            )}
                            {gender && (
                                <li>
                                    <i className="ti ti-user" aria-hidden="true" />
                                    {gender}
                                </li>
                            )}
                            {(customer?.phone ?? sale.walk_in_phone) && (
                                <li>
                                    <i className="ti ti-phone" aria-hidden="true" />
                                    <a href={`tel:${customer?.phone ?? sale.walk_in_phone}`}>
                                        {customer?.phone ?? sale.walk_in_phone}
                                    </a>
                                </li>
                            )}
                            {place && (
                                <li>
                                    <i className="ti ti-map-pin" aria-hidden="true" />
                                    {place}
                                </li>
                            )}
                        </ul>

                        {customer ? (
                            <Link className="sb-link" to={`/customers/${customer.id}`}>
                                Open patient file
                                <i className="ti ti-arrow-right" aria-hidden="true" />
                            </Link>
                        ) : (
                            <span className="sb-note">Not a registered patient — sold at the counter by name.</span>
                        )}
                    </div>
                </section>

                <dl className="sb-meta">
                    <div>
                        <dt>Billed on</dt>
                        <dd>{formatDateTime(sale.sale_date)}</dd>
                    </div>
                    <div>
                        <dt>Store</dt>
                        <dd>{sale.store_name ?? '—'}</dd>
                    </div>
                    {/* Older bills never recorded who rang them up — no row rather than a dash. */}
                    {sale.created_by_name && (
                        <div>
                            <dt>Billed by</dt>
                            <dd>{sale.created_by_name}</dd>
                        </div>
                    )}
                    {(sale.doctor_name || sale.prescription_id) && (
                        <div>
                            <dt>Prescribed by</dt>
                            <dd>
                                {sale.doctor_name ?? '—'}
                                {sale.prescription_id && <span className="dr-sub d-block">Dispensed on a prescription</span>}
                            </dd>
                        </div>
                    )}
                    <div>
                        <dt>Payment</dt>
                        <dd>
                            {sale.status === 'cancelled' ? (
                                <span className="sb-status is-cancelled">Cancelled</span>
                            ) : (
                                <span className={`sb-status ${STATUS_TONES[sale.payment_status] ?? ''}`}>
                                    {PAYMENT_STATUS_LABELS[sale.payment_status] ?? sale.payment_status}
                                </span>
                            )}
                        </dd>
                    </div>
                </dl>
            </div>

            {sale.status === 'cancelled' && (
                <div className="sb-cancelled">
                    <i className="ti ti-ban" aria-hidden="true" />
                    <div>
                        <b>Cancelled {formatDate(sale.cancelled_at)}</b>
                        {sale.cancellation_reason && <span className="d-block">{sale.cancellation_reason}</span>}
                    </div>
                </div>
            )}

            <div className="pf-table-wrap sb-items">
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
                        {(sale.items ?? []).map((item) => (
                            <tr key={item.id}>
                                <td>
                                    <b className="sb-item">{item.item_name}</b>
                                    {item.hsn_code && <span className="dr-sub d-block">HSN {item.hsn_code}</span>}
                                </td>
                                <td>
                                    {item.batch_number}
                                    <span className="dr-sub d-block">Exp {formatDate(item.expiry_date)}</span>
                                </td>
                                <td className="text-end tabular-nums">
                                    {item.quantity}
                                    {(item.returned_quantity ?? 0) > 0 && (
                                        <span className="sb-back d-block">{item.returned_quantity} back</span>
                                    )}
                                </td>
                                <td className="text-end tabular-nums">{money(item.unit_price)}</td>
                                <td className="text-end tabular-nums">
                                    {money(item.tax_amount)}
                                    <span className="dr-sub d-block">{item.tax_rate}%</span>
                                </td>
                                <td className="text-end tabular-nums fw-semibold">{money(item.line_total)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="sb-bottom">
                <section className="sb-money" aria-label="Payments, refunds and returns">
                    <h6 className="sb-heading">Payments &amp; refunds</h6>

                    {payments.length === 0 ? (
                        <p className="sb-note mb-0">Nothing paid on this bill yet.</p>
                    ) : (
                        <ul className="sb-ledger">
                            {payments.map((payment) => (
                                <li key={payment.id} className={payment.is_refund ? 'is-refund' : undefined}>
                                    <i
                                        className={payment.is_refund ? 'ti ti-arrow-back-up' : (METHOD_ICONS[payment.method] ?? 'ti ti-wallet')}
                                        aria-hidden="true"
                                    />
                                    <div className="sb-ledger-what">
                                        <b>
                                            {payment.is_refund ? 'Refund · ' : ''}
                                            {PAYMENT_METHOD_LABELS[payment.method] ?? payment.method}
                                        </b>
                                        <span>
                                            {formatDateTime(payment.paid_at)}
                                            {payment.reference && ` · ${payment.reference}`}
                                        </span>
                                        {payment.notes && <span>{payment.notes}</span>}
                                    </div>
                                    <span className="sb-ledger-amount tabular-nums">
                                        {payment.is_refund ? '−' : ''}
                                        {money(payment.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {returns.length > 0 && (
                        <>
                            <h6 className="sb-heading mt-3">Returns</h6>
                            <ul className="sb-ledger">
                                {returns.map((ret) => (
                                    <li key={ret.id} className="is-return">
                                        <i className="ti ti-package-import" aria-hidden="true" />
                                        <div className="sb-ledger-what">
                                            <b>{ret.return_number}</b>
                                            <span>
                                                {formatDate(ret.return_date)}
                                                {ret.created_by_name && ` · ${ret.created_by_name}`}
                                            </span>
                                            <span>{ret.reason}</span>
                                        </div>
                                        <span className="sb-ledger-amount tabular-nums">
                                            {money(ret.total_amount)}
                                            <small>
                                                {ret.refund_amount > 0 ? `${money(ret.refund_amount)} refunded` : 'Taken off what is owed'}
                                            </small>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </section>

                <dl className="sb-totals">
                    <div>
                        <dt>Goods</dt>
                        <dd>{money(sale.subtotal)}</dd>
                    </div>
                    {sale.discount_amount > 0 && (
                        <div>
                            <dt>Discount</dt>
                            <dd>−{money(sale.discount_amount)}</dd>
                        </div>
                    )}
                    <div>
                        <dt>GST</dt>
                        <dd>{money(sale.tax_amount)}</dd>
                    </div>
                    {sale.round_off !== 0 && (
                        <div>
                            <dt>Round off</dt>
                            <dd>{money(sale.round_off)}</dd>
                        </div>
                    )}
                    <div className="is-total">
                        <dt>Total</dt>
                        <dd>{money(sale.total_amount)}</dd>
                    </div>
                    {returned > 0 && (
                        <div>
                            <dt>Returned</dt>
                            <dd>−{money(returned)}</dd>
                        </div>
                    )}
                    <div>
                        <dt>Paid</dt>
                        <dd>{money(sale.paid_amount)}</dd>
                    </div>
                    {sale.status !== 'cancelled' &&
                        (sale.amount_due > 0 ? (
                            <div className="is-owed">
                                <dt>Owed</dt>
                                <dd>{money(sale.amount_due)}</dd>
                            </div>
                        ) : (
                            <div className="is-settled">
                                <dt>Settled</dt>
                                <dd>
                                    <i className="ti ti-circle-check" aria-hidden="true" />{' '}
                                    {sale.payment_status === 'billed_via_invoice' ? 'On the visit bill' : 'Nothing owed'}
                                </dd>
                            </div>
                        ))}
                </dl>
            </div>
        </div>
    );
}
