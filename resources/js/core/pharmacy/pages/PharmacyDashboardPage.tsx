import { useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { StatTiles, type StatTile } from '@/shared/components/ui/StatTiles';
import { AreaChart } from '@/shared/components/ui/AreaChart';
import { BarList } from '@/shared/components/ui/BarList';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { usePharmacyDashboard } from '../api';
import { PAYMENT_METHOD_LABELS, type PharmacyDashboard } from '../types';

/** Rupees, grouped the Indian way — 12,48,500 rather than 1,248,500. */
function money(amount: number): string {
    return `₹${Math.round(amount).toLocaleString('en-IN')}`;
}

/*
 * "Walk-in customer" is what the counter stores when nobody is named, and in
 * a column this narrow it truncates to "Walk-in custo…" — two words of which
 * one is noise. Somebody who WAS named keeps their name.
 */
function soldTo(name: string): string {
    return name === 'Walk-in customer' ? 'Walk-in' : name;
}

function time(value: string | null): string {
    return value
        ? new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        : '—';
}

/** Today against yesterday, as a word somebody can act on. */
function changeHint(data: PharmacyDashboard): string {
    if (data.change === null) {
        return data.yesterday.sales > 0 ? 'vs yesterday' : 'Nothing sold yesterday';
    }

    const direction = data.change >= 0 ? 'up' : 'down';

    return `${direction} ${Math.abs(data.change)}% on yesterday`;
}

/* -------------------------------------------------------------------------- */

/**
 * The money across the top, because that is what somebody opens this for.
 *
 * Takings first, then what the day cost, then what it made, then what is still
 * owed — the order a counter closes its own day in.
 */
function moneyTiles(data: PharmacyDashboard): StatTile[] {
    return [
        {
            label: "Today's sales",
            value: money(data.today.sales),
            icon: 'ti ti-cash-register',
            tone: 'emerald',
            hint: `${data.today.bills} ${data.today.bills === 1 ? 'bill' : 'bills'} · ${changeHint(data)}`,
        },
        {
            label: 'Gross profit today',
            value: money(data.today.gross_profit),
            icon: 'ti ti-trending-up',
            tone: 'teal',
            hint: 'At what the stock cost',
        },
        {
            label: 'Purchases today',
            value: money(data.today.purchases),
            icon: 'ti ti-truck-delivery',
            tone: 'sky',
            hint: 'Received against goods notes',
        },
        {
            label: 'Customer outstanding',
            value: money(data.outstanding),
            icon: 'ti ti-wallet',
            tone: data.outstanding > 0 ? 'amber' : 'muted',
            hint: 'Owed on completed bills',
        },
    ];
}

/**
 * What the shelf is worth, and the three ways it stops being worth it.
 *
 * Out of stock is the worst part of low stock rather than a separate set — a
 * medicine at zero is counted in both, which is how the two tiles are read:
 * how much is running out, and how much already has.
 */
function stockTiles(data: PharmacyDashboard): StatTile[] {
    return [
        {
            label: 'Stock value at cost',
            value: money(data.stock.value_at_cost),
            icon: 'ti ti-building-warehouse',
            tone: 'indigo',
            hint: `${data.stock.stocked} medicines stocked here`,
        },
        {
            label: 'Low stock',
            value: data.stock.low,
            icon: 'ti ti-alert-triangle',
            tone: data.stock.low > 0 ? 'amber' : 'muted',
            hint: 'At or below the reorder level',
        },
        {
            label: 'Out of stock',
            value: data.stock.out,
            icon: 'ti ti-package-off',
            tone: data.stock.out > 0 ? 'rose' : 'muted',
            hint: 'Nothing left to dispense',
        },
        {
            label: 'Expiring soon',
            value: data.stock.expiring,
            icon: 'ti ti-clock-exclamation',
            tone: data.stock.expiring > 0 ? 'violet' : 'muted',
            hint: `Batches within ${data.stock.expiry_warning_days} days`,
        },
    ];
}

/* -------------------------------------------------------------------------- */

/** The last few bills, as the counter would read them back. */
function RecentBills({ data, storeId }: { data: PharmacyDashboard; storeId: number }) {
    return (
        <Card
            className="chart-card pd-panel"
            title="Recent bills"
            icon="ti ti-receipt"
            actions={
                <Link className="db-link" to={`/pharmacy/sales?store=${storeId}`}>
                    View all
                    <i className="ti ti-arrow-right" />
                </Link>
            }
        >
            {data.recent_bills.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-mood-empty" aria-hidden="true" />
                    Nothing sold here yet today.
                </p>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Bill</th>
                                <th>Sold to</th>
                                <th className="pd-num">Total</th>
                                <th className="pd-tender-col">Paid by</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.recent_bills.map((bill) => (
                                <tr key={bill.id}>
                                    <td className="pf-strong">
                                        {bill.sale_number}
                                        <span className="pd-sub">{time(bill.sale_date)}</span>
                                    </td>
                                    <td title={bill.customer_name}>
                                        {soldTo(bill.customer_name)}
                                        <span className="pd-sub">
                                            {bill.items_count} {bill.items_count === 1 ? 'line' : 'lines'}
                                        </span>
                                    </td>
                                    <td className="pd-num pf-strong">
                                        {money(bill.total_amount)}
                                        {bill.amount_due > 0 && (
                                            <span className="pd-sub">
                                                {money(bill.amount_due)} owed
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <span
                                            className={`pd-tender${bill.payment === 'credit' ? ' is-credit' : ''}`}
                                        >
                                            {PAYMENT_METHOD_LABELS[bill.payment] ?? bill.payment}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/** What to order, emptiest first. */
function LowStock({ data, storeId }: { data: PharmacyDashboard; storeId: number }) {
    return (
        <Card
            className="chart-card pd-panel"
            title="Low stock"
            icon="ti ti-alert-triangle"
            actions={
                <Link className="db-link" to={`/pharmacy/stock?store=${storeId}`}>
                    View stock
                    <i className="ti ti-arrow-right" />
                </Link>
            }
        >
            {data.low_stock.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-circle-check" aria-hidden="true" />
                    Everything this store keeps is above its reorder level.
                </p>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th className="pd-num">Stock</th>
                                <th className="pd-num">Reorder</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.low_stock.map((row) => (
                                <tr key={row.medicine_id}>
                                    <td className="pf-strong">{row.name}</td>
                                    <td className="pd-num">
                                        {/* Out is a different problem from low,
                                            and the two must not read the same. */}
                                        <span
                                            className={`pd-level${row.on_hand === 0 ? ' is-out' : ''}`}
                                        >
                                            {row.on_hand === 0 ? 'Out' : row.on_hand}
                                        </span>
                                    </td>
                                    <td className="pd-num">{row.reorder_level}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/** Batches worth moving before they cannot be sold at all. */
function Expiring({ data, storeId }: { data: PharmacyDashboard; storeId: number }) {
    return (
        <Card
            className="chart-card pd-panel"
            title="Expiry alerts"
            icon="ti ti-clock-exclamation"
            actions={
                <Link className="db-link" to={`/pharmacy/stock?store=${storeId}`}>
                    View batches
                    <i className="ti ti-arrow-right" />
                </Link>
            }
        >
            {data.expiring.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-circle-check" aria-hidden="true" />
                    Nothing expires in the next {data.stock.expiry_warning_days} days.
                </p>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th className="pd-tight">Batch</th>
                                <th className="pd-num">Left</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.expiring.map((row) => (
                                <tr key={row.id}>
                                    <td className="pf-strong" title={row.name}>
                                        {row.name}
                                        <span className="pd-sub">{row.quantity} left on the shelf</span>
                                    </td>
                                    <td className="pd-tight" title={row.batch_number}>
                                        {row.batch_number}
                                        <span className="pd-sub">{row.expiry_date}</span>
                                    </td>
                                    <td className="pd-num">
                                        {/* Days left is what somebody acts on;
                                            the date itself is the evidence. */}
                                        <span
                                            className={`pd-days${row.days_left <= 30 ? ' is-urgent' : ''}`}
                                        >
                                            {row.days_left}d
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/* -------------------------------------------------------------------------- */

/**
 * One store's day.
 *
 * Money first, because that is the question somebody opens a pharmacy
 * dashboard with; then the week behind it; then the three lists that say what
 * to do about tomorrow — what was sold, what to order, what to move.
 *
 * About ONE store, chosen the same way every other stock screen chooses one
 * and kept in the URL, so a link to this dashboard opens on the store somebody
 * meant. Figures summed across counters would answer nothing anybody standing
 * at one of them is asking.
 */
export default function PharmacyDashboardPage() {
    const navigate = useNavigate();

    const { can } = useTenantAuth();
    const canSell = can('pharmacy.sell');

    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();
    const { data, isLoading, isError, error, refetch } = usePharmacyDashboard(store?.id);

    // Rounded to whole rupees: the axis and the tooltip are read at a glance,
    // and paise on a week's takings are noise.
    const chart = useMemo(
        () => ({
            sales: (data?.series ?? []).map((point) => ({
                label: point.label,
                title: point.title,
                value: Math.round(point.sales),
            })),
            purchases: (data?.series ?? []).map((point) => ({
                label: point.label,
                title: point.title,
                value: Math.round(point.purchases),
            })),
            soldThisWeek: (data?.series ?? []).reduce((sum, point) => sum + point.sales, 0),
            boughtThisWeek: (data?.series ?? []).reduce((sum, point) => sum + point.purchases, 0),
        }),
        [data],
    );

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    return (
        <>
            <PageHeader
                title="Pharmacy dashboard"
                subtitle="What this counter took today, what it is holding, and what needs attention."
                icon="ti ti-layout-dashboard"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Dashboard' }]}
                actions={
                    canSell && store ? (
                        <Button
                            icon="ti ti-cash-register"
                            onClick={() => navigate(`/pharmacy/pos?store=${store.id}`)}
                        >
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
                    <div className="mb-3">
                        <StorePicker stores={stores} value={store} onChange={choose} />
                    </div>

                    {isLoading || !data ? (
                        isError ? (
                            <Card>
                                <ErrorState
                                    message={resolveErrorMessage(error)}
                                    onRetry={() => void refetch()}
                                />
                            </Card>
                        ) : (
                            <LoadingBlock label="Adding up the day…" />
                        )
                    ) : (
                        <>
                            <div className="pd-tiles">
                                <StatTiles tiles={moneyTiles(data)} />
                                <StatTiles tiles={stockTiles(data)} />
                            </div>

                            {/*
                                Two charts, not two series on one.

                                A shop takes a thousand rupees a day and buys
                                sixty thousand in one delivery. Drawn against a
                                shared axis, the takings — the line anybody
                                actually watches — flatten onto the floor and
                                say nothing. Each measure gets its own scale
                                and its own card instead.
                            */}
                            <div className="pd-charts">
                                <Card
                                    className="chart-card pd-chart"
                                    title="Daily takings"
                                    icon="ti ti-chart-area-line"
                                    description={`Last 7 days — ${money(chart.soldThisWeek)} sold`}
                                >
                                    <AreaChart points={chart.sales} valueLabel="sold" seriesLabel="Sales" />
                                </Card>

                                <Card
                                    className="chart-card pd-chart"
                                    title="Stock bought in"
                                    icon="ti ti-truck-delivery"
                                    description={`Last 7 days — ${money(chart.boughtThisWeek)} received`}
                                >
                                    <AreaChart
                                        points={chart.purchases}
                                        valueLabel="bought"
                                        seriesLabel="Purchases"
                                    />
                                </Card>
                            </div>

                            {/*
                                The week, under the day.

                                A counter's takings swing about far too much
                                for one morning to say anything: what sells,
                                what pays for it and what a customer spends
                                only become questions with answers over a few
                                days. Three shapes, because the three are
                                different questions — a ranking, a split, and
                                a split with an absence in it.
                            */}
                            <div className="pd-week">
                                <Card
                                    className="chart-card"
                                    title="What sells"
                                    icon="ti ti-trending-up"
                                    description={`Last 7 days — ${money(data.week.sold)} across ${data.week.bills} bills`}
                                >
                                    <BarList
                                        rows={data.week.top_items.map((item) => ({
                                            label: item.name,
                                            value: item.revenue,
                                        }))}
                                        format={money}
                                        max={8}
                                        empty="Nothing has sold here in the last seven days."
                                    />
                                </Card>

                                <Card
                                    className="chart-card"
                                    title="What it was made of"
                                    icon="ti ti-chart-donut"
                                    description="Takings by category"
                                >
                                    <DonutChart
                                        slices={data.week.categories}
                                        centreLabel="This week"
                                        format={money}
                                        empty="Nothing sold yet to divide up."
                                    />
                                </Card>

                                <Card
                                    className="chart-card"
                                    title="How it was paid"
                                    icon="ti ti-wallet"
                                    description={`${data.week.items} items · ${money(data.week.average_bill)} a bill`}
                                >
                                    <DonutChart
                                        slices={data.week.tenders.map((tender) => ({
                                            ...tender,
                                            label: PAYMENT_METHOD_LABELS[tender.label] ?? tender.label,
                                        }))}
                                        centreLabel="Taken"
                                        format={money}
                                        empty="Nothing taken yet."
                                    />
                                </Card>
                            </div>

                            <div className="pd-panels">
                                <RecentBills data={data} storeId={store.id} />
                                <LowStock data={data} storeId={store.id} />
                                <Expiring data={data} storeId={store.id} />
                            </div>
                        </>
                    )}
                </>
            )}
        </>
    );
}
