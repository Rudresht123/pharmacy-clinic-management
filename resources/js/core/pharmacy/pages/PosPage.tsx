import { useMemo, useRef, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { http, resolveErrorMessage } from '@/shared/api/http';
import { formatDate } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';
import { customersHooks } from '@/core/customers/api';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { useStock, type MedicineBatch, type StockRow } from '../inventory';
import { usePharmacySettings } from '../api';
import { newIdempotencyKey, useCreateSale, type Sale, type SaleInput } from '../sales';
import { PAYMENT_METHOD_LABELS } from '../types';

/**
 * One line in the cart.
 *
 * `batch` is the batch the counter expects to sell from — the one closest to
 * expiry — shown so the cashier can see what they are handing over. The
 * server allocates again under its lock, so what is on screen is a
 * prediction and the bill is the truth.
 */
interface CartLine {
    medicine_id: number;
    name: string;
    unit: string;
    quantity: number;
    discount_percent: number;
    /** Usable stock at the moment it was added, to warn before the server refuses. */
    available: number;
    batch: { id: number; number: string; expiry: string; price: number } | null;
}

const money = (value: number) => `₹${value.toFixed(2)}`;

/**
 * The counter.
 *
 * Search, add, take the money, print. Everything that decides whether a sale
 * is allowed — stock, expiry, prescription-only medicines, credit — is the
 * server's answer under its lock, and is shown here in place rather than
 * guessed at beforehand.
 *
 * The idempotency key belongs to the cart, not to the request: it is made
 * when the cart is started and thrown away when the bill comes back, so a
 * double-tap or a retry on a bad connection bills once.
 */
export default function PosPage() {
    const { stores, store, choose, isLoading } = useChosenStore();
    const { data: settings } = usePharmacySettings();

    const [term, setTerm] = useState('');
    const [lines, setLines] = useState<CartLine[]>([]);
    const [customerId, setCustomerId] = useState('');
    const [walkIn, setWalkIn] = useState({ name: '', phone: '' });
    const [method, setMethod] = useState('cash');
    const [tendered, setTendered] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [bill, setBill] = useState<Sale | null>(null);

    const cartKey = useRef(newIdempotencyKey());

    const { data: stock, isFetching } = useStock(store?.id, { search: term, per_page: 8 }, term.trim() !== '');
    const { data: customers } = customersHooks.useList({ per_page: 100 });
    const sell = useCreateSale(store?.id);

    const totals = useMemo(() => {
        const gross = lines.reduce((sum, line) => {
            const price = line.batch?.price ?? 0;
            const lineGross = price * line.quantity;

            return sum + lineGross - (lineGross * line.discount_percent) / 100;
        }, 0);

        const rounded = settings?.round_off_enabled ? Math.round(gross) : gross;

        return { gross, rounded, roundOff: rounded - gross };
    }, [lines, settings]);

    const owed = Number(tendered === '' ? totals.rounded : tendered);

    /** Add a medicine, with the batch that would fill it: first expiry first out. */
    async function add(row: StockRow) {
        setError(null);

        if (lines.some((line) => line.medicine_id === row.medicine_id)) {
            return;
        }

        let batch: CartLine['batch'] = null;

        try {
            const { data } = await http.get<{ data: MedicineBatch[] }>(
                `/tenant/pharmacy-stores/${store?.id}/batches`,
                { params: { medicine_id: row.medicine_id, in_stock: 1, per_page: 50 }, silent: true },
            );

            const usable = data.data
                .filter((candidate) => candidate.is_usable && candidate.quantity_available > 0)
                .sort((a, b) => a.expiry_date.localeCompare(b.expiry_date));

            if (usable[0]) {
                batch = {
                    id: usable[0].id,
                    number: usable[0].batch_number,
                    expiry: usable[0].expiry_date,
                    price: Number(
                        settings?.price_basis === 'selling' ? usable[0].selling_price : usable[0].mrp,
                    ),
                };
            }
        } catch {
            // The bill is the server's answer anyway; a price we could not
            // read just means the cart shows no estimate for this line.
        }

        setLines((was) => [
            ...was,
            {
                medicine_id: row.medicine_id,
                name: row.medicine_name ?? 'Item',
                unit: row.base_unit ?? 'unit',
                quantity: 1,
                discount_percent: 0,
                available: row.usable,
                batch,
            },
        ]);

        setTerm('');
    }

    function change(index: number, patch: Partial<CartLine>) {
        setLines((was) => was.map((line, at) => (at === index ? { ...line, ...patch } : line)));
    }

    function clear() {
        setLines([]);
        setCustomerId('');
        setWalkIn({ name: '', phone: '' });
        setTendered('');
        setError(null);
        cartKey.current = newIdempotencyKey();
    }

    async function checkout() {
        setError(null);

        const payload: SaleInput = {
            customer_id: customerId ? Number(customerId) : null,
            walk_in_name: customerId ? null : walkIn.name || null,
            walk_in_phone: customerId ? null : walkIn.phone || null,
            items: lines.map((line) => ({
                medicine_id: line.medicine_id,
                quantity: line.quantity,
                discount_percent: line.discount_percent || null,
            })),
            payments: owed > 0 ? [{ method, amount: Number(owed.toFixed(2)) }] : [],
        };

        try {
            const sale = await sell.mutateAsync({ payload, key: cartKey.current });

            setBill(sale);
            clear();
            notify.success(`${sale.sale_number} billed`);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Loading the counter…" />;
    }

    if (!store) {
        return (
            <Card>
                <NoStores />
            </Card>
        );
    }

    return (
        <>
            <PageHeader
                title="Counter"
                icon="ti ti-cash-register"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Counter' }]}
                actions={<StorePicker stores={stores} value={store} onChange={choose} />}
            />

            <div className="row g-3">
                <div className="col-lg-8">
                    <Card title="Items" icon="ti ti-search" description="Search, or scan a barcode.">
                        <div className="pos-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="search"
                                className="form-control"
                                placeholder="Medicine, brand or barcode…"
                                aria-label="Search the shelf"
                                value={term}
                                onChange={(event) => setTerm(event.target.value)}
                                autoFocus
                            />
                        </div>

                        {term.trim() !== '' && (
                            <div className="pos-results">
                                {isFetching && <p className="pos-none">Looking…</p>}

                                {!isFetching && (stock?.data ?? []).length === 0 && (
                                    <p className="pos-none">Nothing on the shelf matches that.</p>
                                )}

                                {(stock?.data ?? []).map((row) => (
                                    <button
                                        type="button"
                                        className="pos-result"
                                        key={row.medicine_id}
                                        onClick={() => void add(row)}
                                        disabled={row.usable <= 0}
                                    >
                                        <span>
                                            <b>{row.medicine_name}</b>
                                            <small>
                                                {row.usable > 0
                                                    ? `${row.usable} ${row.base_unit ?? 'unit'} in stock`
                                                    : 'Out of stock'}
                                                {row.next_expiry ? ` · nearest expiry ${formatDate(row.next_expiry)}` : ''}
                                            </small>
                                        </span>
                                        <i className="ti ti-plus" aria-hidden="true" />
                                    </button>
                                ))}
                            </div>
                        )}

                        {lines.length === 0 ? (
                            <EmptyState
                                icon="ti ti-shopping-cart"
                                title="Nothing on this bill yet"
                                description="Search for what the customer is buying and add it."
                            />
                        ) : (
                            <div className="dpt-frame mt-3">
                                <table className="dpt-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Batch</th>
                                            <th className="text-end">Qty</th>
                                            <th className="text-end">Disc %</th>
                                            <th className="text-end">Amount</th>
                                            <th aria-label="Remove" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.map((line, index) => {
                                            const price = line.batch?.price ?? 0;
                                            const gross = price * line.quantity;
                                            const net = gross - (gross * line.discount_percent) / 100;

                                            return (
                                                <tr key={line.medicine_id}>
                                                    <td>
                                                        <span className="dpt-label">
                                                            <b>{line.name}</b>
                                                            <small>
                                                                {money(price)} a {line.unit}
                                                                {line.quantity > line.available
                                                                    ? ` · only ${line.available} in stock`
                                                                    : ''}
                                                            </small>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        {line.batch ? (
                                                            <span className="dpt-code">
                                                                {line.batch.number} · {formatDate(line.batch.expiry)}
                                                            </span>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                    <td className="text-end">
                                                        <input
                                                            type="number"
                                                            className="form-control pos-qty"
                                                            min={1}
                                                            value={line.quantity}
                                                            aria-label={`Quantity of ${line.name}`}
                                                            onChange={(event) =>
                                                                change(index, {
                                                                    quantity: Math.max(1, Number(event.target.value) || 1),
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                    <td className="text-end">
                                                        <input
                                                            type="number"
                                                            className="form-control pos-qty"
                                                            min={0}
                                                            max={100}
                                                            value={line.discount_percent}
                                                            aria-label={`Discount on ${line.name}`}
                                                            onChange={(event) =>
                                                                change(index, {
                                                                    discount_percent: Math.min(
                                                                        100,
                                                                        Math.max(0, Number(event.target.value) || 0),
                                                                    ),
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                    <td className="text-end dpt-num">{money(net)}</td>
                                                    <td className="text-end">
                                                        <button
                                                            type="button"
                                                            className="pos-remove"
                                                            aria-label={`Remove ${line.name}`}
                                                            onClick={() =>
                                                                setLines((was) => was.filter((_, at) => at !== index))
                                                            }
                                                        >
                                                            <i className="ti ti-x" aria-hidden="true" />
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="col-lg-4">
                    <Card title="Customer" icon="ti ti-user" description="Registered, or whoever walked in.">
                        <div className="mb-3">
                            <label className="form-label" htmlFor="pos-customer">
                                Registered customer
                            </label>
                            <SearchableSelect
                                id="pos-customer"
                                value={customerId}
                                onChange={setCustomerId}
                                placeholder="Walk-in"
                                options={(customers ?? []).map((customer) => ({
                                    value: String(customer.id),
                                    label: `${customer.name}${customer.phone ? ` · ${customer.phone}` : ''}`,
                                }))}
                            />
                        </div>

                        {!customerId && (
                            <div className="row">
                                <div className="col-7 mb-3">
                                    <label className="form-label" htmlFor="pos-name">
                                        Name
                                    </label>
                                    <input
                                        id="pos-name"
                                        className="form-control"
                                        value={walkIn.name}
                                        placeholder="Walk-in customer"
                                        onChange={(event) => setWalkIn({ ...walkIn, name: event.target.value })}
                                    />
                                </div>
                                <div className="col-5 mb-3">
                                    <label className="form-label" htmlFor="pos-phone">
                                        Phone
                                    </label>
                                    <input
                                        id="pos-phone"
                                        className="form-control"
                                        value={walkIn.phone}
                                        onChange={(event) => setWalkIn({ ...walkIn, phone: event.target.value })}
                                    />
                                </div>
                            </div>
                        )}
                    </Card>

                    <Card title="Payment" icon="ti ti-currency-rupee" className="mt-3">
                        <dl className="pos-totals">
                            <div>
                                <dt>Items</dt>
                                <dd>{lines.length}</dd>
                            </div>
                            <div>
                                <dt>Amount</dt>
                                <dd>{money(totals.gross)}</dd>
                            </div>
                            {settings?.round_off_enabled && (
                                <div>
                                    <dt>Round off</dt>
                                    <dd>{money(totals.roundOff)}</dd>
                                </div>
                            )}
                            <div className="pos-due">
                                <dt>To pay</dt>
                                <dd>{money(totals.rounded)}</dd>
                            </div>
                        </dl>

                        <p className="form-hint">
                            {settings?.prices_include_tax
                                ? 'Prices include GST; the bill shows the tax it contains.'
                                : 'GST is added on the bill.'}
                        </p>

                        <div className="mb-3">
                            <label className="form-label" htmlFor="pos-method">
                                Paid by
                            </label>
                            <select
                                id="pos-method"
                                className="form-select"
                                value={method}
                                onChange={(event) => setMethod(event.target.value)}
                            >
                                {Object.entries(PAYMENT_METHOD_LABELS)
                                    .filter(([value]) => value !== 'credit')
                                    .map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                            </select>
                        </div>

                        <div className="mb-3">
                            <label className="form-label" htmlFor="pos-tendered">
                                Amount taken
                            </label>
                            <input
                                id="pos-tendered"
                                type="number"
                                className="form-control"
                                min={0}
                                step="0.01"
                                value={tendered}
                                placeholder={totals.rounded.toFixed(2)}
                                onChange={(event) => setTendered(event.target.value)}
                            />
                            <p className="form-hint">
                                Less than the bill leaves the rest owed, which needs credit sales switched on and
                                a registered customer.
                            </p>
                        </div>

                        {error && <p className="alert alert-danger py-2 px-3 mb-3">{error}</p>}

                        <div className="d-flex gap-2">
                            <Button
                                onClick={() => void checkout()}
                                loading={sell.isPending}
                                disabled={lines.length === 0}
                                icon="ti ti-receipt"
                            >
                                Take payment
                            </Button>

                            <Button variant="light" onClick={clear} disabled={lines.length === 0}>
                                Clear
                            </Button>
                        </div>
                    </Card>

                    {bill && (
                        <Card title={bill.sale_number} icon="ti ti-file-invoice" className="mt-3">
                            <dl className="pos-totals">
                                <div>
                                    <dt>Sold to</dt>
                                    <dd>{bill.customer_name}</dd>
                                </div>
                                <div>
                                    <dt>Goods</dt>
                                    <dd>{money(bill.subtotal)}</dd>
                                </div>
                                <div>
                                    <dt>GST</dt>
                                    <dd>{money(bill.tax_amount)}</dd>
                                </div>
                                <div className="pos-due">
                                    <dt>Paid</dt>
                                    <dd>{money(bill.paid_amount)}</dd>
                                </div>
                                {bill.amount_due > 0 && (
                                    <div>
                                        <dt>Owed</dt>
                                        <dd>{money(bill.amount_due)}</dd>
                                    </div>
                                )}
                            </dl>

                            <Button variant="light" size="sm" icon="ti ti-x" onClick={() => setBill(null)}>
                                Dismiss
                            </Button>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
