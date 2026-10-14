<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Services\Pharmacy\PharmacyReports;
use App\Services\Pharmacy\Reports\ReportDashboards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The pharmacy's reports, read either way.
 *
 * `summary` is the shape of a report — headline figures and a chart — and
 * `rows` is the evidence behind it, a page at a time. Both take the same
 * filters and are cut from the same query, so a figure on one is the sum of
 * the other; a summary nobody can drill into is a number taken on trust.
 *
 * Behind `pharmacy.view`, and asked about the STORE's branch rather than the
 * acting one: a report is about a counter, wherever the reader is sitting.
 *
 * @group Tenant > Pharmacy > Stores & Sales
 */
class PharmacyReportController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    /** How far back a report looks when nobody says. */
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly PharmacyReports $reports,
        private readonly ReportDashboards $dashboards,
    ) {}

    public function summary(Request $request, PharmacyStore $store, string $report): JsonResponse
    {
        $this->authorizeTenant('viewReport', [$store, $report]);

        [$from, $to] = $this->window($request);

        return $this->ok([
            'report' => $report,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // Reports about the shelf ignore the dates; the screen hides the
            // range for those rather than showing one that changes nothing.
            'dated' => in_array($report, PharmacyReports::DATED, true),
            ...$this->reports->summary($report, $store, $from, $to),

            /*
             * The reports screen's own dashboard — figures, two charts, two
             * lists. Only when asked: the sales and goods-received lists read
             * this same summary for their headline figures, and computing a
             * dozen more queries for a page that draws none of them is waste.
             */
            ...($request->boolean('dashboard')
                ? ['dashboard' => $this->dashboards->for($report, $store, $from, $to)]
                : []),
        ]);
    }

    public function rows(Request $request, PharmacyStore $store, string $report): JsonResponse
    {
        $this->authorizeTenant('viewReport', [$store, $report]);

        [$from, $to] = $this->window($request);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([PharmacySale::PAID, PharmacySale::PARTIAL, PharmacySale::UNPAID])],
            'method' => ['nullable', 'string', 'max:30'],
            'supplier_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(StockInward::TYPES)],
            'category' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', Rule::in(['low', 'ok'])],
            'within' => ['nullable', Rule::in(['expired', '30', '60', '90'])],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort' => ['nullable', Rule::in(PharmacyReports::SORTS[$report] ?? [])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        // Only this report's own filters reach its query; another report's
        // left in the address bar is ignored, not applied somewhere it means
        // something else.
        $filters = array_filter(
            array_intersect_key($filters, array_flip([...(PharmacyReports::FILTERS[$report] ?? []), 'sort', 'direction'])),
            fn ($value) => $value !== null && $value !== '',
        );

        $page = $this->reports->rows(
            $report,
            $store,
            $from,
            $to,
            $this->resolvePerPage($request),
            trim((string) $request->query('search', '')),
            $filters,
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $this->row($report, $row))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // What each of this table's filters can be set to.
                // An object even when empty, so a reader can always key into it.
                'filters' => (object) $this->reports->filterOptions($report, $store, $from, $to),
            ],
        ]);
    }

    /**
     * One row, in the shape its table reads.
     *
     * Written here rather than in six Resource classes: each of these is a
     * handful of columns that exist only for one table on one screen, and a
     * class per shape would be six files nobody else ever calls.
     *
     * @return array<string, mixed>
     */
    private function row(string $report, mixed $row): array
    {
        return match ($report) {
            PharmacyReports::SALES => [
                'id' => $row->id,
                'date' => $row->sale_date?->toIso8601String(),
                'number' => $row->sale_number,
                'customer' => $row->buyerName(),
                'lines' => (int) ($row->items_count ?? 0),
                'taxable' => (float) $row->subtotal,
                'tax' => (float) $row->tax_amount,
                'total' => (float) $row->total_amount,
                'paid' => (float) $row->paid_amount,
                'due' => $row->amountDue(),
                'tender' => $this->tender($row),
                'status' => (string) $row->payment_status,
                'phone' => $row->customer?->phone ?? $row->walk_in_phone,
            ],

            PharmacyReports::PURCHASES => [
                'id' => $row->id,
                'date' => $row->received_date?->toDateString(),
                'number' => $row->inward_number,
                'supplier' => $row->supplier?->name ?? '—',
                'invoice' => $row->supplier_invoice_no,
                'lines' => (int) ($row->items_count ?? 0),
                'total' => (float) $row->total_amount,
                'type' => (string) $row->inward_type,
            ],

            PharmacyReports::STOCK => [
                'id' => (int) $row->medicine_id,
                'item' => $this->itemName($row),
                'category' => $row->category,
                'unit' => $row->base_unit,
                'batches' => (int) $row->batches,
                'units' => (int) $row->units,
                'at_cost' => (float) $row->at_cost,
                'at_mrp' => (float) $row->at_mrp,
                'reorder_level' => (int) ($row->reorder_level ?? 0),
                // At or under the store's own reorder level is low; a store
                // that set none has nothing to be under.
                'status' => (int) ($row->reorder_level ?? 0) > 0 && (int) $row->units <= (int) $row->reorder_level
                    ? 'low'
                    : 'in_stock',
            ],

            PharmacyReports::EXPIRY => [
                'id' => $row->id,
                'item' => $row->medicine?->displayName() ?? 'Removed item',
                'batch' => $row->batch_number,
                'expiry' => $row->expiry_date?->toDateString(),
                'days_left' => (int) Carbon::today()->diffInDays($row->expiry_date, false),
                'units' => (int) $row->quantity_available,
                'at_cost' => round((float) $row->purchase_price * (int) $row->quantity_available, 2),
            ],

            PharmacyReports::PROFIT => [
                'id' => (int) $row->medicine_id,
                'item' => $this->itemName($row),
                'category' => $row->category,
                'units' => (int) $row->units,
                'revenue' => (float) $row->revenue,
                'cost' => (float) $row->cost,
                'margin' => (float) $row->margin,
                'margin_percent' => (float) $row->revenue > 0
                    ? round((float) $row->margin / (float) $row->revenue * 100, 1)
                    : 0.0,
            ],

            PharmacyReports::GST => [
                'id' => $row->hsn.'-'.$row->tax_rate,
                'hsn' => $row->hsn,
                'rate' => (float) $row->tax_rate,
                'units' => (int) $row->units,
                'taxable' => (float) $row->taxable,
                'tax' => (float) $row->tax,
                // Half central, half state, on a sale within the state.
                'cgst' => round((float) $row->tax / 2, 2),
                'sgst' => round((float) $row->tax / 2, 2),
            ],
        };
    }

    /** "Dolo 650 (Paracetamol) 650 mg", from a grouped row rather than a model. */
    private function itemName(mixed $row): string
    {
        $name = $row->brand_name
            ? "{$row->brand_name} ({$row->generic_name})"
            : (string) $row->generic_name;

        return trim($name.' '.(string) ($row->strength ?? ''));
    }

    /** What a bill was settled with: the one tender, several, or nothing yet. */
    private function tender(PharmacySale $sale): string
    {
        $methods = $sale->payments->pluck('method')->unique();

        return match (true) {
            $methods->count() === 1 => (string) $methods->first(),
            $methods->count() > 1 => 'split',
            default => 'credit',
        };
    }

    /**
     * The window a dated report covers.
     *
     * A month back by default — long enough for a pattern, short enough to
     * answer quickly on a counter's own machine.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        $to = $request->date('to') ?? Carbon::today();
        $from = $request->date('from') ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1);

        // Given back to front, a range is still a range.
        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
