import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { StatTiles, type StatTile } from '@/shared/components/ui/StatTiles';
import { EmptyState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { http, resolveErrorMessage } from '@/shared/api/http';
import type { Page } from '@/shared/api/resource';
import { formatDate } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';
import { customersHooks } from '@/core/customers/api';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { OpdPatientsPanel } from '../components/OpdPatientsPanel';
import { useStock, useStoreCategories, type MedicineBatch, type StockRow } from '../inventory';
import { storesApi, usePharmacyDashboard, usePharmacySettings } from '../api';
import { countOpdTabs, useOpdHandoff, type OpdPatient } from '../opd';
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
    /** What the bill line reads: the generic and its strength, "Amoxicillin 500 mg". */
    name: string;
    unit: string;
    quantity: number;
    /** Usable stock at the moment it was added, to warn before the server refuses. */
    available: number;
    /** GST %, priced the way SalePricing will price it. */
    taxRate: number;
    batch: { id: number; number: string; expiry: string; price: number; mrp: number } | null;
}

type Sort = 'recent' | 'name' | 'stock';
type View = 'grid' | 'list';

const money = (value: number) => `₹${value.toFixed(2)}`;

/** Two places, as the server rounds every figure as it works it out. */
const round2 = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;

/** A day's takings read in whole rupees, grouped the Indian way: ₹24,560. */
const rupees = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
});

/** Notes a cashier hands over without counting: the common ones only. */
const QUICK_CASH = [50, 100, 200, 500];

/** The shelf, a page at a time. Eight is two rows of four. */
const PER_PAGE = 8;

const SORTS: { value: Sort; label: string }[] = [
    { value: 'recent', label: 'Recent' },
    { value: 'name', label: 'Name (A–Z)' },
    { value: 'stock', label: 'Most in stock' },
];

/** A tender reads faster with its own mark than as one more word in a row. */
const METHOD_ICONS: Record<string, string> = {
    cash: 'ti ti-cash',
    card: 'ti ti-credit-card',
    upi: 'ti ti-qrcode',
    bank_transfer: 'ti ti-building-bank',
    other: 'ti ti-dots',
};

/** Units sold off a strip rather than out of a box. */
const STRIP_UNITS = ['tablet', 'capsule'];

/** "Strip of 10" — the pack the per-unit price comes out of. */
function packLabel(row: StockRow): string {
    const unit = row.base_unit ?? 'unit';

    if (row.pack_size && row.pack_size > 1) {
        return STRIP_UNITS.includes(unit) ? `Strip of ${row.pack_size}` : `Pack of ${row.pack_size}`;
    }

    return `Per ${unit}`;
}

/** The second line on a product: the brand on the pack, or failing that its strength. */
function makerLine(row: StockRow): string | null {
    return row.brand_name ?? row.strength ?? null;
}

function rowName(row: StockRow): string {
    return row.generic_name ?? row.medicine_name ?? 'Item';
}

