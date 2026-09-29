import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { useStock, useStoreCategories, type MedicineBatch, type StockRow } from '../inventory';
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
    maker: string | null;
    unit: string;
    quantity: number;
    /** Usable stock at the moment it was added, to warn before the server refuses. */
    available: number;
    batch: { id: number; number: string; expiry: string; price: number } | null;
}

const money = (value: number) => `₹${value.toFixed(2)}`;

/** Notes a cashier hands over without counting: the common ones only. */
const QUICK_CASH = [50, 100, 200, 500];

/** The shelf, a page at a time. Eight fills the grid without a scrollbar. */
const PER_PAGE = 8;

/** A tender reads faster with its own mark than as one more word in a row. */
const METHOD_ICONS: Record<string, string> = {
    cash: 'ti ti-cash',
    card: 'ti ti-credit-card',
    upi: 'ti ti-qrcode',
    bank_transfer: 'ti ti-building-bank',
    other: 'ti ti-dots',
};

/**
 * The store's sections, on ONE line.
 *
 * Wrapping chips were two rows at seventeen categories and would be five at
 * fifty — the shelf itself would start below the fold, and the counter would
 * grow uglier every time somebody added a category. A strip that scrolls is
 * the same height whatever the catalogue does. The arrows appear only when
 * there is somewhere to go, so a store with four categories sees no chrome.
 */
