<?php

namespace App\Services\Pharmacy;

use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySaleItem;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySetting;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Models\Tenant\StoreMedicine;
use Illuminate\Support\Carbon;

/**
 * One counter's morning: what it took yesterday, what it is worth today, and
 * what will stop it selling tomorrow.
 *
 * About ONE store, never a sum across several. Stock that is low at Pratapgarh
 * is not helped by a full shelf at Allahabad, and takings added across two
 * counters answer a question nobody standing at either one is asking.
 *
 * Read-only throughout, and deliberately few queries: every figure is a
 * grouped aggregate rather than a collection walked in PHP, and the three
 * lists at the bottom load their names in one further query each. A dashboard
 * that costs a query per medicine is a dashboard people stop opening.
 *
 * "Usable" here means what the counter could actually hand over — active, not
 * past its expiry — and is the same definition MedicineBatchController::stock
 * uses. Anything else would print a stock value the shelf cannot honour.
 */
class PharmacyDashboard
{
    /** The window the chart covers, today included. */
    private const DAYS = 7;

    /** @return array<string, mixed> */
    public function for(PharmacyStore $store): array
    {
        $settings = PharmacySetting::current();

        $today = Carbon::today();
        $yesterday = (clone $today)->subDay();
        $from = (clone $today)->subDays(self::DAYS - 1);

        $sales = $this->salesByDay($store, $from, $today);
        $purchases = $this->purchasesByDay($store, $from, $today);

        $todayKey = $today->toDateString();
        $yesterdayKey = $yesterday->toDateString();

        $soldToday = (float) ($sales[$todayKey]['total'] ?? 0);
        $soldYesterday = (float) ($sales[$yesterdayKey]['total'] ?? 0);

        $levels = $this->stockLevels($store);

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'branch' => $store->location?->name,
            ],

            'date' => $todayKey,

            'today' => [
                'sales' => $soldToday,
                'bills' => (int) ($sales[$todayKey]['bills'] ?? 0),
                'purchases' => (float) ($purchases[$todayKey] ?? 0),
                'gross_profit' => $this->grossProfit($store, $today),
            ],

            'yesterday' => [
                'sales' => $soldYesterday,
                'bills' => (int) ($sales[$yesterdayKey]['bills'] ?? 0),
            ],

            'change' => $this->change($soldToday, $soldYesterday),

            'stock' => [
                ...$this->shelfValue($store, $today, $settings->expiry_warning_days),
                'low' => $levels['low'],
                'out' => $levels['out'],
                'stocked' => $levels['stocked'],
                'expiry_warning_days' => $settings->expiry_warning_days,
            ],

            'outstanding' => $this->outstanding($store),

            'series' => $this->series($from, $sales, $purchases),

            /*
             * The week, not the day. A counter's takings swing about far too
             * much for one morning to say anything: what sells, what it is
             * paid with and what each customer spends only become questions
             * with an answer over a few days.
             */
            'week' => $this->week($store, $from),

