<?php

namespace App\Services\Pharmacy\Reports;

use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySaleItem;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StoreMedicine;
use App\Models\Tenant\Supplier;
use App\Services\Pharmacy\PharmacyReports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The dashboard view of each pharmacy report — four figures, two charts and
 * two short lists, the same shape for every report so the screen draws all
 * six with one renderer.
 *
 *   kpis    four figures, each with a change against the period before
 *           where one exists, and a small run of bars where the figure has
 *           a shape over the window
 *   charts  two: the wide one on the left is usually the window over time,
 *           the narrow one on the right how a total divides
 *   tables  two short lists — the leaders, and the latest
 *
 * Computed only when the reports screen asks for it (`dashboard=1` on the
 * summary): the sales and goods-received lists read the same summary for
 * their own headline figures and have no use for any of this.
 *
 * Every figure is cut from the same definitions PharmacyReports uses —
 * completed bills, posted goods received notes, usable stock — so a number
 * here is the number the table view adds up to.
 *
 * WHAT IS NOT HERE. The software records what a supplier delivered but not
 * what was paid to them, so there are no "pending dues"; and it records no
 * running costs, so there is no "net profit" beyond the gross margin on what
 * sold. Those slots carry true figures instead rather than invented ones.
 */
class ReportDashboards
{
    /** How many rows each short list shows. */
    private const ROWS = 5;

    /** How many bars the run in a figure's card has. */
    private const SPARK = 7;

    /** @return array<string, mixed> */
    public function for(string $report, PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        return match ($report) {
            PharmacyReports::SALES => $this->sales($store, $from, $to),
            PharmacyReports::PURCHASES => $this->purchases($store, $from, $to),
            PharmacyReports::STOCK => $this->stock($store),
            PharmacyReports::EXPIRY => $this->expiry($store),
            PharmacyReports::PROFIT => $this->profit($store, $from, $to),
            PharmacyReports::GST => $this->gst($store, $from, $to),
        };
    }

    /* =====================================================================
     | Sales
     |===================================================================== */

    /** @return array<string, mixed> */
    private function sales(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        [$before, $beforeEnd] = $this->previous($from, $to);

        $sold = $this->daily($this->bills($store, $from, $to), 'sale_date', 'total_amount - returned_amount');
        $soldBefore = $this->daily($this->bills($store, $before, $beforeEnd), 'sale_date', 'total_amount - returned_amount');
        $bought = $this->daily($this->inwards($store, $from, $to), 'received_date', 'total_amount');
        $boughtBefore = array_sum($this->daily($this->inwards($store, $before, $beforeEnd), 'received_date', 'total_amount'));

        $margin = $this->marginByDay($store, $from, $to);
        $revenue = array_sum(array_column($margin, 'revenue'));
        $profit = array_sum(array_column($margin, 'profit'));

        $levels = $this->levels($store);
        $days = $this->days($from, $to);

        return [
            'kpis' => [
                [
                    'key' => 'net_sales',
                    'label' => 'Net sales',
                    'value' => round(array_sum($sold), 2),
                    'money' => true,
                    'change' => $this->change(array_sum($sold), array_sum($soldBefore)),
                    'info' => 'Completed bills, less goods brought back',
                    'spark' => $this->spark($days, $sold),
                ],
                [
                    'key' => 'gross_profit',
                    'label' => 'Gross profit',
                    'value' => round($profit, 2),
                    'money' => true,
                    'hint' => $this->percent($profit, $revenue).' margin',
                    'info' => 'What sold, less what its stock cost',
                    'spark' => $this->spark($days, array_map(fn (array $day) => $day['profit'], $margin)),
                ],
                [
                    'key' => 'purchases',
                    'label' => 'Purchases',
                    'value' => round(array_sum($bought), 2),
                    'money' => true,
                    'change' => $this->change(array_sum($bought), $boughtBefore),
                    'cost' => true,
                    'info' => 'Stock received from suppliers',
                    'spark' => $this->spark($days, $bought),
                ],
                [
                    'key' => 'low_stock',
                    'label' => 'Low-stock alerts',
                    'value' => $levels['low'] + $levels['out'],
                    'suffix' => ' items',
                    'hint' => $levels['out'] > 0 ? "{$levels['out']} out of stock" : 'Nothing has run out',
                    'hint_tone' => $levels['out'] > 0 ? 'warn' : null,
                ],
            ],

            'charts' => [
                [
                    'title' => 'Sales overview',
                    'icon' => 'ti ti-chart-line',
                    'subtitle' => 'This period vs the period before',
                    'kind' => 'area',
                    'money' => true,
                    'granular' => true,
                    'points' => $this->points($from, $to),
                    'series' => [
                        ['label' => 'Sales (this period)', 'tone' => 'blue', 'values' => $this->align($days, $sold)],
                        [
                            'label' => 'Previous period',
                            'tone' => 'blue',
                            'dashed' => true,
                            // Day one against day one: the earlier period is
                            // laid over this one, however many days each has.
                            'values' => array_slice(
                                array_pad($this->align($this->days($before, $beforeEnd), $soldBefore), count($days), 0.0),
                                0,
                                count($days),
                            ),
                        ],
                    ],
                ],
                [
                    'title' => 'Sales by payment method',
                    'icon' => 'ti ti-wallet',
                    'subtitle' => 'Distribution of sales amount',
                    'kind' => 'donut',
                    'money' => true,
                    'centre' => 'Total sales',
                    'rows' => $this->tenders($store, $from, $to),
                ],
            ],

            'tables' => [
                [
                    'title' => 'Top-selling medicines',
                    'icon' => 'ti ti-trophy',
                    'subtitle' => 'By units sold in this period',
                    'link' => 'profit',
                    'columns' => [
                        ['key' => 'rank', 'label' => '#', 'format' => 'rank'],
                        ['key' => 'name', 'label' => 'Medicine name'],
                        ['key' => 'pack', 'label' => 'Pack size', 'format' => 'muted'],
                        ['key' => 'units', 'label' => 'Units sold', 'format' => 'count'],
                        ['key' => 'amount', 'label' => 'Net sales', 'format' => 'money'],
                        ['key' => 'share', 'label' => '% of sales', 'format' => 'percent'],
                    ],
                    'rows' => $this->topSelling($store, $from, $to),
                ],
                [
                    'title' => 'Recent sales',
                    'icon' => 'ti ti-clock',
                    'subtitle' => 'Latest pharmacy sales transactions',
                    'link' => 'sales',
                    'columns' => [
                        ['key' => 'date', 'label' => 'Date & time', 'format' => 'datetime'],
                        ['key' => 'number', 'label' => 'Invoice no.'],
                        ['key' => 'party', 'label' => 'Patient'],
                        ['key' => 'items', 'label' => 'Items', 'format' => 'count'],
                        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
                        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
                    ],
                    'rows' => $this->bills($store, $from, $to)
                        ->with('customer:id,name')
                        ->withCount('items')
                        ->latest('sale_date')
                        ->latest('id')
                        ->limit(self::ROWS)
                        ->get()
                        ->map(fn (PharmacySale $sale) => [
                            'id' => $sale->id,
                            'date' => $sale->sale_date?->toIso8601String(),
                            'number' => $sale->sale_number,
                            'party' => $sale->buyerName(),
                            'items' => (int) $sale->items_count,
                            'amount' => round((float) $sale->total_amount - (float) $sale->returned_amount, 2),
                            'status' => (string) $sale->payment_status,
                        ])
                        ->all(),
                ],
            ],
        ];
    }

