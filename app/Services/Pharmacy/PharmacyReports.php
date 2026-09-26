<?php

namespace App\Services\Pharmacy;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySaleItem;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Models\Tenant\StockInwardItem;
use App\Models\Tenant\StoreMedicine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The pharmacy's reports, each readable two ways.
 *
 * Every report answers the same question twice: `summary()` is the shape of
 * it — headline figures and a chart — and `rows()` is the evidence, a page
 * at a time. The two are built from the SAME filtered query, which is the
 * point: a summary somebody cannot drill into is a number they have to take
 * on trust, and a table with no summary is a spreadsheet.
 *
 * About one store and one date range, both chosen by whoever is reading.
 * Cancelled bills are excluded from everything that counts money — they are
 * reversed, not deleted, and counting them would double the day.
 *
 * "Usable" stock is active and not past its expiry, the same definition the
 * stock screen and the dashboard use. The expiry report is the exception: it
 * is about what is going to stop being usable, so it looks past that.
 */
class PharmacyReports
{
    public const SALES = 'sales';

    public const PURCHASES = 'purchases';

    public const STOCK = 'stock';

    public const EXPIRY = 'expiry';

    public const PROFIT = 'profit';

    public const GST = 'gst';

    /** Every report this service can produce. */
    public const REPORTS = [
        self::SALES, self::PURCHASES, self::STOCK, self::EXPIRY, self::PROFIT, self::GST,
    ];

    /**
     * The capability each report is sold under — `reports.sales`, and so on.
     *
     * Kept here, next to the reports themselves, rather than spelled out again
     * wherever a route needs "any report at all": a seventh report is one line
     * in this file, not a second place to remember to update.
     */
    public const CAPABILITIES = ['reports.sales', 'reports.purchases', 'reports.stock', 'reports.expiry', 'reports.profit', 'reports.gst'];

    /** Reports about a span of time; the rest are about the shelf right now. */
    public const DATED = [self::SALES, self::PURCHASES, self::PROFIT, self::GST];

    /**
     * The headline figures and the chart behind one report.
     *
     * @return array<string, mixed>
     */
    public function summary(string $report, PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        return match ($report) {
            self::SALES => $this->salesSummary($store, $from, $to),
            self::PURCHASES => $this->purchasesSummary($store, $from, $to),
            self::STOCK => $this->stockSummary($store),
            self::EXPIRY => $this->expirySummary($store),
            self::PROFIT => $this->profitSummary($store, $from, $to),
            self::GST => $this->gstSummary($store, $from, $to),
        };
    }

    /**
     * The rows behind it, a page at a time.
     *
     * @return LengthAwarePaginator<int, mixed>
     */
    public function rows(string $report, PharmacyStore $store, Carbon $from, Carbon $to, int $perPage, string $search = ''): LengthAwarePaginator
    {
        return match ($report) {
            self::SALES => $this->salesRows($store, $from, $to, $perPage, $search),
            self::PURCHASES => $this->purchasesRows($store, $from, $to, $perPage, $search),
            self::STOCK => $this->stockRows($store, $perPage, $search),
            self::EXPIRY => $this->expiryRows($store, $perPage, $search),
            self::PROFIT => $this->profitRows($store, $from, $to, $perPage, $search),
            self::GST => $this->gstRows($store, $from, $to, $perPage),
        };
    }