function CategoryStrip({
    categories,
    value,
    onChange,
}: {
    categories: string[];
    value: string;
    onChange: (next: string) => void;
}) {
    const strip = useRef<HTMLDivElement>(null);
    const [edge, setEdge] = useState({ left: false, right: false });

    const measure = useCallback(() => {
        const el = strip.current;

        if (!el) {
            return;
        }

        setEdge({
            left: el.scrollLeft > 4,
            right: el.scrollLeft + el.clientWidth < el.scrollWidth - 4,
        });
    }, []);

    useEffect(() => {
        measure();

        const el = strip.current;

        if (!el || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(measure);

        observer.observe(el);

        return () => observer.disconnect();
    }, [measure, categories]);

    const nudge = (by: number) => strip.current?.scrollBy({ left: by, behavior: 'smooth' });
    const overflows = edge.left || edge.right;

    return (
        <div className={`pos-cats${overflows ? ' is-scrollable' : ''}`}>
            {overflows && (
                <button
                    type="button"
                    className="pos-cat-nav"
                    disabled={!edge.left}
                    onClick={() => nudge(-220)}
                    aria-label="Earlier categories"
                >
                    <i className="ti ti-chevron-left" aria-hidden="true" />
                </button>
            )}

            <div className="pos-cats-strip" ref={strip} onScroll={measure} role="group" aria-label="Category">
                <button
                    type="button"
                    className={`pos-chip${value === '' ? ' is-active' : ''}`}
                    aria-pressed={value === ''}
                    onClick={() => onChange('')}
                >
                    <i className="ti ti-layout-grid" aria-hidden="true" />
                    All
                </button>

                {categories.map((name) => (
                    <button
                        key={name}
                        type="button"
                        className={`pos-chip${value === name ? ' is-active' : ''}`}
                        aria-pressed={value === name}
                        onClick={() => onChange(name)}
                    >
                        {name}
                    </button>
                ))}
            </div>

            {overflows && (
                <button
                    type="button"
                    className="pos-cat-nav"
                    disabled={!edge.right}
                    onClick={() => nudge(220)}
                    aria-label="More categories"
                >
                    <i className="ti ti-chevron-right" aria-hidden="true" />
                </button>
            )}
        </div>
    );
}

/**
 * The counter.
 *
 * Shelf on the left, bill on the right — the two things a cashier looks at,
 * side by side, with nothing between them. The shelf is BROWSABLE rather than
 * search-only: most counter sales are a handful of fast-moving items, and
 * making somebody type the name of the thing in front of them is slower than
 * letting them tap it.
 *
 * Everything that decides whether a sale is allowed — stock, expiry,
 * prescription-only medicines, credit — is the server's answer under its lock,
 * and is shown here in place rather than guessed at beforehand.
 *
 * The idempotency key belongs to the cart, not to the request: it is made
 * when the cart is started and thrown away when the bill comes back, so a
 * double-tap or a retry on a bad connection bills once.
 */
export default function PosPage() {
    const { stores, store, choose, isLoading } = useChosenStore();
    const { data: settings } = usePharmacySettings();

    const [term, setTerm] = useState('');
    const [category, setCategory] = useState('');
    const [page, setPage] = useState(1);

    const [lines, setLines] = useState<CartLine[]>([]);
    const [customerId, setCustomerId] = useState('');
    const [walkIn, setWalkIn] = useState({ name: '', phone: '' });
    const [method, setMethod] = useState('cash');
    const [tendered, setTendered] = useState('');
    const [discountMode, setDiscountMode] = useState<'percent' | 'amount'>('percent');
    const [discountInput, setDiscountInput] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [bill, setBill] = useState<Sale | null>(null);

    const cartKey = useRef(newIdempotencyKey());

    const { data: stock, isFetching } = useStock(store?.id, {
        search: term,
        category,
        page,
        per_page: PER_PAGE,
    });

    const { data: categories } = useStoreCategories(store?.id);
    const { data: customers } = customersHooks.useList({ per_page: 100 });
    const sell = useCreateSale(store?.id);

    /**
     * What the bill will come to, as far as the counter can tell.
     *
     * The DISCOUNT IS A PERCENTAGE under the surface, because that is what the
     * sale API models — one rate per line. A rupee figure is converted to the
     * rate that produces it, so the number on screen is the number sent.
     */
    const totals = useMemo(() => {
        const gross = lines.reduce(
            (sum, line) => sum + (line.batch?.price ?? 0) * line.quantity,
            0,
        );

        const typed = Number(discountInput) || 0;

        const percent =
            discountMode === 'percent'
                ? Math.min(100, Math.max(0, typed))
                : gross > 0
                  ? Math.min(100, Math.max(0, (typed / gross) * 100))
                  : 0;

        const discount = (gross * percent) / 100;
        const net = gross - discount;
        const rounded = settings?.round_off_enabled ? Math.round(net) : net;

        return { gross, percent, discount, net, rounded, roundOff: rounded - net };
    }, [lines, discountInput, discountMode, settings]);

    const owed = Number(tendered === '' ? totals.rounded : tendered);

    /** Add a medicine, with the batch that would fill it: first expiry first out. */
    async function add(row: StockRow) {
        setError(null);

        // Already on the bill — a second tap means one more, not a second line.
        const existing = lines.findIndex((line) => line.medicine_id === row.medicine_id);

        if (existing !== -1) {
            change(existing, { quantity: lines[existing].quantity + 1 });

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
                name: row.generic_name ?? row.medicine_name ?? 'Item',
                maker: row.brand_name,
                unit: row.base_unit ?? 'unit',
                quantity: 1,
                available: row.usable,
                batch,
            },
        ]);
    }

    function change(index: number, patch: Partial<CartLine>) {
        setLines((was) => was.map((line, at) => (at === index ? { ...line, ...patch } : line)));
    }

    function remove(index: number) {
        setLines((was) => was.filter((_, at) => at !== index));
    }

    function clear() {
        setLines([]);
        setCustomerId('');
        setWalkIn({ name: '', phone: '' });
        setTendered('');
        setDiscountInput('');
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
                // One rate across every line is what a bill-level discount IS,
                // expressed in the only terms the sale API has.
                discount_percent: totals.percent || null,
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

    const rows = stock?.data ?? [];
    const meta = stock?.meta;

    /** Chips go back to page one: page 4 of "All" is rarely page 4 of "Vitamins". */
    function filter(next: string) {
        setCategory(next);
        setPage(1);
    }

    return (
        <>
            <PageHeader
                title="Counter"
                icon="ti ti-building-store"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Counter' }]}
                actions={<StorePicker stores={stores} value={store} onChange={choose} />}
            />

            <div className="pos-grid">
                {/* ---------------------------------------------- the shelf */}
                <Card className="pos-panel" title="Products" icon="ti ti-package">
                    <div className="pos-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            className="form-control"
                            placeholder="Medicine, brand or barcode…"
                            aria-label="Search the shelf"
                            value={term}
                            onChange={(event) => {
                                setTerm(event.target.value);
                                setPage(1);
                            }}
                            autoFocus
                        />
                    </div>

                    {/* The shelf's own sections, from what this store actually stocks. */}
                    {(categories ?? []).length > 0 && (
                        <CategoryStrip
                            categories={categories ?? []}
                            value={category}
                            onChange={filter}
                        />
                    )}

                    {rows.length === 0 ? (
                        <EmptyState
                            icon="ti ti-package-off"
                            title={isFetching ? 'Looking…' : 'Nothing on this shelf'}
                            description={
                                term.trim() !== '' || category !== ''
                                    ? 'Nothing matches that. Clear the search or pick another category.'
                                    : 'This store has no stock yet. Receive goods before selling.'
                            }
                        />
                    ) : (
                        <div className="pos-products">
                            {rows.map((row) => {
                                const out = row.usable <= 0;

                                return (
                                    <div
                                        className={`pos-product${out ? ' is-out' : ''}`}
                                        key={row.medicine_id}
                                    >
                                        <div className="pos-product-body">
                                            <b>{row.generic_name ?? row.medicine_name}</b>
                                            {row.brand_name && <small>{row.brand_name}</small>}

                                            <span
                                                className={`pos-stock${out ? ' is-out' : row.is_low ? ' is-low' : ''}`}
                                            >
                                                {out ? 'Out of stock' : `In stock: ${row.usable}`}
                                            </span>
                                        </div>

                                        <div className="pos-product-foot">
                                            <span className="pos-price">
                                                {row.price === null ? '—' : money(row.price)}
                                            </span>

                                            <button
                                                type="button"
                                                className="pos-add"
                                                disabled={out}
                                                onClick={() => void add(row)}
                                                aria-label={`Add ${row.generic_name ?? row.medicine_name}`}
                                            >
                                                Add
                                                <i className="ti ti-plus" aria-hidden="true" />
                                            </button>
                                        </div>

                                        {row.next_expiry && !out && (
                                            <span className="pos-expiry">
                                                Nearest expiry {formatDate(row.next_expiry)}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {meta && meta.total > 0 && (
                        <div className="pos-pager">
                            <span>
                                Showing {meta.from}–{meta.to} of {meta.total}
                            </span>

                            <div className="pos-pager-buttons">
                                <button
                                    type="button"
                                    className="pos-page"
                                    disabled={meta.current_page <= 1}
                                    onClick={() => setPage((at) => Math.max(1, at - 1))}
                                    aria-label="Previous page"
                                >
                                    <i className="ti ti-chevron-left" aria-hidden="true" />
                                </button>

                                <span className="pos-page is-current">
                                    {meta.current_page} / {meta.last_page}
                                </span>

                                <button
                                    type="button"
                                    className="pos-page"
                                    disabled={meta.current_page >= meta.last_page}
                                    onClick={() => setPage((at) => at + 1)}
                                    aria-label="Next page"
                                >
                                    <i className="ti ti-chevron-right" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    )}
                </Card>

                {/* ----------------------------------------------- the bill */}
                <Card
                    className="pos-panel pos-bill"
                    bodyClassName="pos-bill-body"
                    title="Current bill"
                    icon="ti ti-receipt"
                    actions={
                        lines.length > 0 && (
                            <button type="button" className="pos-clear" onClick={clear}>
                                Clear all
                            </button>
                        )
                    }
                >
                    {/* Who the bill is for: set once, so it stays at the top. */}
                    <div className="pos-bill-top">
                        <SearchableSelect
                            id="pos-customer"
                            value={customerId}
                            onChange={setCustomerId}
                            placeholder="Walk-in customer"
                            options={(customers ?? []).map((customer) => ({
                                value: String(customer.id),
                                label: `${customer.name}${customer.phone ? ` · ${customer.phone}` : ''}`,
                            }))}
                        />

                        {!customerId && (
                            <div className="pos-walkin">
                                <input
                                    className="form-control"
                                    value={walkIn.name}
                                    placeholder="Name (optional)"
                                    aria-label="Walk-in name"
                                    onChange={(event) =>
                                        setWalkIn({ ...walkIn, name: event.target.value })
                                    }
                                />

                                <input
                                    className="form-control"
                                    value={walkIn.phone}
                                    placeholder="Phone"
                                    aria-label="Walk-in phone"
                                    onChange={(event) =>
                                        setWalkIn({ ...walkIn, phone: event.target.value })
                                    }
                                />
                            </div>
                        )}
                    </div>

                    {/*
                     * ONLY THE LINES SCROLL. Everything that takes the money —
                     * tender, change, total, button — is pinned below, because a
                     * till that has to be scrolled before every sale gets
                     * scrolled all day.
                     */}
                    <div className="pos-bill-scroll">
                    {lines.length === 0 ? (
                        <EmptyState
                            icon="ti ti-shopping-cart"
                            title="Nothing on this bill yet"
                            description="Tap an item on the left, or search for it."
                        />
                    ) : (
                        <div className="pos-lines">
                            {lines.map((line, index) => {
                                const price = line.batch?.price ?? 0;
                                const short = line.quantity > line.available;

                                return (
                                    <div className="pos-line" key={line.medicine_id}>
                                        <span className="pos-line-no">{index + 1}</span>

                                        <div className="pos-line-body">
                                            <b>{line.name}</b>
                                            <small>
                                                {line.maker ? `${line.maker} · ` : ''}
                                                {money(price)} a {line.unit}
                                            </small>

                                            {short && (
                                                <small className="pos-line-warn">
                                                    Only {line.available} in stock
                                                </small>
                                            )}
                                        </div>

                                        <div className="pos-stepper">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    change(index, {
                                                        quantity: Math.max(1, line.quantity - 1),
                                                    })
                                                }
                                                aria-label={`One fewer ${line.name}`}
                                            >
                                                <i className="ti ti-minus" aria-hidden="true" />
                                            </button>

                                            <input
                                                type="number"
                                                min={1}
                                                value={line.quantity}
                                                aria-label={`Quantity of ${line.name}`}
                                                onChange={(event) =>
                                                    change(index, {
                                                        quantity: Math.max(
                                                            1,
                                                            Number(event.target.value) || 1,
                                                        ),
                                                    })
                                                }
                                            />

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    change(index, { quantity: line.quantity + 1 })
                                                }
                                                aria-label={`One more ${line.name}`}
                                            >
                                                <i className="ti ti-plus" aria-hidden="true" />
                                            </button>
                                        </div>

                                        <span className="pos-line-total">
                                            {money(price * line.quantity)}
                                        </span>

                                        <button
                                            type="button"
                                            className="pos-remove"
                                            aria-label={`Remove ${line.name}`}
                                            onClick={() => remove(index)}
                                        >
                                            <i className="ti ti-trash" aria-hidden="true" />
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Nothing to discount and nothing to total on an empty
                        till, so neither is shown until there is a line. */}
                    {lines.length > 0 && (
                        <div className="pos-money">
                            <div className="pos-discount">
                                <select
                                    className="form-select"
                                    value={discountMode}
                                    aria-label="Discount type"
                                    onChange={(event) =>
                                        setDiscountMode(event.target.value as 'percent' | 'amount')
                                    }
                                >
                                    <option value="percent">Discount %</option>
                                    <option value="amount">Discount ₹</option>
                                </select>

                                <input
                                    type="number"
                                    className="form-control"
                                    min={0}
                                    step="0.01"
                                    placeholder="0.00"
                                    aria-label="Discount"
                                    value={discountInput}
                                    onChange={(event) => setDiscountInput(event.target.value)}
                                />
                            </div>

                            <dl className="pos-totals">
                                <div>
                                    <dt>Items ({lines.length})</dt>
                                    <dd>{money(totals.gross)}</dd>
                                </div>

                                {totals.discount > 0 && (
                                    <div>
                                        <dt>Discount</dt>
                                        <dd className="is-minus">− {money(totals.discount)}</dd>
                                    </div>
                                )}

                                {settings?.round_off_enabled && totals.roundOff !== 0 && (
                                    <div>
                                        <dt>Round off</dt>
                                        <dd>{money(totals.roundOff)}</dd>
                                    </div>
                                )}
                            </dl>

                            {/*
                             * GST is NOT added here. This system stores Indian
                             * MRP with the tax already inside it, so the bill
                             * splits the tax out rather than adding it on —
                             * showing "+ GST" would make the screen disagree
                             * with the receipt by the tax amount.
                             */}
                            <p className="pos-tax">
                                {settings?.prices_include_tax
                                    ? 'Prices include GST — the bill shows how much tax they contain.'
                                    : 'GST is added when the bill is made.'}
                            </p>
                        </div>
                    )}

                    {bill && (
                        <div className="pos-receipt">
                            <b>
                                <i className="ti ti-circle-check" aria-hidden="true" />
                                {bill.sale_number}
                            </b>

                            <dl>
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
                                <div>
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

                            <button type="button" className="pos-clear" onClick={() => setBill(null)}>
                                Dismiss
                            </button>
                        </div>
                    )}
                    </div>

                    <div className="pos-bill-foot">
                        <div className="pos-methods" role="group" aria-label="Payment method">
                            {Object.entries(PAYMENT_METHOD_LABELS)
                                // Credit is not a way of paying at the counter;
                                // it is the absence of one, and it needs a
                                // registered customer the server checks for.
                                // Split is not a tender either — it is a bill
                                // settled by more than one, which this screen
                                // cannot yet collect.
                                .filter(([value]) => value !== 'credit' && value !== 'split')
                                .map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className={`pos-method${method === value ? ' is-active' : ''}`}
                                        aria-pressed={method === value}
                                        onClick={() => setMethod(value)}
                                    >
                                        <i
                                            className={METHOD_ICONS[value] ?? 'ti ti-dots'}
                                            aria-hidden="true"
                                        />
                                        {label}
                                    </button>
                                ))}
                        </div>

                        {/* Notes a cashier is handed, so the change is worked
                            out without typing. Cash only — there is nothing to
                            tender on a card. */}
                        {method === 'cash' && (
                            <div className="pos-cash" role="group" aria-label="Amount taken">
                                {QUICK_CASH.map((note) => (
                                    <button
                                        key={note}
                                        type="button"
                                        className="pos-note-btn"
                                        onClick={() => setTendered(String(note))}
                                    >
                                        ₹{note}
                                    </button>
                                ))}

                                <input
                                    type="number"
                                    className="form-control"
                                    min={0}
                                    step="0.01"
                                    value={tendered}
                                    placeholder={totals.rounded.toFixed(2)}
                                    aria-label="Amount taken"
                                    onChange={(event) => setTendered(event.target.value)}
                                />
                            </div>
                        )}

                        {method === 'cash' && owed > totals.rounded && (
                            <p className="pos-change">
                                <span>Change due</span>
                                <b>{money(owed - totals.rounded)}</b>
                            </p>
                        )}

                        {error && <p className="alert alert-danger py-2 px-3 mb-2">{error}</p>}

                        <div className="pos-payable">
                            <span>Total amount</span>
                            <b>{money(totals.rounded)}</b>
                        </div>

                        <Button
                            className="pos-generate"
                            onClick={() => void checkout()}
                            loading={sell.isPending}
                            disabled={lines.length === 0}
                            icon="ti ti-printer"
                        >
                            Generate bill
                        </Button>
                    </div>
                </Card>
            </div>
        </>
    );
}