    /**
     * How the period was paid for. What is still owed is a slice of its own
     * — credit is not a tender, it is the absence of one — so the ring adds
     * up to what was sold rather than to what was collected.
     *
     * @return list<array{label: string, value: float, key: string}>
     */
    private function tenders(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $rows = PharmacySalePayment::query()
            ->whereHas('sale', fn (Builder $sale) => $sale
                ->where('pharmacy_store_id', $store->id)
                ->where('status', PharmacySale::COMPLETED)
                ->whereBetween('sale_date', [$from, $to]))
            ->selectRaw('method, SUM('.PharmacySalePayment::NET_SQL.') AS value')
            ->groupBy('method')
            ->havingRaw('SUM('.PharmacySalePayment::NET_SQL.') > 0')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['key' => (string) $row->method, 'label' => (string) $row->method, 'value' => round((float) $row->value, 2)])
            ->all();

        $owed = (float) $this->bills($store, $from, $to)
            ->selectRaw('COALESCE(SUM('.PharmacySale::DUE_SQL.'), 0) AS due')
            ->value('due');

        if ($owed > 0) {
            $rows[] = ['key' => 'credit', 'label' => 'credit', 'value' => round($owed, 2)];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function topSelling(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $kept = '(pharmacy_sale_items.quantity - pharmacy_sale_items.returned_quantity)';

        $lines = $this->lines($store, $from, $to)
            ->join('medicines', 'medicines.id', '=', 'pharmacy_sale_items.medicine_id');

        $total = (float) (clone $lines)
            ->selectRaw("COALESCE(SUM(pharmacy_sale_items.line_total * {$kept}::numeric / pharmacy_sale_items.quantity), 0) AS total")
            ->value('total');

        return $lines
            ->selectRaw('pharmacy_sale_items.medicine_id')
            ->selectRaw('MAX(medicines.generic_name) AS generic_name, MAX(medicines.brand_name) AS brand_name, MAX(medicines.strength) AS strength')
            ->selectRaw('MAX(medicines.pack_size) AS pack_size, MAX(medicines.dosage_form) AS dosage_form')
            ->selectRaw("SUM({$kept}) AS units")
            ->selectRaw("SUM(pharmacy_sale_items.line_total * {$kept}::numeric / pharmacy_sale_items.quantity) AS amount")
            ->groupBy('pharmacy_sale_items.medicine_id')
            ->orderByDesc('units')
            ->orderByDesc('amount')
            ->limit(self::ROWS)
            ->get()
            ->values()
            ->map(fn ($row, int $index) => [
                'id' => (int) $row->medicine_id,
                'rank' => $index + 1,
                'name' => $this->name($row),
                'pack' => $this->pack($row->pack_size, $row->dosage_form),
                'units' => (int) $row->units,
                'amount' => round((float) $row->amount, 2),
                'share' => $total > 0 ? round((float) $row->amount / $total * 100, 1) : 0.0,
            ])
            ->all();
    }

    /* =====================================================================
     | Purchases
     |===================================================================== */

    /** @return array<string, mixed> */
    private function purchases(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        [$before, $beforeEnd] = $this->previous($from, $to);

        $bought = $this->daily($this->inwards($store, $from, $to), 'received_date', 'total_amount');
        $notes = $this->daily($this->inwards($store, $from, $to), 'received_date', '1');
        $boughtBefore = (float) $this->inwards($store, $before, $beforeEnd)->sum('total_amount');
        $notesBefore = $this->inwards($store, $before, $beforeEnd)->count();

        $total = array_sum($bought);
        $count = (int) array_sum($notes);
        $days = $this->days($from, $to);

        $suppliers = $this->inwards($store, $from, $to)->whereNotNull('supplier_id')->distinct()->count('supplier_id');

        $bySupplier = $this->inwards($store, $from, $to)
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_inwards.supplier_id')
            ->selectRaw("COALESCE(suppliers.name, 'No supplier') AS label, SUM(stock_inwards.total_amount) AS value")
            ->groupBy('label')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => round((float) $row->value, 2)]);