    /* ---------------------------------------------------------------------
     | Sales
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function salesSummary(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $totals = $this->sales($store, $from, $to)
            ->selectRaw('COUNT(*) AS bills, COALESCE(SUM(total_amount), 0) AS total')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) AS paid')
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) AS due')
            ->first();

        $bills = (int) ($totals?->bills ?? 0);
        $total = (float) ($totals?->total ?? 0);

        $tenders = PharmacySalePayment::query()
            ->whereHas('sale', fn (Builder $sale) => $this->soldBetween($sale, $store, $from, $to))
            ->selectRaw('method AS label, SUM(amount) AS value')
            ->groupBy('method')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();

        return [
            'figures' => [
                ['label' => 'Taken', 'value' => $total, 'money' => true],
                ['label' => 'Bills', 'value' => $bills],
                ['label' => 'Average bill', 'value' => $bills > 0 ? round($total / $bills, 2) : 0, 'money' => true],
                ['label' => 'Still owed', 'value' => (float) ($totals?->due ?? 0), 'money' => true],
            ],
            'series' => $this->perDay($this->sales($store, $from, $to), 'sale_date', 'total_amount', $from, $to),
            'splits' => [
                ['title' => 'How it was paid', 'kind' => 'donut', 'tenders' => true, 'rows' => $tenders],
                ['title' => 'What sold', 'kind' => 'bars', 'rows' => $this->topSold($store, $from, $to)],
            ],
        ];
    }

    /** @return LengthAwarePaginator<int, PharmacySale> */
    private function salesRows(PharmacyStore $store, Carbon $from, Carbon $to, int $perPage, string $search): LengthAwarePaginator
    {
        return $this->sales($store, $from, $to)
            ->with(['customer', 'payments'])
            ->withCount('items')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('sale_number', 'ILIKE', "%{$search}%")
                    ->orWhere('walk_in_name', 'ILIKE', "%{$search}%")
            ))
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* ---------------------------------------------------------------------
     | Purchases
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function purchasesSummary(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $totals = $this->purchases($store, $from, $to)
            ->selectRaw('COUNT(*) AS notes, COALESCE(SUM(total_amount), 0) AS total')
            ->first();

        $notes = (int) ($totals?->notes ?? 0);
        $total = (float) ($totals?->total ?? 0);

        $bySupplier = $this->purchases($store, $from, $to)
            ->join('suppliers', 'suppliers.id', '=', 'stock_inwards.supplier_id')
            ->selectRaw('suppliers.name AS label, SUM(stock_inwards.total_amount) AS value')
            ->groupBy('suppliers.name')
            ->orderByDesc('value')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();

        return [
            'figures' => [
                ['label' => 'Bought in', 'value' => $total, 'money' => true],
                ['label' => 'Goods received notes', 'value' => $notes],
                ['label' => 'Average note', 'value' => $notes > 0 ? round($total / $notes, 2) : 0, 'money' => true],
            ],
            'series' => $this->perDay($this->purchases($store, $from, $to), 'received_date', 'total_amount', $from, $to),
            'splits' => [
                ['title' => 'By supplier', 'kind' => 'donut', 'rows' => $bySupplier],
                ['title' => 'Most bought in', 'kind' => 'bars', 'rows' => $this->topBought($store, $from, $to)],
            ],
        ];
    }

    /** @return LengthAwarePaginator<int, StockInward> */
    private function purchasesRows(PharmacyStore $store, Carbon $from, Carbon $to, int $perPage, string $search): LengthAwarePaginator
    {
        return $this->purchases($store, $from, $to)
            ->with('supplier')
            ->withCount('items')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('inward_number', 'ILIKE', "%{$search}%")
                    ->orWhere('supplier_invoice_no', 'ILIKE', "%{$search}%")
            ))
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* ---------------------------------------------------------------------
     | Stock
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function stockSummary(PharmacyStore $store): array
    {
        $totals = $this->usable($store)
            ->selectRaw('COALESCE(SUM(quantity_available), 0) AS units')
            ->selectRaw('COALESCE(SUM(quantity_available * purchase_price), 0) AS at_cost')
            ->selectRaw('COALESCE(SUM(quantity_available * mrp), 0) AS at_mrp')
            ->selectRaw('COUNT(DISTINCT medicine_id) AS items')
            ->first();

        $byCategory = $this->usable($store)
            ->join('medicines', 'medicines.id', '=', 'medicine_batches.medicine_id')
            ->selectRaw("COALESCE(NULLIF(medicines.category, ''), 'Uncategorised') AS label")
            ->selectRaw('SUM(medicine_batches.quantity_available * medicine_batches.purchase_price) AS value')
            ->groupBy('label')
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();

        $atCost = (float) ($totals?->at_cost ?? 0);
        $atMrp = (float) ($totals?->at_mrp ?? 0);

        return [
            'figures' => [
                ['label' => 'Value at cost', 'value' => $atCost, 'money' => true],
                ['label' => 'Value at MRP', 'value' => $atMrp, 'money' => true],
                // What the shelf would make if all of it sold at MRP.
                ['label' => 'Margin in stock', 'value' => round($atMrp - $atCost, 2), 'money' => true],
                ['label' => 'Items stocked', 'value' => (int) ($totals?->items ?? 0)],
            ],
            'series' => [],
            'splits' => [
                ['title' => 'Value by category', 'kind' => 'donut', 'rows' => $byCategory],
                ['title' => 'How the shelf is doing', 'kind' => 'bars', 'money' => false, 'rows' => $this->shelfHealth($store)],
            ],
        ];
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function stockRows(PharmacyStore $store, int $perPage, string $search): LengthAwarePaginator
    {
        return $this->usable($store)
            ->join('medicines', 'medicines.id', '=', 'medicine_batches.medicine_id')
            ->selectRaw('medicine_batches.medicine_id')
            ->selectRaw('MAX(medicines.generic_name) AS generic_name, MAX(medicines.brand_name) AS brand_name')
            ->selectRaw('MAX(medicines.strength) AS strength, MAX(medicines.base_unit) AS base_unit')
            ->selectRaw("MAX(COALESCE(NULLIF(medicines.category, ''), 'Uncategorised')) AS category")
            ->selectRaw('COUNT(*) AS batches, SUM(medicine_batches.quantity_available) AS units')
            ->selectRaw('SUM(medicine_batches.quantity_available * medicine_batches.purchase_price) AS at_cost')
            ->selectRaw('SUM(medicine_batches.quantity_available * medicine_batches.mrp) AS at_mrp')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('medicines.generic_name', 'ILIKE', "%{$search}%")
                    ->orWhere('medicines.brand_name', 'ILIKE', "%{$search}%")
            ))
            ->groupBy('medicine_batches.medicine_id')
            ->orderByDesc('at_cost')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* ---------------------------------------------------------------------
     | Expiry
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function expirySummary(PharmacyStore $store): array
    {
        $today = Carbon::today();

        /*
         * Four buckets, because they are four different decisions: what is
         * already dead stock, what has to move this month, and what can be
         * watched. One number for "expiring" hides which of those it is.
         */
        $row = $this->expiring($store)
            ->selectRaw('COALESCE(SUM(CASE WHEN expiry_date <= ? THEN quantity_available * purchase_price ELSE 0 END), 0) AS expired', [$today->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN expiry_date > ? AND expiry_date <= ? THEN quantity_available * purchase_price ELSE 0 END), 0) AS in_30', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN expiry_date > ? AND expiry_date <= ? THEN quantity_available * purchase_price ELSE 0 END), 0) AS in_60', [$today->copy()->addDays(30)->toDateString(), $today->copy()->addDays(60)->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN expiry_date > ? AND expiry_date <= ? THEN quantity_available * purchase_price ELSE 0 END), 0) AS in_90', [$today->copy()->addDays(60)->toDateString(), $today->copy()->addDays(90)->toDateString()])
            ->selectRaw('COUNT(*) AS batches, COALESCE(SUM(quantity_available), 0) AS units')
            ->first();

        $expired = (float) ($row?->expired ?? 0);

        return [
            'figures' => [
                ['label' => 'Already expired', 'value' => $expired, 'money' => true],
                ['label' => 'At risk in 90 days', 'value' => round((float) ($row?->in_30 ?? 0) + (float) ($row?->in_60 ?? 0) + (float) ($row?->in_90 ?? 0), 2), 'money' => true],
                ['label' => 'Batches', 'value' => (int) ($row?->batches ?? 0)],
                ['label' => 'Units', 'value' => (int) ($row?->units ?? 0)],
            ],
            'series' => [],
            'splits' => [[
                'title' => 'Value at cost, by how long is left',
                'kind' => 'bars',
                'rows' => [
                    ['label' => 'Already expired', 'value' => $expired, 'muted' => true],
                    ['label' => 'Within 30 days', 'value' => (float) ($row?->in_30 ?? 0)],
                    ['label' => '31 to 60 days', 'value' => (float) ($row?->in_60 ?? 0)],
                    ['label' => '61 to 90 days', 'value' => (float) ($row?->in_90 ?? 0)],
                ],
            ]],
        ];
    }

    /** @return LengthAwarePaginator<int, MedicineBatch> */
    private function expiryRows(PharmacyStore $store, int $perPage, string $search): LengthAwarePaginator
    {
        return $this->expiring($store)
            ->with('medicine')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('batch_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('medicine', fn (Builder $medicine) => $medicine->withTrashed()->where(
                        fn (Builder $names) => $names
                            ->where('generic_name', 'ILIKE', "%{$search}%")
                            ->orWhere('brand_name', 'ILIKE', "%{$search}%")
                    ))
            ))
            ->orderBy('expiry_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* ---------------------------------------------------------------------
     | Profit
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function profitSummary(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $row = $this->soldItems($store, $from, $to)
            ->selectRaw('COALESCE(SUM(taxable_amount), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(COALESCE(unit_cost, 0) * quantity), 0) AS cost')
            ->selectRaw('COALESCE(SUM(quantity), 0) AS units')
            ->first();

        $revenue = (float) ($row?->revenue ?? 0);
        $cost = (float) ($row?->cost ?? 0);
        $margin = round($revenue - $cost, 2);

        $earners = $this->profitByMedicine($store, $from, $to)
            ->orderByDesc('margin')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'label' => trim(($item->brand_name ?: $item->generic_name).' '.(string) $item->strength),
                'value' => (float) $item->margin,
            ])
            ->all();

        return [
            'figures' => [
                ['label' => 'Revenue', 'value' => $revenue, 'money' => true, 'hint' => 'Before GST'],
                ['label' => 'Cost of what sold', 'value' => $cost, 'money' => true],
                ['label' => 'Margin', 'value' => $margin, 'money' => true],
                ['label' => 'Margin %', 'value' => $revenue > 0 ? round($margin / $revenue * 100, 1) : 0, 'suffix' => '%'],
            ],
            'series' => [],
            'splits' => [['title' => 'Where the margin came from', 'kind' => 'bars', 'rows' => $earners]],
        ];
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function profitRows(PharmacyStore $store, Carbon $from, Carbon $to, int $perPage, string $search): LengthAwarePaginator
    {
        return $this->profitByMedicine($store, $from, $to)
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('medicines.generic_name', 'ILIKE', "%{$search}%")
                    ->orWhere('medicines.brand_name', 'ILIKE', "%{$search}%")
            ))
            ->orderByDesc('margin')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* ---------------------------------------------------------------------
     | GST
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function gstSummary(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $row = $this->soldItems($store, $from, $to)
            ->selectRaw('COALESCE(SUM(taxable_amount), 0) AS taxable, COALESCE(SUM(tax_amount), 0) AS tax')
            ->first();

        $byRate = $this->soldItems($store, $from, $to)
            ->selectRaw('tax_rate, SUM(tax_amount) AS tax')
            ->groupBy('tax_rate')
            ->orderByDesc('tax')
            ->get()
            ->map(fn ($item) => [
                'label' => rtrim(rtrim(number_format((float) $item->tax_rate, 2), '0'), '.').'%',
                'value' => (float) $item->tax,
            ])
            ->all();

        return [
            'figures' => [
                ['label' => 'Taxable value', 'value' => (float) ($row?->taxable ?? 0), 'money' => true],
                ['label' => 'GST collected', 'value' => (float) ($row?->tax ?? 0), 'money' => true],
                // Half each, on a sale within the state; the split is only
                // ever presentational, so it is worked out for the return.
                ['label' => 'CGST', 'value' => round((float) ($row?->tax ?? 0) / 2, 2), 'money' => true],
                ['label' => 'SGST', 'value' => round((float) ($row?->tax ?? 0) / 2, 2), 'money' => true],
            ],
            'series' => [],
            'splits' => [['title' => 'GST by rate', 'kind' => 'donut', 'rows' => $byRate]],
        ];
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function gstRows(PharmacyStore $store, Carbon $from, Carbon $to, int $perPage): LengthAwarePaginator
    {
        return $this->soldItems($store, $from, $to)
            ->selectRaw("COALESCE(NULLIF(hsn_code_snapshot, ''), '—') AS hsn, tax_rate")
            ->selectRaw('SUM(quantity) AS units, SUM(taxable_amount) AS taxable, SUM(tax_amount) AS tax')
            ->groupBy('hsn', 'tax_rate')
            ->orderByDesc('tax')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * What the window's money was spent on, by item.
     *
     * @return list<array{label: string, value: float}>
     */
    private function topSold(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        return $this->soldItems($store, $from, $to)
            ->selectRaw('item_name_snapshot AS label, SUM(line_total) AS value')
            ->groupBy('item_name_snapshot')
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();
    }

    /**
     * What the deliveries were mostly spent on.
     *
     * @return list<array{label: string, value: float}>
     */
    private function topBought(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        return StockInwardItem::query()
            ->join('medicines', 'medicines.id', '=', 'stock_inward_items.medicine_id')
            ->whereHas('inward', fn (Builder $inward) => $inward
                ->where('pharmacy_store_id', $store->id)
                ->where('status', StockInward::POSTED)
                ->whereBetween('received_date', [$from->copy()->toDateString(), $to->copy()->toDateString()]))
            ->selectRaw("COALESCE(NULLIF(medicines.brand_name, ''), medicines.generic_name) AS label")
            ->selectRaw('SUM(stock_inward_items.line_total) AS value')
            ->groupBy('label')
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (float) $row->value])
            ->all();
    }

    /**
     * The state the shelf is in, counted rather than valued.
     *
     * Four states a medicine can be in, and each is a different decision:
     * sell it, reorder it, chase it, or write it off. Counts, not rupees —
     * "38 out of stock" is the sentence somebody acts on; what those 38
     * would have been worth is a different question.
     *
     * @return list<array{label: string, value: float, muted?: bool}>
     */
    private function shelfHealth(PharmacyStore $store): array
    {
        $usable = $this->usable($store)
            ->selectRaw('medicine_id, SUM(quantity_available) AS units')
            ->groupBy('medicine_id')
            ->pluck('units', 'medicine_id');

        $levels = StoreMedicine::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('is_active', true)
            ->pluck('reorder_level', 'medicine_id');

        $inStock = 0;
        $low = 0;
        $out = 0;

        foreach ($levels as $medicineId => $level) {
            $units = (int) ($usable[$medicineId] ?? 0);

            match (true) {
                $units === 0 => $out++,
                $units <= (int) $level => $low++,
                default => $inStock++,
            };
        }

        $expired = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '<=', Carbon::today())
            ->distinct()
            ->count('medicine_id');

        return [
            ['label' => 'In stock', 'value' => $inStock],
            ['label' => 'Low stock', 'value' => $low],
            ['label' => 'Out of stock', 'value' => $out],
            ['label' => 'Expired stock', 'value' => $expired, 'muted' => true],
        ];
    }

    /* ---------------------------------------------------------------------
     | The queries every report is cut from
     |------------------------------------------------------------------- */

    /** Completed bills this store issued in the window. */
    private function sales(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->whereBetween('sale_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /** Posted goods received notes in the window. */
    private function purchases(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return StockInward::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('status', StockInward::POSTED)
            ->whereBetween('received_date', [$from->copy()->toDateString(), $to->copy()->toDateString()]);
    }

    /** What the counter could hand over today. */
    private function usable(PharmacyStore $store): Builder
    {
        return MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('status', MedicineBatch::ACTIVE)
            ->whereDate('expiry_date', '>', Carbon::today())
            ->where('quantity_available', '>', 0);
    }

    /**
     * Stock that will stop being sellable, and stock that already has.
     *
     * The only query here that looks past the expiry date, because that is
     * the whole subject: what is about to be worth nothing.
     */
    private function expiring(PharmacyStore $store): Builder
    {
        return MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->whereIn('status', [MedicineBatch::ACTIVE, MedicineBatch::EXPIRED])
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '<=', Carbon::today()->addDays(90));
    }

    /** Lines from completed bills in the window. */
    private function soldItems(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return PharmacySaleItem::query()
            ->whereHas('sale', fn (Builder $sale) => $this->soldBetween($sale, $store, $from, $to));
    }

    /** Sold lines rolled up per medicine, with what each made. */
    private function profitByMedicine(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return $this->soldItems($store, $from, $to)
            ->join('medicines', 'medicines.id', '=', 'pharmacy_sale_items.medicine_id')
            ->selectRaw('pharmacy_sale_items.medicine_id')
            ->selectRaw('MAX(medicines.generic_name) AS generic_name, MAX(medicines.brand_name) AS brand_name')
            ->selectRaw('MAX(medicines.strength) AS strength')
            ->selectRaw('SUM(pharmacy_sale_items.quantity) AS units')
            ->selectRaw('SUM(pharmacy_sale_items.taxable_amount) AS revenue')
            ->selectRaw('SUM(COALESCE(pharmacy_sale_items.unit_cost, 0) * pharmacy_sale_items.quantity) AS cost')
            ->selectRaw('SUM(pharmacy_sale_items.taxable_amount - COALESCE(pharmacy_sale_items.unit_cost, 0) * pharmacy_sale_items.quantity) AS margin')
            ->groupBy('pharmacy_sale_items.medicine_id');
    }

    /** @param  Builder<PharmacySale>  $sale */
    private function soldBetween(Builder $sale, PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return $sale->where('pharmacy_store_id', $store->id)
            ->where('status', PharmacySale::COMPLETED)
            ->whereBetween('sale_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * A figure per day across the window, including the days nothing
     * happened — a line that skips its empty days lies about the shape.
     *
     * @return list<array{date: string, label: string, title: string, value: float}>
     */
    private function perDay(Builder $query, string $column, string $sum, Carbon $from, Carbon $to): array
    {
        $totals = $query
            ->selectRaw("{$column}::date AS day, SUM({$sum}) AS total")
            ->groupByRaw("{$column}::date")
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->day => (float) $row->total]);

        $series = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $key = $day->toDateString();

            $series[] = [
                'date' => $key,
                'label' => $day->format('d M'),
                'title' => $day->format('D, d M'),
                'value' => round($totals[$key] ?? 0, 2),
            ];
        }

        return $series;
    }
}