            'recent_bills' => $this->recentBills($store),
            'low_stock' => $levels['rows'],
            'expiring' => $this->expiring($store, $today, $settings->expiry_warning_days),
        ];
    }

    /* ---------------------------------------------------------------------
     | The week: what sold, what it was paid with, what a bill comes to
     |------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function week(PharmacyStore $store, Carbon $from): array
    {
        $totals = PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->where('sale_date', '>=', $from->copy()->startOfDay())
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS sold, COUNT(*) AS bills')
            ->first();

        $bills = (int) ($totals?->bills ?? 0);
        $sold = (float) ($totals?->sold ?? 0);

        return [
            'sold' => $sold,
            'bills' => $bills,
            // What a customer spends in one visit — the figure a shop grows.
            'average_bill' => $bills > 0 ? round($sold / $bills, 2) : 0.0,
            'items' => $this->itemsSold($store, $from),

            'top_items' => $this->topItems($store, $from),
            'categories' => $this->categories($store, $from),
            'tenders' => $this->tenders($store, $from),
        ];
    }

    /** Units handed over the counter in the window. */
    private function itemsSold(PharmacyStore $store, Carbon $from): int
    {
        return (int) PharmacySaleItem::query()
            ->whereHas('sale', fn ($sale) => $this->soldSince($sale, $store, $from))
            ->selectRaw('COALESCE(SUM(quantity), 0) AS units')
            ->value('units');
    }

    /**
     * What actually moves: the best sellers by what they took, not by units.
     *
     * By revenue rather than by count, because a shop that ranks by units
     * learns only that it sells a lot of paracetamol — true, and no use when
     * the question is what the week was made of.
     *
     * @return list<array<string, mixed>>
     */
    private function topItems(PharmacyStore $store, Carbon $from): array
    {
        $rows = PharmacySaleItem::query()
            ->whereHas('sale', fn ($sale) => $this->soldSince($sale, $store, $from))
            ->selectRaw('medicine_id, SUM(quantity) AS units, SUM(line_total) AS revenue')
            ->groupBy('medicine_id')
            ->orderByDesc('revenue')
            // A few more than the card shows, so it can say what the rest
            // came to rather than ending as if the eighth were the last.
            ->limit(14)
            ->get();

        // One query for the names, rather than one per row.
        $names = Medicine::query()
            ->withTrashed()
            ->whereIn('id', $rows->pluck('medicine_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(fn ($row) => [
            'medicine_id' => (int) $row->medicine_id,
            'name' => $names[$row->medicine_id]?->displayName() ?? 'Removed item',
            'units' => (int) $row->units,
            'revenue' => (float) $row->revenue,
        ])->all();
    }

    /**
     * Where the money came from, by what the catalogue calls each item.
     *
     * Joined rather than loaded: the question is about a column on medicines,
     * and answering it per sale line in PHP would read the catalogue once for
     * every line sold this week.
     *
     * @return list<array{label: string, value: float}>
     */
    private function categories(PharmacyStore $store, Carbon $from): array
    {
        return PharmacySaleItem::query()
            ->join('medicines', 'medicines.id', '=', 'pharmacy_sale_items.medicine_id')
            ->whereHas('sale', fn ($sale) => $this->soldSince($sale, $store, $from))
            ->selectRaw("COALESCE(NULLIF(medicines.category, ''), 'Uncategorised') AS label")
            ->selectRaw('SUM(pharmacy_sale_items.line_total) AS value')
            ->groupBy('label')
            ->orderByDesc('value')
            ->limit(6)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();
    }

    /**
     * How the week was paid for.
     *
     * Credit is not a tender — it is the absence of one — so what is unpaid
     * is counted from the bills themselves rather than from a payment row
     * that was never written.
     *
     * @return list<array{label: string, value: float, muted?: bool}>
     */
    private function tenders(PharmacyStore $store, Carbon $from): array
    {
        $taken = PharmacySalePayment::query()
            ->whereHas('sale', fn ($sale) => $this->soldSince($sale, $store, $from))
            ->selectRaw('method AS label, SUM(amount) AS value')
            ->groupBy('method')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();

        $owed = (float) PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->where('sale_date', '>=', $from->copy()->startOfDay())
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) AS due')
            ->value('due');

        if ($owed > 0) {
            $taken[] = ['label' => 'On account', 'value' => $owed, 'muted' => true];
        }

        return $taken;
    }

    /** The bills this store completed since a date, for a whereHas on items. */
    private function soldSince($sale, PharmacyStore $store, Carbon $from)
    {
        return $sale->where('pharmacy_store_id', $store->id)
            ->where('status', PharmacySale::COMPLETED)
            ->where('sale_date', '>=', $from->copy()->startOfDay());
    }

    /* ---------------------------------------------------------------------
     | Money
     |------------------------------------------------------------------- */

    /**
     * Takings per day over the window, cancelled bills excluded.
     *
     * One query answers the chart AND the two headline figures: today and
     * yesterday are both inside the window, so asking for them separately
     * would be three questions where one does.
     *
     * @return array<string, array{total: float, bills: int}>
     */
    private function salesByDay(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        return PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->whereBetween('sale_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('sale_date::date AS day')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->selectRaw('COUNT(*) AS bills')
            ->groupByRaw('sale_date::date')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->day => ['total' => (float) $row->total, 'bills' => (int) $row->bills],
            ])
            ->all();
    }

    /**
     * What was received per day, at what the invoices said.
     *
     * A cancelled note never happened as far as money is concerned — its
     * stock went back off the books, so its value must not stay in the chart.
     *
     * @return array<string, float>
     */
    private function purchasesByDay(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        return StockInward::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('status', StockInward::POSTED)
            ->whereBetween('received_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('received_date AS day')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->groupBy('received_date')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->day => (float) $row->total])
            ->all();
    }

    /**
     * What the day's selling actually made.
     *
     * Against the cost copied onto each line when it was billed, never a cost
     * looked up now: a batch bought cheaper next week must not rewrite what
     * today's bills earned. A line with no cost recorded contributes its full
     * taxable amount, which is the honest reading of "we do not know what it
     * cost" — and is why StockInwardService insists on a purchase price.
     */
    private function grossProfit(PharmacyStore $store, Carbon $day): float
    {
        return (float) PharmacySaleItem::query()
            ->whereHas(
                'sale',
                fn ($sale) => $sale->where('pharmacy_store_id', $store->id)
                    ->where('status', PharmacySale::COMPLETED)
                    ->whereBetween('sale_date', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]),
            )
            ->selectRaw('COALESCE(SUM(taxable_amount - COALESCE(unit_cost, 0) * quantity), 0) AS profit')
            ->value('profit');
    }

    /** What customers still owe on bills this store issued. */
    private function outstanding(PharmacyStore $store): float
    {
        return (float) PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) AS due')
            ->value('due');
    }

    /**
     * Null when yesterday took nothing.
     *
     * A rise from zero has no percentage, and printing one — 100%, or worse
     * ∞ — is a number somebody would repeat in a meeting.
     */
    private function change(float $now, float $before): ?int
    {
        return $before > 0 ? (int) round((($now - $before) / $before) * 100) : null;
    }

    /* ---------------------------------------------------------------------
     | Stock
     |------------------------------------------------------------------- */

    /**
     * What is on the shelf, at cost, and how much of it is about to be worth
     * nothing.
     *
     * Both read the same set — usable batches — so they are one query with a
     * FILTER rather than two passes over the same index.
     *
     * @return array{value_at_cost: float, expiring: int}
     */
    private function shelfValue(PharmacyStore $store, Carbon $today, int $warningDays): array
    {
        $row = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('status', MedicineBatch::ACTIVE)
            ->whereDate('expiry_date', '>', $today->toDateString())
            ->selectRaw('COALESCE(SUM(quantity_available * purchase_price), 0) AS value_at_cost')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE quantity_available > 0 AND expiry_date <= ?) AS expiring',
                [$today->copy()->addDays($warningDays)->toDateString()],
            )
            ->first();

        return [
            'value_at_cost' => (float) $row->value_at_cost,
            'expiring' => (int) $row->expiring,
        ];
    }

    /**
     * What the store keeps, against what it has.
     *
     * Driven by store_medicines rather than by batches, because a medicine the
     * store stocks and has NONE of has no batch row to be counted from — and
     * that is precisely the row somebody opens this screen to find.
     *
     * Out of stock is the worst part of low stock, not a separate set: a
     * medicine at zero is both, and the two tiles are read as "how much is
     * running out" and "how much has already run out".
     *
     * @return array{low: int, out: int, stocked: int, rows: list<array<string, mixed>>}
     */
    private function stockLevels(PharmacyStore $store): array
    {
        $stocked = StoreMedicine::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('is_active', true)
            ->get(['id', 'medicine_id', 'reorder_level']);

        if ($stocked->isEmpty()) {
            return ['low' => 0, 'out' => 0, 'stocked' => 0, 'rows' => []];
        }

        $usable = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('status', MedicineBatch::ACTIVE)
            ->whereDate('expiry_date', '>', now()->toDateString())
            ->whereIn('medicine_id', $stocked->pluck('medicine_id'))
            ->selectRaw('medicine_id, COALESCE(SUM(quantity_available), 0) AS on_hand')
            ->groupBy('medicine_id')
            ->pluck('on_hand', 'medicine_id');

        $low = $stocked
            ->map(fn (StoreMedicine $row) => [
                'medicine_id' => $row->medicine_id,
                'on_hand' => (int) ($usable[$row->medicine_id] ?? 0),
                'reorder_level' => (int) $row->reorder_level,
            ])
            ->filter(fn (array $row) => $row['on_hand'] <= $row['reorder_level'])
            // Emptiest first: what has run out is more urgent than what is
            // merely getting there.
            ->sortBy('on_hand')
            ->values();

        $names = Medicine::withTrashed()
            ->whereIn('id', $low->take(8)->pluck('medicine_id'))
            ->get(['id', 'generic_name', 'brand_name', 'strength', 'dosage_form', 'base_unit'])
            ->keyBy('id');

        return [
            'low' => $low->count(),
            'out' => $low->where('on_hand', 0)->count(),
            'stocked' => $stocked->count(),
            'rows' => $low->take(8)->map(fn (array $row) => [
                ...$row,
                'name' => $names->get($row['medicine_id'])?->displayName() ?? 'Unknown',
                'unit' => $names->get($row['medicine_id'])?->base_unit,
            ])->all(),
        ];
    }

    /**
     * Batches close enough to their expiry to be worth moving.
     *
     * Empty batches are left out: a lot with nothing in it expires without
     * costing anybody anything, and listing it buries the ones that will.
     *
     * @return list<array<string, mixed>>
     */
    private function expiring(PharmacyStore $store, Carbon $today, int $warningDays): array
    {
        return MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('status', MedicineBatch::ACTIVE)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>', $today->toDateString())
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($warningDays)->toDateString())
            ->with('medicine')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->limit(6)
            ->get()
            ->map(fn (MedicineBatch $batch) => [
                'id' => $batch->id,
                'name' => $batch->medicine?->displayName() ?? 'Unknown',
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'days_left' => (int) $today->diffInDays($batch->expiry_date),
                'quantity' => (int) $batch->quantity_available,
            ])
            ->all();
    }

    /* ---------------------------------------------------------------------
     | The chart and the lists
     |------------------------------------------------------------------- */

    /**
     * Seven days, every one of them present.
     *
     * Built from the window rather than from the rows that came back: a day
     * with no takings is a quiet day and belongs on the chart, where leaving
     * it out would draw a week that looks busier than it was.
     *
     * @param  array<string, array{total: float, bills: int}>  $sales
     * @param  array<string, float>  $purchases
     * @return list<array<string, mixed>>
     */
    private function series(Carbon $from, array $sales, array $purchases): array
    {
        $points = [];

        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $day = $from->copy()->addDays($offset);
            $key = $day->toDateString();

            $points[] = [
                'date' => $key,
                'label' => $day->format('D'),
                'title' => $day->format('j M'),
                'sales' => (float) ($sales[$key]['total'] ?? 0),
                'purchases' => (float) ($purchases[$key] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * The last few bills, as the counter would read them back.
     *
     * The payment is what was actually tendered, not the `payment_status`
     * column: a bill settled half in cash and half by UPI is "split", and one
     * with no tender at all is credit — which is the absence of payment
     * rather than a method that takes money.
     *
     * @return list<array<string, mixed>>
     */
    private function recentBills(PharmacyStore $store): array
    {
        return PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->with(['customer:id,name', 'payments:id,pharmacy_sale_id,method'])
            ->withCount('items')
            ->latest('sale_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(function (PharmacySale $sale) {
                $methods = $sale->payments->pluck('method')->unique();

                return [
                    'id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'customer_name' => $sale->buyerName(),
                    'items_count' => (int) $sale->items_count,
                    'total_amount' => (float) $sale->total_amount,
                    'amount_due' => $sale->amountDue(),
                    'payment' => match (true) {
                        $methods->isEmpty() => 'credit',
                        $methods->count() > 1 => 'split',
                        default => (string) $methods->first(),
                    },
                    'sale_date' => $sale->sale_date?->toIso8601String(),
                ];
            })
            ->all();
    }
}