        return [
            'kpis' => [
                [
                    'key' => 'total_purchases',
                    'label' => 'Total purchases',
                    'value' => round($total, 2),
                    'money' => true,
                    'change' => $this->change($total, $boughtBefore),
                    'cost' => true,
                    'spark' => $this->spark($days, $bought),
                ],
                [
                    'key' => 'grns',
                    'label' => 'Goods received',
                    'value' => $count,
                    'change' => $this->change($count, $notesBefore),
                    'info' => 'Goods received notes posted',
                    'spark' => $this->spark($days, $notes),
                ],
                [
                    'key' => 'suppliers',
                    'label' => 'Suppliers',
                    'value' => $suppliers,
                    'hint' => Supplier::query()->where('is_active', true)->count().' active suppliers',
                ],
                [
                    'key' => 'average_purchase',
                    'label' => 'Avg. purchase value',
                    'value' => $count > 0 ? round($total / $count, 2) : 0.0,
                    'money' => true,
                    'hint' => 'Per goods received note',
                ],
            ],

            'charts' => [
                [
                    'title' => 'Purchase trend',
                    'icon' => 'ti ti-chart-bar',
                    'subtitle' => 'Stock received in this period',
                    'kind' => 'bars',
                    'money' => true,
                    'granular' => true,
                    'points' => $this->points($from, $to),
                    'series' => [['label' => 'Purchases', 'tone' => 'blue', 'values' => $this->align($days, $bought)]],
                ],
                [
                    'title' => 'Purchases by supplier',
                    'icon' => 'ti ti-chart-donut',
                    'subtitle' => 'Share of what was bought in',
                    'kind' => 'donut',
                    'money' => true,
                    'centre' => 'Total purchases',
                    'rows' => $this->foldTail($bySupplier, 4),
                ],
            ],

            'tables' => [
                [
                    'title' => 'Top suppliers',
                    'icon' => 'ti ti-truck-delivery',
                    'subtitle' => 'By value received in this period',
                    'link' => 'purchases',
                    'columns' => [
                        ['key' => 'rank', 'label' => '#', 'format' => 'rank'],
                        ['key' => 'name', 'label' => 'Supplier name'],
                        ['key' => 'notes', 'label' => 'GRNs', 'format' => 'count'],
                        ['key' => 'amount', 'label' => 'Purchases', 'format' => 'money'],
                        ['key' => 'last', 'label' => 'Last received', 'format' => 'date'],
                    ],
                    'rows' => $this->inwards($store, $from, $to)
                        ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_inwards.supplier_id')
                        ->selectRaw("stock_inwards.supplier_id, COALESCE(MAX(suppliers.name), 'No supplier') AS name")
                        ->selectRaw('COUNT(*) AS notes, SUM(stock_inwards.total_amount) AS amount, MAX(stock_inwards.received_date) AS last')
                        ->groupBy('stock_inwards.supplier_id')
                        ->orderByDesc('amount')
                        ->limit(self::ROWS)
                        ->get()
                        ->values()
                        ->map(fn ($row, int $index) => [
                            'id' => (int) $row->supplier_id,
                            'rank' => $index + 1,
                            'name' => (string) $row->name,
                            'notes' => (int) $row->notes,
                            'amount' => round((float) $row->amount, 2),
                            'last' => $row->last ? Carbon::parse($row->last)->toDateString() : null,
                        ])
                        ->all(),
                ],
                [
                    'title' => 'Recent purchases',
                    'icon' => 'ti ti-clock',
                    'subtitle' => 'Latest goods received notes',
                    'link' => 'purchases',
                    'columns' => [
                        ['key' => 'date', 'label' => 'Date', 'format' => 'date'],
                        ['key' => 'number', 'label' => 'GRN no.'],
                        ['key' => 'party', 'label' => 'Supplier'],
                        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
                        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
                    ],
                    'rows' => $this->inwards($store, $from, $to)
                        ->with('supplier:id,name')
                        ->latest('received_date')
                        ->latest('id')
                        ->limit(self::ROWS)
                        ->get()
                        ->map(fn (StockInward $inward) => [
                            'id' => $inward->id,
                            'date' => $inward->received_date?->toDateString(),
                            'number' => $inward->inward_number,
                            'party' => $inward->supplier?->name ?? '—',
                            'amount' => round((float) $inward->total_amount, 2),
                            'status' => 'received',
                        ])
                        ->all(),
                ],
            ],
        ];
    }

    /* =====================================================================
     | Stock
     |===================================================================== */

    /** @return array<string, mixed> */
    private function stock(PharmacyStore $store): array
    {
        $totals = $this->usable($store)
            ->selectRaw('COALESCE(SUM(quantity_available * purchase_price), 0) AS at_cost')
            ->selectRaw('COALESCE(SUM(quantity_available * mrp), 0) AS at_mrp')
            ->selectRaw('COUNT(DISTINCT medicine_id) AS items')
            ->first();

        $atCost = (float) ($totals?->at_cost ?? 0);
        $atMrp = (float) ($totals?->at_mrp ?? 0);
        $levels = $this->levels($store);

        $byCategory = $this->usable($store)
            ->join('medicines', 'medicines.id', '=', 'medicine_batches.medicine_id')
            ->selectRaw("COALESCE(NULLIF(medicines.category, ''), 'Uncategorised') AS label")
            ->selectRaw('SUM(medicine_batches.quantity_available * medicine_batches.purchase_price) AS value')
            ->groupBy('label')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => round((float) $row->value, 2)]);

        $expired = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '<=', Carbon::today())
            ->distinct()
            ->count('medicine_id');

        return [
            'kpis' => [
                ['key' => 'stock_cost', 'label' => 'Total stock value', 'value' => round($atCost, 2), 'money' => true, 'hint' => 'At cost price'],
                ['key' => 'stock_mrp', 'label' => 'Value at MRP', 'value' => round($atMrp, 2), 'money' => true, 'hint' => 'Margin of '.$this->rupees($atMrp - $atCost).' on the shelf'],
                ['key' => 'items_in_stock', 'label' => 'Items in stock', 'value' => (int) ($totals?->items ?? 0), 'hint' => $levels['stocked'].' items kept'],
                [
                    'key' => 'low_stock_items',
                    'label' => 'Low stock items',
                    'value' => $levels['low'] + $levels['out'],
                    'hint' => $levels['low'] + $levels['out'] > 0 ? 'Need attention' : 'All above reorder level',
                    'hint_tone' => $levels['low'] + $levels['out'] > 0 ? 'bad' : null,
                ],
            ],

            'charts' => [
                [
                    'title' => 'Value by category',
                    'icon' => 'ti ti-chart-donut',
                    'subtitle' => 'Usable stock at cost',
                    'kind' => 'donut',
                    'money' => true,
                    'centre' => 'Stock value',
                    'wide' => true,
                    'rows' => $this->foldTail($byCategory, 5),
                ],
                [
                    'title' => 'Stock status',
                    'icon' => 'ti ti-list-check',
                    'subtitle' => 'Items the store keeps, by where they stand',
                    'kind' => 'meter',
                    'money' => false,
                    'rows' => [
                        ['key' => 'in_stock', 'label' => 'In stock', 'value' => $levels['in']],
                        ['key' => 'out', 'label' => 'Out of stock', 'value' => $levels['out']],
                        ['key' => 'low', 'label' => 'Low stock', 'value' => $levels['low']],
                        ['key' => 'expired', 'label' => 'Expired stock', 'value' => $expired],
                    ],
                ],
            ],

            'tables' => [
                [
                    'title' => 'Low stock items',
                    'icon' => 'ti ti-alert-triangle',
                    'subtitle' => 'At or below their reorder level',
                    'link' => 'stock',
                    'columns' => [
                        ['key' => 'rank', 'label' => '#', 'format' => 'rank'],
                        ['key' => 'name', 'label' => 'Medicine name'],
                        ['key' => 'stock', 'label' => 'Current stock', 'format' => 'count'],
                        ['key' => 'level', 'label' => 'Min. level', 'format' => 'count'],
                        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
                    ],
                    'rows' => $levels['rows'],
                ],
                [
                    'title' => 'Recently updated stock',
                    'icon' => 'ti ti-refresh',
                    'subtitle' => 'The latest movements on the shelf',
                    'link' => 'stock',
                    'columns' => [
                        ['key' => 'date', 'label' => 'Date', 'format' => 'date'],
                        ['key' => 'name', 'label' => 'Medicine'],
                        ['key' => 'change', 'label' => 'Change', 'format' => 'delta'],
                        ['key' => 'stock', 'label' => 'Current stock', 'format' => 'count'],
                    ],
                    'rows' => $this->recentMovements($store),
                ],
            ],
        ];
    }

    /**
     * Where each medicine the store keeps stands against its reorder level.
     *
     * Driven by the store's own list rather than by its batches, because a
     * medicine it keeps and has none of has no batch to be found by — and is
     * the one somebody most needs to see.
     *
     * @return array{in: int, low: int, out: int, stocked: int, rows: list<array<string, mixed>>}
     */
    private function levels(PharmacyStore $store): array
    {
        $today = Carbon::today()->toDateString();

        $kept = StoreMedicine::query()
            ->leftJoin('medicine_batches AS stock', function (JoinClause $join) use ($today) {
                $join->on('stock.pharmacy_store_id', '=', 'store_medicines.pharmacy_store_id')
                    ->on('stock.medicine_id', '=', 'store_medicines.medicine_id')
                    ->where('stock.status', '=', MedicineBatch::ACTIVE)
                    ->where('stock.expiry_date', '>', $today)
                    ->whereNull('stock.deleted_at');
            })
            ->where('store_medicines.pharmacy_store_id', $store->id)
            ->where('store_medicines.is_active', true)
            ->select('store_medicines.medicine_id', 'store_medicines.reorder_level')
            ->selectRaw('COALESCE(SUM(stock.quantity_available), 0) AS on_hand')
            ->groupBy('store_medicines.id', 'store_medicines.medicine_id', 'store_medicines.reorder_level')
            ->get();

        $short = $kept
            ->filter(fn ($row) => (int) $row->on_hand <= (int) $row->reorder_level)
            // Emptiest first: what has run out comes before what is running low.
            ->sortBy(fn ($row) => (int) $row->on_hand)
            ->values();

        $names = Medicine::withTrashed()
            ->whereIn('id', $short->take(self::ROWS)->pluck('medicine_id'))
            ->get()
            ->keyBy('id');

        $out = $short->filter(fn ($row) => (int) $row->on_hand === 0)->count();

        return [
            'in' => $kept->count() - $short->count(),
            'low' => $short->count() - $out,
            'out' => $out,
            'stocked' => $kept->count(),
            'rows' => $short->take(self::ROWS)->values()->map(fn ($row, int $index) => [
                'id' => (int) $row->medicine_id,
                'rank' => $index + 1,
                'name' => $names->get($row->medicine_id)?->displayName() ?? 'Unknown',
                'stock' => (int) $row->on_hand,
                'level' => (int) $row->reorder_level,
                'status' => (int) $row->on_hand === 0 ? 'out' : 'low',
            ])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentMovements(PharmacyStore $store): array
    {
        $movements = StockMovement::query()
            ->where('pharmacy_store_id', $store->id)
            ->with('medicine')
            ->latest('movement_date')
            ->latest('id')
            ->limit(self::ROWS)
            ->get();

        // What each listed medicine has on the shelf now, in one query.
        $onHand = $this->usable($store)
            ->whereIn('medicine_id', $movements->pluck('medicine_id')->unique())
            ->selectRaw('medicine_id, SUM(quantity_available) AS units')
            ->groupBy('medicine_id')
            ->pluck('units', 'medicine_id');

        return $movements->map(fn (StockMovement $movement) => [
            'id' => $movement->id,
            'date' => $movement->movement_date?->toDateString(),
            'name' => $movement->medicine?->displayName() ?? 'Unknown',
            'change' => (int) $movement->quantity_after - (int) $movement->quantity_before,
            'stock' => (int) ($onHand[$movement->medicine_id] ?? 0),
        ])->all();
    }

    /* =====================================================================
     | Expiry
     |===================================================================== */

    /** @return array<string, mixed> */
    private function expiry(PharmacyStore $store): array
    {
        $today = Carbon::today();

        $onShelf = fn () => MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->whereIn('status', [MedicineBatch::ACTIVE, MedicineBatch::EXPIRED])
            ->where('quantity_available', '>', 0);

        $expired = $onShelf()->whereDate('expiry_date', '<=', $today->toDateString());
        $soon = $onShelf()
            ->whereDate('expiry_date', '>', $today->toDateString())
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(30)->toDateString());
        $atRisk = $onShelf()->whereDate('expiry_date', '<=', $today->copy()->addDays(90)->toDateString());

        // Six calendar months from this one, a count and a value for each.
        $months = collect(range(0, 5))->map(fn (int $i) => $today->copy()->startOfMonth()->addMonthsNoOverflow($i));

        $timeline = $onShelf()
            ->whereDate('expiry_date', '>', $today->toDateString())
            ->whereDate('expiry_date', '<=', $months->last()->copy()->endOfMonth()->toDateString())
            ->selectRaw("to_char(expiry_date, 'YYYY-MM') AS month, COUNT(*) AS batches")
            ->groupBy('month')
            ->pluck('batches', 'month');

        $byCategory = $atRisk
            ->clone()
            ->join('medicines', 'medicines.id', '=', 'medicine_batches.medicine_id')
            ->selectRaw("COALESCE(NULLIF(medicines.category, ''), 'Uncategorised') AS label, COUNT(*) AS value")
            ->groupBy('label')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (int) $row->value]);

        $batchRow = fn (MedicineBatch $batch, int $index) => [
            'id' => $batch->id,
            'rank' => $index + 1,
            'name' => $batch->medicine?->displayName() ?? 'Unknown',
            'batch' => $batch->batch_number,
            'expiry' => $batch->expiry_date?->toDateString(),
            'days' => (int) $today->diffInDays($batch->expiry_date, false),
            'stock' => (int) $batch->quantity_available,
            'value' => round((float) $batch->quantity_available * (float) $batch->purchase_price, 2),
        ];

        return [
            'kpis' => [
                [
                    'key' => 'total_items',
                    'label' => 'Total items',
                    'value' => $this->usable($store)->distinct()->count('medicine_id'),
                    'hint' => 'In stock',
                ],
                [
                    'key' => 'near_expiry',
                    'label' => 'Near expiry (30 days)',
                    'value' => $soon->count(),
                    'hint' => 'Take action',
                    'hint_tone' => 'bad',
                ],
                [
                    'key' => 'expired_items',
                    'label' => 'Expired items',
                    'value' => $expired->count(),
                    'hint' => 'Remove from shelf',
                    'hint_tone' => 'bad',
                ],
                [
                    'key' => 'expiry_value',
                    'label' => 'Expiry value',
                    'value' => round((float) $atRisk->clone()
                        ->selectRaw('COALESCE(SUM(quantity_available * purchase_price), 0) AS value')
                        ->value('value'), 2),
                    'money' => true,
                    'hint' => 'At cost price',
                    'info' => 'Expired stock, and stock expiring within 90 days',
                ],
            ],

            'charts' => [
                [
                    'title' => 'Expiry timeline (next 6 months)',
                    'icon' => 'ti ti-calendar-time',
                    'subtitle' => 'Batches on the shelf, by the month they expire',
                    'kind' => 'bars',
                    'money' => false,
                    'points' => $months->map(fn (Carbon $month) => [
                        'date' => $month->format('Y-m'),
                        'label' => $month->format('M Y'),
                        'title' => $month->format('F Y'),
                    ])->all(),
                    'series' => [[
                        'label' => 'Batches',
                        'tone' => 'blue',
                        'values' => $months->map(fn (Carbon $month) => (float) ($timeline[$month->format('Y-m')] ?? 0))->all(),
                    ]],
                ],
                [
                    'title' => 'Expiry by category',
                    'icon' => 'ti ti-chart-donut',
                    'subtitle' => 'Expired or expiring within 90 days',
                    'kind' => 'donut',
                    'money' => false,
                    'centre' => 'Items',
                    'rows' => $this->foldTail($byCategory, 5),
                ],
            ],

            'tables' => [
                [
                    'title' => 'Near expiry items',
                    'icon' => 'ti ti-hourglass-low',
                    'subtitle' => 'Expiring within 90 days, soonest first',
                    'link' => 'expiry',
                    'columns' => [
                        ['key' => 'rank', 'label' => '#', 'format' => 'rank'],
                        ['key' => 'name', 'label' => 'Medicine', 'sub' => 'batch', 'sub_prefix' => 'Batch '],
                        ['key' => 'expiry', 'label' => 'Expiry date', 'format' => 'expiry'],
                        ['key' => 'days', 'label' => 'Days left', 'format' => 'count'],
                        ['key' => 'stock', 'label' => 'Stock', 'format' => 'count'],
                        ['key' => 'value', 'label' => 'Value', 'format' => 'money'],
                    ],
                    'rows' => $atRisk->clone()
                        ->whereDate('expiry_date', '>', $today->toDateString())
                        ->with('medicine')
                        ->orderBy('expiry_date')
                        ->orderBy('id')
                        ->limit(self::ROWS)
                        ->get()
                        ->values()
                        ->map($batchRow)
                        ->all(),
                ],
                [
                    'title' => 'Expired items',
                    'icon' => 'ti ti-ban',
                    'subtitle' => 'Still on the shelf — not to be sold',
                    'link' => 'expiry',
                    'columns' => [
                        ['key' => 'rank', 'label' => '#', 'format' => 'rank'],
                        ['key' => 'name', 'label' => 'Medicine', 'sub' => 'batch', 'sub_prefix' => 'Batch '],
                        ['key' => 'expiry', 'label' => 'Expiry date', 'format' => 'expiry'],
                        ['key' => 'stock', 'label' => 'Stock', 'format' => 'count'],
                        ['key' => 'value', 'label' => 'Value', 'format' => 'money'],
                    ],
                    'rows' => $expired->clone()
                        ->with('medicine')
                        ->orderBy('expiry_date')
                        ->orderBy('id')
                        ->limit(self::ROWS)
                        ->get()
                        ->values()
                        ->map($batchRow)
                        ->all(),
                ],
            ],
        ];
    }

    /* =====================================================================
     | Profit
     |===================================================================== */

    /** @return array<string, mixed> */
    private function profit(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        [$before, $beforeEnd] = $this->previous($from, $to);

        $margin = $this->marginByDay($store, $from, $to);
        $marginBefore = $this->marginByDay($store, $before, $beforeEnd);

        $revenue = array_sum(array_column($margin, 'revenue'));
        $cost = array_sum(array_column($margin, 'cost'));
        $profit = $revenue - $cost;
        $bills = $this->bills($store, $from, $to)->count();

        $days = $this->days($from, $to);
        $byCategory = $this->profitByCategory($store, $from, $to);

        return [
            'kpis' => [
                [
                    'key' => 'revenue',
                    'label' => 'Total revenue',
                    'value' => round($revenue, 2),
                    'money' => true,
                    'change' => $this->change($revenue, array_sum(array_column($marginBefore, 'revenue'))),
                    'info' => 'What sold, before GST',
                    'spark' => $this->spark($days, array_map(fn (array $day) => $day['revenue'], $margin)),
                ],
                [
                    'key' => 'cogs',
                    'label' => 'Cost of goods',
                    'value' => round($cost, 2),
                    'money' => true,
                    'hint' => 'What the stock sold had cost',
                    'spark' => $this->spark($days, array_map(fn (array $day) => $day['cost'], $margin)),
                ],
                [
                    'key' => 'gross_profit',
                    'label' => 'Gross profit',
                    'value' => round($profit, 2),
                    'money' => true,
                    'hint' => $this->percent($profit, $revenue).' margin',
                    'change' => $this->change($profit, array_sum(array_column($marginBefore, 'profit'))),
                    'spark' => $this->spark($days, array_map(fn (array $day) => $day['profit'], $margin)),
                ],
                [
                    'key' => 'profit_per_bill',
                    'label' => 'Avg. profit per bill',
                    'value' => $bills > 0 ? round($profit / $bills, 2) : 0.0,
                    'money' => true,
                    'hint' => $bills.' '.Str::plural('bill', $bills),
                ],
            ],

            'charts' => [
                [
                    'title' => 'Profit trend',
                    'icon' => 'ti ti-chart-line',
                    'subtitle' => 'Revenue, cost and profit by day',
                    'kind' => 'lines',
                    'money' => true,
                    'granular' => true,
                    'points' => $this->points($from, $to),
                    'series' => [
                        ['label' => 'Revenue', 'tone' => 'blue', 'values' => $this->align($days, array_map(fn (array $day) => $day['revenue'], $margin))],
                        ['label' => 'Cost', 'tone' => 'amber', 'values' => $this->align($days, array_map(fn (array $day) => $day['cost'], $margin))],
                        ['label' => 'Profit', 'tone' => 'green', 'values' => $this->align($days, array_map(fn (array $day) => $day['profit'], $margin))],
                    ],
                ],
                [
                    'title' => 'Profit by category',
                    'icon' => 'ti ti-chart-donut',
                    'subtitle' => 'Where the margin came from',
                    'kind' => 'donut',
                    'money' => true,
                    'centre' => 'Gross profit',
                    'rows' => $this->foldTail(
                        $byCategory->map(fn (array $row) => ['label' => $row['category'], 'value' => max($row['profit'], 0)]),
                        5,
                    ),
                ],
            ],

            'tables' => [
                [
                    'title' => 'Category wise profit',
                    'icon' => 'ti ti-category',
                    'subtitle' => 'Sales, cost and margin by category',
                    'link' => 'profit',
                    'columns' => [
                        ['key' => 'category', 'label' => 'Category'],
                        ['key' => 'sales', 'label' => 'Sales', 'format' => 'money'],
                        ['key' => 'cost', 'label' => 'Cost', 'format' => 'money'],
                        ['key' => 'profit', 'label' => 'Profit', 'format' => 'money'],
                        ['key' => 'margin', 'label' => 'Margin', 'format' => 'percent'],
                    ],
                    'rows' => $byCategory->take(self::ROWS)->values()->all(),
                ],
                [
                    'title' => 'Top profitable medicines',
                    'icon' => 'ti ti-trophy',
                    'subtitle' => 'By margin made in this period',
                    'link' => 'profit',
                    'columns' => [
                        ['key' => 'name', 'label' => 'Medicine'],
                        ['key' => 'sales', 'label' => 'Sales', 'format' => 'money'],
                        ['key' => 'profit', 'label' => 'Profit', 'format' => 'money'],
                        ['key' => 'margin', 'label' => 'Margin', 'format' => 'percent'],
                    ],
                    'rows' => $this->topProfitable($store, $from, $to),
                ],
            ],
        ];
    }

    /**
     * Revenue, cost and profit for each day of the window that sold anything.
     *
     * Revenue is the taxable value — GST is the government's, not takings —
     * and every figure is net of what came back, the same rule the profit
     * report itself uses.
     *
     * @return array<string, array{revenue: float, cost: float, profit: float}>
     */
    private function marginByDay(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $kept = '(pharmacy_sale_items.quantity - pharmacy_sale_items.returned_quantity)';

        return $this->lines($store, $from, $to)
            ->selectRaw('pharmacy_sales.sale_date::date AS day')
            ->selectRaw("SUM(pharmacy_sale_items.taxable_amount * {$kept}::numeric / pharmacy_sale_items.quantity) AS revenue")
            ->selectRaw("SUM(COALESCE(pharmacy_sale_items.unit_cost, 0) * {$kept}) AS cost")
            ->groupByRaw('pharmacy_sales.sale_date::date')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->day => [
                'revenue' => round((float) $row->revenue, 2),
                'cost' => round((float) $row->cost, 2),
                'profit' => round((float) $row->revenue - (float) $row->cost, 2),
            ]])
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function profitByCategory(PharmacyStore $store, Carbon $from, Carbon $to): Collection
    {
        $kept = '(pharmacy_sale_items.quantity - pharmacy_sale_items.returned_quantity)';

        return $this->lines($store, $from, $to)
            ->join('medicines', 'medicines.id', '=', 'pharmacy_sale_items.medicine_id')
            ->selectRaw("COALESCE(NULLIF(medicines.category, ''), 'Uncategorised') AS category")
            ->selectRaw("SUM(pharmacy_sale_items.taxable_amount * {$kept}::numeric / pharmacy_sale_items.quantity) AS sales")
            ->selectRaw("SUM(COALESCE(pharmacy_sale_items.unit_cost, 0) * {$kept}) AS cost")
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->category,
                'category' => (string) $row->category,
                'sales' => round((float) $row->sales, 2),
                'cost' => round((float) $row->cost, 2),
                'profit' => round((float) $row->sales - (float) $row->cost, 2),
                'margin' => (float) $row->sales > 0 ? round(((float) $row->sales - (float) $row->cost) / (float) $row->sales * 100, 1) : 0.0,
            ])
            ->sortByDesc('profit')
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private function topProfitable(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        $kept = '(pharmacy_sale_items.quantity - pharmacy_sale_items.returned_quantity)';
        $sales = "SUM(pharmacy_sale_items.taxable_amount * {$kept}::numeric / pharmacy_sale_items.quantity)";
        $cost = "SUM(COALESCE(pharmacy_sale_items.unit_cost, 0) * {$kept})";

        return $this->lines($store, $from, $to)
            ->join('medicines', 'medicines.id', '=', 'pharmacy_sale_items.medicine_id')
            ->selectRaw('pharmacy_sale_items.medicine_id')
            ->selectRaw('MAX(medicines.generic_name) AS generic_name, MAX(medicines.brand_name) AS brand_name, MAX(medicines.strength) AS strength')
            ->selectRaw("{$sales} AS sales, {$cost} AS cost")
            ->groupBy('pharmacy_sale_items.medicine_id')
            ->orderByRaw("{$sales} - {$cost} DESC")
            ->limit(self::ROWS)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->medicine_id,
                'name' => $this->name($row),
                'sales' => round((float) $row->sales, 2),
                'profit' => round((float) $row->sales - (float) $row->cost, 2),
                'margin' => (float) $row->sales > 0 ? round(((float) $row->sales - (float) $row->cost) / (float) $row->sales * 100, 1) : 0.0,
            ])
            ->all();
    }

    /* =====================================================================
     | GST
     |===================================================================== */

    /** @return array<string, mixed> */
    private function gst(PharmacyStore $store, Carbon $from, Carbon $to): array
    {
        [$before, $beforeEnd] = $this->previous($from, $to);

        $kept = '(pharmacy_sale_items.quantity - pharmacy_sale_items.returned_quantity)';
        $taxable = "pharmacy_sale_items.taxable_amount * {$kept}::numeric / pharmacy_sale_items.quantity";
        $tax = "pharmacy_sale_items.tax_amount * {$kept}::numeric / pharmacy_sale_items.quantity";

        $byDay = $this->lines($store, $from, $to)
            ->selectRaw("pharmacy_sales.sale_date::date AS day, SUM({$taxable}) AS taxable, SUM({$tax}) AS tax")
            ->groupByRaw('pharmacy_sales.sale_date::date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $taxableNow = (float) $byDay->sum('taxable');
        $taxNow = (float) $byDay->sum('tax');
        $taxableBefore = (float) $this->lines($store, $before, $beforeEnd)->selectRaw("COALESCE(SUM({$taxable}), 0) AS taxable")->value('taxable');

        $byRate = $this->lines($store, $from, $to)
            ->selectRaw("pharmacy_sale_items.tax_rate AS rate, SUM({$taxable}) AS taxable, SUM({$tax}) AS tax")
            ->groupBy('pharmacy_sale_items.tax_rate')
            ->orderByDesc('taxable')
            ->get();

        $days = $this->days($from, $to);
        $half = fn (float $value) => round($value / 2, 2);

        $summary = $byRate->map(fn ($row) => [
            'id' => (string) $row->rate,
            'rate' => $this->rate((float) $row->rate),
            'taxable' => round((float) $row->taxable, 2),
            'tax' => round((float) $row->tax, 2),
            'total' => round((float) $row->taxable + (float) $row->tax, 2),
        ])->values();

        return [
            'kpis' => [
                [
                    'key' => 'taxable',
                    'label' => 'Total taxable sales',
                    'value' => round($taxableNow, 2),
                    'money' => true,
                    'change' => $this->change($taxableNow, $taxableBefore),
                    'spark' => $this->spark($days, $byDay->map(fn ($row) => (float) $row->taxable)->all()),
                ],
                // Within the state a sale's GST is half central, half state.
                ['key' => 'cgst', 'label' => 'CGST', 'value' => $half($taxNow), 'money' => true, 'hint' => 'Half of the GST collected'],
                ['key' => 'sgst', 'label' => 'SGST', 'value' => $half($taxNow), 'money' => true, 'hint' => 'Half of the GST collected'],
                [
                    'key' => 'total_gst',
                    'label' => 'Total GST',
                    'value' => round($taxNow, 2),
                    'money' => true,
                    'hint' => $this->percent($taxNow, $taxableNow).' of taxable sales',
                    'spark' => $this->spark($days, $byDay->map(fn ($row) => (float) $row->tax)->all()),
                ],
            ],

            'charts' => [
                [
                    'title' => 'GST collection trend',
                    'icon' => 'ti ti-chart-bar',
                    'subtitle' => 'CGST and SGST by day',
                    'kind' => 'stacked',
                    'money' => true,
                    'granular' => true,
                    'points' => $this->points($from, $to),
                    'series' => [
                        ['label' => 'CGST', 'tone' => 'blue', 'values' => array_map(fn (string $day) => $half((float) ($byDay->get($day)?->tax ?? 0)), $days)],
                        ['label' => 'SGST', 'tone' => 'green', 'values' => array_map(fn (string $day) => $half((float) ($byDay->get($day)?->tax ?? 0)), $days)],
                    ],
                ],
                [
                    'title' => 'Sales by GST rate',
                    'icon' => 'ti ti-chart-donut',
                    'subtitle' => 'Taxable value at each rate',
                    'kind' => 'donut',
                    'money' => true,
                    'centre' => 'Taxable sales',
                    'rows' => $summary->map(fn (array $row) => ['label' => $row['rate'], 'value' => $row['taxable']])->all(),
                ],
            ],

            'tables' => [
                [
                    'title' => 'GST summary',
                    'icon' => 'ti ti-file-text',
                    'subtitle' => 'By rate, for the return',
                    'link' => 'gst',
                    'columns' => [
                        ['key' => 'rate', 'label' => 'GST rate'],
                        ['key' => 'taxable', 'label' => 'Taxable amount', 'format' => 'money'],
                        ['key' => 'tax', 'label' => 'GST amount', 'format' => 'money'],
                        ['key' => 'total', 'label' => 'Total amount', 'format' => 'money'],
                    ],
                    'rows' => $summary->all(),
                    'total' => $summary->isEmpty() ? null : [
                        'rate' => 'Total',
                        'taxable' => round($summary->sum('taxable'), 2),
                        'tax' => round($summary->sum('tax'), 2),
                        'total' => round($summary->sum('total'), 2),
                    ],
                ],
                [
                    'title' => 'Recent GST transactions',
                    'icon' => 'ti ti-clock',
                    'subtitle' => 'Latest bills and the tax on them',
                    'link' => 'sales',
                    'columns' => [
                        ['key' => 'date', 'label' => 'Date', 'format' => 'date'],
                        ['key' => 'number', 'label' => 'Invoice no.'],
                        ['key' => 'taxable', 'label' => 'Taxable amount', 'format' => 'money'],
                        ['key' => 'tax', 'label' => 'GST amount', 'format' => 'money'],
                    ],
                    'rows' => $this->bills($store, $from, $to)
                        ->latest('sale_date')
                        ->latest('id')
                        ->limit(self::ROWS)
                        ->get(['id', 'sale_number', 'sale_date', 'subtotal', 'tax_amount'])
                        ->map(fn (PharmacySale $sale) => [
                            'id' => $sale->id,
                            'date' => $sale->sale_date?->toDateString(),
                            'number' => $sale->sale_number,
                            'taxable' => round((float) $sale->subtotal, 2),
                            'tax' => round((float) $sale->tax_amount, 2),
                        ])
                        ->all(),
                ],
            ],
        ];
    }

    /* =====================================================================
     | The queries the dashboards are cut from — the same definitions as
     | PharmacyReports, so a figure here is a figure there.
     |===================================================================== */

    /** Completed bills this store issued in the window. */
    private function bills(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->completed()
            ->whereBetween('sale_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /** Lines from those bills, joined to their bill for its date. */
    private function lines(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return PharmacySaleItem::query()
            ->join('pharmacy_sales', 'pharmacy_sales.id', '=', 'pharmacy_sale_items.pharmacy_sale_id')
            ->where('pharmacy_sales.pharmacy_store_id', $store->id)
            ->where('pharmacy_sales.status', PharmacySale::COMPLETED)
            ->whereBetween('pharmacy_sales.sale_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /** Posted goods received notes in the window. */
    private function inwards(PharmacyStore $store, Carbon $from, Carbon $to): Builder
    {
        return StockInward::query()
            ->where('stock_inwards.pharmacy_store_id', $store->id)
            ->where('stock_inwards.status', StockInward::POSTED)
            ->whereBetween('stock_inwards.received_date', [$from->toDateString(), $to->toDateString()]);
    }

    /** What the counter could hand over today. */
    private function usable(PharmacyStore $store): Builder
    {
        return MedicineBatch::query()
            ->where('medicine_batches.pharmacy_store_id', $store->id)
            ->where('medicine_batches.status', MedicineBatch::ACTIVE)
            ->whereDate('medicine_batches.expiry_date', '>', Carbon::today())
            ->where('medicine_batches.quantity_available', '>', 0);
    }

    /* =====================================================================
     | Time
     |===================================================================== */

    /**
     * The window to compare against: the month before a whole calendar
     * month, otherwise the same number of days just before.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previous(Carbon $from, Carbon $to): array
    {
        if ($from->day === 1 && $to->isSameDay($from->copy()->endOfMonth())) {
            $month = $from->copy()->subMonthNoOverflow();

            return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        }

        $length = count($this->days($from, $to));
        $end = $from->copy()->subDay()->endOfDay();

        return [$end->copy()->subDays($length - 1)->startOfDay(), $end];
    }

    /**
     * A sum per day, keyed Y-m-d.
     *
     * @return array<string, float>
     */
    private function daily(Builder $query, string $column, string $sum): array
    {
        $table = $query->getModel()->getTable();

        return $query
            ->selectRaw("{$table}.{$column}::date AS day, SUM({$sum}) AS total")
            ->groupByRaw("{$table}.{$column}::date")
            ->pluck('total', 'day')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /** @return list<string> every day of the window, Y-m-d */
    private function days(Carbon $from, Carbon $to): array
    {
        $days = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * The chart's axis — a point for every day, including the quiet ones. The
     * screen folds them into weeks or months when asked.
     *
     * @return list<array{date: string, label: string, title: string}>
     */
    private function points(Carbon $from, Carbon $to): array
    {
        return array_map(function (string $day) {
            $date = Carbon::parse($day);

            return ['date' => $day, 'label' => $date->format('j M'), 'title' => $date->format('D, j M Y')];
        }, $this->days($from, $to));
    }

    /**
     * Values in day order, nothing where a day had nothing.
     *
     * @param  list<string>  $days
     * @param  array<string, float>  $values
     * @return list<float>
     */
    private function align(array $days, array $values): array
    {
        return array_map(fn (string $day) => round((float) ($values[$day] ?? 0), 2), $days);
    }

    /**
     * The window cut into SPARK runs of days, each summed — the bars in a
     * figure's card. Empty when there is nothing to draw.
     *
     * @param  list<string>  $days
     * @param  array<string, float>  $values
     * @return list<float>
     */
    private function spark(array $days, array $values): array
    {
        $aligned = $this->align($days, $values);

        if (array_sum(array_map('abs', $aligned)) == 0) {
            return [];
        }

        $size = (int) ceil(count($aligned) / self::SPARK);

        return array_map(
            fn (array $chunk) => round(array_sum($chunk), 2),
            array_chunk($aligned, max($size, 1)),
        );
    }

    /* =====================================================================
     | Small things
     |===================================================================== */

    /**
     * The biggest few, and the rest as one "Others" — a ring of fifteen
     * slivers answers nothing.
     *
     * @param  Collection<int, array{label: string, value: float|int}>  $rows
     * @return list<array{label: string, value: float|int}>
     */
    private function foldTail(Collection $rows, int $keep): array
    {
        $rows = $rows->filter(fn (array $row) => $row['value'] > 0)->values();

        if ($rows->count() <= $keep + 1) {
            return $rows->all();
        }

        return $rows->take($keep)
            ->push(['label' => 'Others', 'value' => $rows->slice($keep)->sum('value'), 'muted' => true])
            ->all();
    }

    /**
     * Null when the period before had nothing to compare with — a rise from
     * zero has no percentage, and printing one is a number somebody repeats.
     */
    private function change(float|int $now, float|int $before): ?float
    {
        return $before != 0 ? round(($now - $before) / abs($before) * 100, 1) : null;
    }

    /** "24.1%" of a whole, or "0%" when there is no whole. */
    private function percent(float $part, float $whole): string
    {
        return ($whole > 0 ? round($part / $whole * 100, 1) : 0).'%';
    }

    /** "₹12,48,500" — grouped the Indian way, as every other figure on screen. */
    private function rupees(float $amount): string
    {
        $digits = (string) (int) round(max($amount, 0));

        if (strlen($digits) > 3) {
            $digits = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($digits, 0, -3)).','.substr($digits, -3);
        }

        return '₹'.$digits;
    }

    /** "12%", "5%", "0%". */
    private function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';
    }

    /** "Dolo 650 (Paracetamol) 650 mg", from a grouped row. */
    private function name(object $row): string
    {
        $name = $row->brand_name ? "{$row->brand_name} ({$row->generic_name})" : (string) $row->generic_name;

        return trim($name.' '.(string) ($row->strength ?? ''));
    }

    /** "10 Tablets", from what a pack holds. */
    private function pack(mixed $size, ?string $form): string
    {
        if (! $size) {
            return '—';
        }

        return $size.' '.Str::plural(Str::ucfirst((string) ($form ?: 'unit')), (int) $size);
    }
}