/** The grid-or-list choice, remembered per browser. Storage can refuse; the grid is fine then. */
function useRememberedView(): [View, (next: View) => void] {
    const [view, setView] = useState<View>(() => {
        try {
            return window.localStorage.getItem('pos.view') === 'list' ? 'list' : 'grid';
        } catch {
            return 'grid';
        }
    });

    const choose = useCallback((next: View) => {
        setView(next);

        try {
            window.localStorage.setItem('pos.view', next);
        } catch {
            // Remembering is a convenience; the choice still applies now.
        }
    }, []);

    return [view, choose];
}

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
            {edge.left && (
                <button
                    type="button"
                    className="pos-cat-nav"
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

function StockBadge({ row }: { row: StockRow }) {
    const out = row.usable <= 0;

    return (
        <span className={`pos-stock${out ? ' is-out' : row.is_low ? ' is-low' : ''}`}>
            {out ? 'Out of stock' : `In stock: ${row.usable}`}
        </span>
    );
}

/**
 * The counter.
 *
 * Figures for the day across the top; the shelf on the left with the
 * patients the doctors have just finished under it; the bill down the whole
 * right-hand side — the things a cashier looks at, with nothing between them.
 * The shelf is BROWSABLE rather than search-only: most counter sales are a
 * handful of fast-moving items, and making somebody type the name of the
 * thing in front of them is slower than letting them tap it.
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
    const client = useQueryClient();

    const [term, setTerm] = useState('');
    const [category, setCategory] = useState('');
    const [sort, setSort] = useState<Sort>('recent');
    const [view, setView] = useRememberedView();
    const [page, setPage] = useState(1);

    const [lines, setLines] = useState<CartLine[]>([]);
    const [customerId, setCustomerId] = useState('');
    /** A patient picked from OPD who may not be in the first page of customers. */
    const [picked, setPicked] = useState<{ id: number; name: string; hint: string } | null>(null);
    const [walkIn, setWalkIn] = useState({ name: '', phone: '' });
    const [note, setNote] = useState<string | null>(null);
    const [method, setMethod] = useState('cash');
    const [tendered, setTendered] = useState('');
    const [discountMode, setDiscountMode] = useState<'percent' | 'amount'>('amount');
    const [discountInput, setDiscountInput] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [bill, setBill] = useState<Sale | null>(null);

    /*
     * The ways this counter may be paid: the organisation's list from Billing
     * settings, one list for every counter. Credit is never one of them — it
     * is the absence of a payment, decided by the pharmacy's credit setting —
     * and Split is not a tender either, but a bill settled by more than one.
     */
    const tenders = Object.entries(PAYMENT_METHOD_LABELS).filter(
        ([value]) =>
            value !== 'credit' &&
            value !== 'split' &&
            (!settings?.accepted_payment_methods || settings.accepted_payment_methods.includes(value)),
    );

    // The chosen method has to be one the counter may take.
    useEffect(() => {
        if (tenders.length > 0 && !tenders.some(([value]) => value === method)) {
            setMethod(tenders[0][0]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [settings?.accepted_payment_methods?.join(','), method]);

    const cartKey = useRef(newIdempotencyKey());
    const searchBox = useRef<HTMLInputElement>(null);

    const { data: stock, isFetching } = useStock(store?.id, {
        search: term,
        category,
        sort,
        page,
        per_page: PER_PAGE,
    });

    const { data: categories } = useStoreCategories(store?.id);
    const { data: customers } = customersHooks.useList({ per_page: 100 });
    const { data: day, isLoading: dayLoading } = usePharmacyDashboard(store?.id);
    const handoff = useOpdHandoff(store?.id);
    const sell = useCreateSale(store?.id);

    const customerOptions = useMemo(() => {
        const options = (customers ?? []).map((customer) => ({
            value: String(customer.id),
            label: customer.name,
            // Searched as well as shown, so a typed or scanned ID finds them.
            hint: [customer.code, customer.phone].filter(Boolean).join(' · ') || undefined,
        }));

        if (picked && !options.some((option) => option.value === String(picked.id))) {
            options.unshift({ value: String(picked.id), label: picked.name, hint: picked.hint || undefined });
        }

        return options;
    }, [customers, picked]);

    /**
     * What the bill will come to, priced exactly as SalePricing prices it.
     *
     * Line by line, rounded to the paisa at each step, so the figure on screen
     * is the figure on the receipt. The DISCOUNT IS A PERCENTAGE under the
     * surface, because that is what the sale API models — one rate per line. A
     * rupee figure is converted to the rate that produces it, so the number
     * on screen is the number sent.
     *
     * Whether GST is added or already inside the price is the organisation's
     * setting: Indian MRP includes it, so by default the bill splits the tax
     * out rather than adding it on.
     */
    const totals = useMemo(() => {
        const gross = round2(
            lines.reduce((sum, line) => sum + round2((line.batch?.price ?? 0) * line.quantity), 0),
        );

        const typed = Number(discountInput) || 0;

        const percent = round2(
            discountMode === 'percent'
                ? Math.min(100, Math.max(0, typed))
                : gross > 0
                  ? Math.min(100, Math.max(0, (typed / gross) * 100))
                  : 0,
        );

        const included = settings?.prices_include_tax ?? true;

        let subtotal = 0;
        let tax = 0;
        let discount = 0;

        for (const line of lines) {
            const lineGross = round2((line.batch?.price ?? 0) * line.quantity);
            const lineDiscount = round2((lineGross * percent) / 100);
            const net = round2(lineGross - lineDiscount);
            const rate = line.taxRate;

            const taxable = included ? (rate > 0 ? round2(net / (1 + rate / 100)) : net) : net;
            const lineTax = included ? round2(net - taxable) : round2((net * rate) / 100);

            subtotal += taxable;
            tax += lineTax;
            discount += lineDiscount;
        }

        const payable = round2(round2(subtotal) + round2(tax));
        const rounded = settings?.round_off_enabled ? Math.round(payable) : payable;

        const rates = [...new Set(lines.map((line) => line.taxRate).filter((rate) => rate > 0))];

        return {
            gross,
            percent,
            discount: round2(discount),
            tax: round2(tax),
            included,
            rate: rates.length === 1 ? rates[0] : null,
            rounded,
            roundOff: round2(rounded - payable),
        };
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
                const mrp = Number(usable[0].mrp);

                batch = {
                    id: usable[0].id,
                    number: usable[0].batch_number,
                    expiry: usable[0].expiry_date,
                    mrp,
                    price: settings?.price_basis === 'selling' ? Number(usable[0].selling_price) : mrp,
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
                name: [rowName(row), row.strength].filter(Boolean).join(' '),
                unit: row.base_unit ?? 'unit',
                quantity: 1,
                available: row.usable,
                taxRate: row.tax_rate ?? 0,
                batch,
            },
        ]);
    }

    /**
     * Enter in the search box adds the one thing it found.
     *
     * Which is what a barcode scanner does: it types the code and presses
     * Enter. Asked of the server afresh rather than read off the grid, because
     * a scanner types faster than the grid can catch up with it.
     */
    async function addFromSearch() {
        const typed = term.trim();

        if (!typed || !store) {
            return;
        }

        try {
            const { data } = await http.get<Page<StockRow>>(`/tenant/pharmacy-stores/${store.id}/stock`, {
                params: { search: typed, per_page: 2 },
                silent: true,
            });

            const [only, ...more] = data.data;

            if (only && more.length === 0 && only.usable > 0) {
                await add(only);
                setTerm('');
                setPage(1);
            }
        } catch {
            // Nothing added; the grid below still shows what matched.
        }
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
        setPicked(null);
        setWalkIn({ name: '', phone: '' });
        setNote(null);
        setTendered('');
        setDiscountInput('');
        setError(null);
        cartKey.current = newIdempotencyKey();
    }

    function pickPatient(patient: OpdPatient) {
        if (patient.patient.id === null) {
            return;
        }

        setPicked({
            id: patient.patient.id,
            name: patient.patient.name ?? 'Patient',
            hint: [patient.patient.code, patient.patient.phone].filter(Boolean).join(' · '),
        });
        setCustomerId(String(patient.patient.id));
    }

    async function checkout() {
        if (lines.length === 0 || sell.isPending) {
            return;
        }

        setError(null);

        const payload: SaleInput = {
            customer_id: customerId ? Number(customerId) : null,
            walk_in_name: customerId ? null : walkIn.name || null,
            walk_in_phone: customerId ? null : walkIn.phone || null,
            notes: note?.trim() || null,
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

            // The shelf, the day's figures and the OPD list all just moved.
            void client.invalidateQueries({ queryKey: ['tenant/pharmacy', 'stock', store?.id] });
            void client.invalidateQueries({ queryKey: [storesApi.endpoint, store?.id] });
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    // Ctrl+Enter bills from anywhere on the screen — the hint on the button.
    const checkoutNow = useRef(checkout);
    checkoutNow.current = checkout;

    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault();
                void checkoutNow.current();
            }
        }

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

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

    const counts = countOpdTabs(handoff.query.data?.patients ?? []);

    /*
     * The day at a glance. The OPD three only where the branch hands patients
     * over; a medical shop with no OPD sees what is running short instead.
     */
    const tiles: StatTile[] = [
        ...(handoff.shown
            ? [
                  { label: 'Waiting (OPD)', value: counts.pending, icon: 'ti ti-users' },
                  { label: 'In Progress', value: counts.in_progress, icon: 'ti ti-progress' },
                  {
                      label: 'Completed (Today)',
                      value: counts.completed,
                      icon: 'ti ti-rosette-discount-check',
                      tone: 'emerald' as const,
                  },
              ]
            : []),
        {
            label: 'Total Sales (Today)',
            value: day ? day.today.bills : '—',
            icon: 'ti ti-shopping-cart',
            tone: 'sky',
        },
        {
            label: 'Total Amount (Today)',
            value: day ? rupees.format(day.today.sales) : '—',
            icon: 'ti ti-cash',
            tone: 'emerald',
        },
        ...(handoff.shown
            ? []
            : [
                  {
                      label: 'Low stock',
                      value: day ? day.stock.low : '—',
                      icon: 'ti ti-alert-triangle',
                      tone: 'amber' as const,
                  },
                  {
                      label: 'Expiring soon',
                      value: day ? day.stock.expiring : '—',
                      icon: 'ti ti-calendar-time',
                      tone: 'rose' as const,
                  },
              ]),
    ];

    const taxLabel = `GST${totals.rate !== null ? ` ${totals.rate}%` : ''}`;

    return (
        <>
            <PageHeader
                title="Pharmacy Counter"
                subtitle="Quick billing and medicine dispensing"
                icon="ti ti-building-store"
                tone="teal"
                home={false}
                // Only where there is a choice to make.
                actions={
                    stores.length > 1 ? (
                        <StorePicker stores={stores} value={store} onChange={choose} />
                    ) : undefined
                }
            />

            <div className="pos-stats">
                <StatTiles tiles={tiles} loading={dayLoading || (handoff.shown && handoff.query.isLoading)} />
            </div>

            <div className="pos-grid">
                <div className="pos-main">
                    {/* ------------------------------------------ the shelf */}
                    <Card
                        className="pos-panel pos-shelf"
                        title="Products"
                        icon="ti ti-cube"
                        actions={
                            <div className="pos-tools">
                                <select
                                    className="form-select pos-sort"
                                    value={sort}
                                    aria-label="Order the shelf"
                                    onChange={(event) => {
                                        setSort(event.target.value as Sort);
                                        setPage(1);
                                    }}
                                >
                                    {SORTS.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>

                                <div className="pos-view" role="group" aria-label="Layout">
                                    <button
                                        type="button"
                                        className={view === 'grid' ? 'is-on' : undefined}
                                        aria-pressed={view === 'grid'}
                                        aria-label="Grid"
                                        title="Grid"
                                        onClick={() => setView('grid')}
                                    >
                                        <i className="ti ti-layout-grid" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        className={view === 'list' ? 'is-on' : undefined}
                                        aria-pressed={view === 'list'}
                                        aria-label="List"
                                        title="List"
                                        onClick={() => setView('list')}
                                    >
                                        <i className="ti ti-list" aria-hidden="true" />
                                    </button>
                                </div>
                            </div>
                        }
                    >
                        <div className="pos-search-row">
                            <div className="pos-search">
                                <i className="ti ti-search" aria-hidden="true" />
                                <input
                                    ref={searchBox}
                                    type="search"
                                    className="form-control"
                                    placeholder="Search medicine, brand, barcode or SKU..."
                                    aria-label="Search the shelf"
                                    value={term}
                                    onChange={(event) => {
                                        setTerm(event.target.value);
                                        setPage(1);
                                    }}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey) {
                                            event.preventDefault();
                                            void addFromSearch();
                                        }
                                    }}
                                    autoFocus
                                />
                            </div>

                            {/* A USB scanner types into whatever has focus, so
                                "scan" is: give the box focus and get out of the way. */}
                            <button
                                type="button"
                                className="pos-icon-btn is-lg"
                                title="Scan a barcode — it adds the item straight to the bill"
                                aria-label="Scan a barcode"
                                onClick={() => {
                                    searchBox.current?.focus();
                                    searchBox.current?.select();
                                }}
                            >
                                <i className="ti ti-barcode" aria-hidden="true" />
                            </button>
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
                        ) : view === 'grid' ? (
                            <div className="pos-products">
                                {rows.map((row) => {
                                    const out = row.usable <= 0;
                                    const maker = makerLine(row);

                                    return (
                                        <div
                                            className={`pos-product${out ? ' is-out' : ''}`}
                                            key={row.medicine_id}
                                            title={
                                                row.next_expiry && !out
                                                    ? `Nearest expiry ${formatDate(row.next_expiry)}`
                                                    : undefined
                                            }
                                        >
                                            <div className="pos-product-body">
                                                <b>{rowName(row)}</b>
                                                {maker && <small>{maker}</small>}
                                                <StockBadge row={row} />
                                            </div>

                                            <div className="pos-product-foot">
                                                <span className="pos-price">
                                                    <b>{row.price === null ? '—' : money(row.price)}</b>
                                                    <small>{packLabel(row)}</small>
                                                </span>

                                                <button
                                                    type="button"
                                                    className="pos-add"
                                                    disabled={out}
                                                    onClick={() => void add(row)}
                                                    aria-label={`Add ${rowName(row)}`}
                                                >
                                                    Add
                                                    <i className="ti ti-plus" aria-hidden="true" />
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="pos-list-wrap">
                                <table className="pos-list">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Stock</th>
                                            <th className="is-num">Price</th>
                                            <th aria-label="Add" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row) => {
                                            const maker = makerLine(row);

                                            return (
                                                <tr key={row.medicine_id}>
                                                    <td>
                                                        <span className="pos-list-name">
                                                            <b>{rowName(row)}</b>
                                                            {maker && <small>{maker}</small>}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <StockBadge row={row} />
                                                    </td>
                                                    <td className="is-num">
                                                        <span className="pos-price is-inline">
                                                            <b>{row.price === null ? '—' : money(row.price)}</b>
                                                            <small>{packLabel(row)}</small>
                                                        </span>
                                                    </td>
                                                    <td className="is-act">
                                                        <button
                                                            type="button"
                                                            className="pos-add"
                                                            disabled={row.usable <= 0}
                                                            onClick={() => void add(row)}
                                                            aria-label={`Add ${rowName(row)}`}
                                                        >
                                                            Add
                                                            <i className="ti ti-plus" aria-hidden="true" />
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {meta && meta.last_page > 1 && (
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

                    {/*
                        Who the doctor just finished with — only where this
                        branch runs both the OPD and the pharmacy. "Add to Bill"
                        makes them the bill's customer.
                    */}
                    <OpdPatientsPanel
                        handoff={handoff}
                        onPick={pickPatient}
                        onBill={customerId ? Number(customerId) : null}
                    />
                </div>

                {/* ----------------------------------------------- the bill */}
                <Card
                    className="pos-panel pos-bill"
                    bodyClassName="pos-bill-body"
                    title="Current bill"
                    icon="ti ti-file-invoice"
                    actions={
                        <button
                            type="button"
                            className="pos-clear"
                            onClick={clear}
                            disabled={lines.length === 0 && customerId === '' && note === null}
                        >
                            <i className="ti ti-trash" aria-hidden="true" />
                            Clear
                        </button>
                    }
                >
                    {/* Who the bill is for: set once, so it stays at the top. */}
                    <div className="pos-bill-top">
                        <div className="pos-customer">
                            <div className="pos-customer-select">
                                <span className="pos-customer-av" aria-hidden="true">
                                    <i className="ti ti-user" />
                                </span>

                                <SearchableSelect
                                    id="pos-customer"
                                    value={customerId}
                                    onChange={(next) => setCustomerId(next)}
                                    placeholder="Walk-in customer"
                                    ariaLabel="Customer"
                                    clearable
                                    options={customerOptions}
                                />
                            </div>

                            <button
                                type="button"
                                className="pos-icon-btn is-lg"
                                title="Find a patient by name, phone or patient ID"
                                aria-label="Find a patient"
                                onClick={() => document.getElementById('pos-customer')?.click()}
                            >
                                <i className="ti ti-scan" aria-hidden="true" />
                            </button>
                        </div>

                        {!customerId && (
                            <div className="pos-walkin">
                                <input
                                    className="form-control"
                                    value={walkIn.name}
                                    placeholder="Name (optional)"
                                    aria-label="Walk-in name"
                                    onChange={(event) => setWalkIn({ ...walkIn, name: event.target.value })}
                                />

                                <input
                                    className="form-control"
                                    value={walkIn.phone}
                                    placeholder="Phone (optional)"
                                    aria-label="Walk-in phone"
                                    inputMode="tel"
                                    onChange={(event) => setWalkIn({ ...walkIn, phone: event.target.value })}
                                />
                            </div>
                        )}
                    </div>

                    {/*
                     * ONLY THE LINES SCROLL. Everything that takes the money —
                     * tender, total, button — is pinned below, because a till
                     * that has to be scrolled before every sale gets scrolled
                     * all day.
                     */}
                    <div className="pos-bill-scroll">
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

                                <button type="button" className="pos-dismiss" onClick={() => setBill(null)}>
                                    Dismiss
                                </button>
                            </div>
                        )}

                        {lines.length === 0 ? (
                            !bill && (
                                <EmptyState
                                    icon="ti ti-shopping-cart"
                                    title="Nothing on this bill yet"
                                    description="Tap Add on a product, or scan its barcode."
                                />
                            )
                        ) : (
                            <div className="pos-lines-wrap">
                                <table className="pos-lines">
                                    <thead>
                                        <tr>
                                            <th className="pos-line-no">#</th>
                                            <th>Item</th>
                                            <th className="is-center">Qty</th>
                                            <th className="is-num">MRP (₹)</th>
                                            <th className="is-num">Price (₹)</th>
                                            <th className="is-num">Total (₹)</th>
                                            <th aria-label="Remove" />
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {lines.map((line, index) => {
                                            const price = line.batch?.price ?? 0;
                                            const short = line.quantity > line.available;

                                            return (
                                                <tr key={line.medicine_id}>
                                                    <td className="pos-line-no">{index + 1}</td>

                                                    <td className="pos-line-item">
                                                        <span>{line.name}</span>
                                                        {short && (
                                                            <small className="pos-line-warn">
                                                                Only {line.available} in stock
                                                            </small>
                                                        )}
                                                    </td>

                                                    <td className="is-center">
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
                                                    </td>

                                                    <td className="is-num">
                                                        {line.batch ? line.batch.mrp.toFixed(2) : '—'}
                                                    </td>
                                                    <td className="is-num">{price.toFixed(2)}</td>
                                                    <td className="is-num pos-line-total">
                                                        {(price * line.quantity).toFixed(2)}
                                                    </td>

                                                    <td className="is-act">
                                                        <button
                                                            type="button"
                                                            className="pos-remove"
                                                            aria-label={`Remove ${line.name}`}
                                                            onClick={() => remove(index)}
                                                        >
                                                            <i className="ti ti-trash" aria-hidden="true" />
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* A sentence for whoever packs it: printed on the bill. */}
                        {note === null ? (
                            <button type="button" className="pos-note-add" onClick={() => setNote('')}>
                                <i className="ti ti-plus" aria-hidden="true" />
                                Add Note (e.g. special instruction)
                            </button>
                        ) : (
                            <div className="pos-note">
                                <textarea
                                    className="form-control"
                                    rows={2}
                                    maxLength={2000}
                                    value={note}
                                    placeholder="e.g. Take after food. Keep refrigerated."
                                    aria-label="Note on this bill"
                                    autoFocus
                                    onChange={(event) => setNote(event.target.value)}
                                />
                                <button
                                    type="button"
                                    className="pos-note-drop"
                                    aria-label="Remove the note"
                                    onClick={() => setNote(null)}
                                >
                                    <i className="ti ti-x" aria-hidden="true" />
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="pos-bill-foot">
                        <dl className="pos-totals">
                            <div>
                                <dt>Items ({lines.length})</dt>
                                <dd>{money(totals.gross)}</dd>
                            </div>

                            <div className="pos-discount-row">
                                <dt>
                                    Discount
                                    {totals.discount > 0 && (
                                        <span className="pos-minus">− {money(totals.discount)}</span>
                                    )}
                                </dt>
                                <dd>
                                    <span className="pos-discount">
                                        <span className="pos-seg" role="group" aria-label="Discount in">
                                            <button
                                                type="button"
                                                className={discountMode === 'amount' ? 'is-on' : undefined}
                                                aria-pressed={discountMode === 'amount'}
                                                onClick={() => setDiscountMode('amount')}
                                            >
                                                ₹
                                            </button>
                                            <button
                                                type="button"
                                                className={discountMode === 'percent' ? 'is-on' : undefined}
                                                aria-pressed={discountMode === 'percent'}
                                                onClick={() => setDiscountMode('percent')}
                                            >
                                                %
                                            </button>
                                        </span>

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
                                    </span>
                                </dd>
                            </div>

                            {/*
                             * Indian MRP has the tax inside it, so by default
                             * the bill splits GST out rather than adding it —
                             * "+ GST" would make the screen disagree with the
                             * receipt by the tax amount.
                             */}
                            <div>
                                <dt>
                                    Tax ({taxLabel}
                                    {totals.included ? ', incl.' : ''})
                                </dt>
                                <dd>{money(totals.tax)}</dd>
                            </div>

                            {totals.roundOff !== 0 && (
                                <div>
                                    <dt>Round off</dt>
                                    <dd>{money(totals.roundOff)}</dd>
                                </div>
                            )}
                        </dl>

                        <div className="pos-payable">
                            <span>Total amount</span>
                            <b>{money(totals.rounded)}</b>
                        </div>

                        <div className="pos-methods" role="group" aria-label="Payment method">
                            {tenders.map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className={`pos-method${method === value ? ' is-active' : ''}`}
                                        aria-pressed={method === value}
                                        onClick={() => setMethod(value)}
                                    >
                                        <i className={METHOD_ICONS[value] ?? 'ti ti-dots'} aria-hidden="true" />
                                        {label}
                                    </button>
                                ))}
                        </div>

                        {/* Notes a cashier is handed, so the change is worked
                            out without typing. Cash only — there is nothing to
                            tender on a card. */}
                        {method === 'cash' && (
                            <div className="pos-cash" role="group" aria-label="Amount taken">
                                {QUICK_CASH.map((amount) => (
                                    <button
                                        key={amount}
                                        type="button"
                                        className={`pos-note-btn${tendered === String(amount) ? ' is-active' : ''}`}
                                        onClick={() => setTendered(String(amount))}
                                    >
                                        ₹{amount}
                                    </button>
                                ))}

                                <input
                                    type="number"
                                    className="form-control"
                                    min={0}
                                    step="0.01"
                                    value={tendered}
                                    placeholder="0.00"
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

                        <Button
                            className="pos-generate"
                            onClick={() => void checkout()}
                            loading={sell.isPending}
                            disabled={lines.length === 0}
                            icon="ti ti-file-invoice"
                        >
                            Generate Bill
                            <kbd className="pos-kbd">Ctrl + Enter</kbd>
                        </Button>
                    </div>
                </Card>
            </div>
        </>
    );
}
