<?php

namespace App\Services\Billing;

use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\Location;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySaleReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The organisation's money across every department, in one place.
 *
 * A REPORTING LAYER, NOT A TILL. Each department keeps its own counter — the
 * OPD and the lab draw invoices, the pharmacy rings up sales — and this reads
 * both registers side by side. Nothing here writes.
 *
 * FIVE DIFFERENT NUMBERS, never one standing in for another:
 *
 *   billed        what bills were raised for, by the date they were raised
 *                 (drafts and cancelled bills raised nothing)
 *   credit notes  goods taken back at the pharmacy, by the date they came back
 *   collected     money taken, by the moment it was taken
 *   refunded      money handed back, by the moment it went back
 *   outstanding   what is owed right now, on every bill still open
 *
 * and net collections = collected − refunded.
 *
 * COUNTED ONCE. Money is read from PAYMENT ROWS only — `invoice_payments` and
 * `pharmacy_sale_payments` — never from a bill's paid column, so a payment is
 * counted exactly once, in the window it actually happened in. The two
 * registers do not overlap: an invoice never carries a pharmacy sale's money
 * (each department bills for itself), and the one legacy case where one did —
 * a sale marked `billed_via_invoice` by the old visit billing — is left out of
 * the pharmacy's figures, because its value and its money are on the invoice.
 *
 * A pharmacy sale cancelled before cancellations recorded their refund has
 * payment rows and no refund rows; its money did go back. Its payments are
 * left out rather than counted as money still held.
 *
 * HISTORY STAYS READABLE. Nothing here asks whether a module is still
 * switched on: a lab that has been switched off still has last year's bills.
 * Who may read this, and which branches, is the caller's question.
 */
class FinanceReports
{
    public const MODULES = [
        'opd' => 'OPD',
        'laboratory' => 'Laboratory',
        'pharmacy' => 'Pharmacy',
    ];

    /** A window longer than this is charted by month rather than by day. */
    private const DAILY_LIMIT = 62;

    /**
     * @param  list<int>|null  $branches  null means every branch
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to, ?array $branches): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $modules = array_fill_keys(array_keys(self::MODULES), $this->blank());
        $byBranch = [];

        $add = function (string $module, ?int $branch, string $figure, float $amount, int $count = 0) use (&$modules, &$byBranch): void {
            $modules[$module][$figure] += $amount;

            if ($figure === 'billed') {
                $modules[$module]['documents'] += $count;
            }

            if ($branch !== null) {
                $byBranch[$branch] ??= $this->blank();
                $byBranch[$branch][$figure] += $amount;

                if ($figure === 'billed') {
                    $byBranch[$branch]['documents'] += $count;
                }
            }
        };

        // Billed — invoices raised in the window, by department.
        foreach ($this->raisedInvoices($from, $to, $branches)
            ->selectRaw('kind, location_id, COUNT(*) AS documents, COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('kind', 'location_id')
            ->get() as $row) {
            $add($this->moduleOf($row->kind), (int) $row->location_id, 'billed', (float) $row->amount, (int) $row->documents);
        }

        // Billed — pharmacy sales made in the window.
        foreach ($this->pharmacySales($branches)
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw('location_id, COUNT(*) AS documents, COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('location_id')
            ->get() as $row) {
            $add('pharmacy', (int) $row->location_id, 'billed', (float) $row->amount, (int) $row->documents);
        }

        // Credit notes — goods taken back in the window.
        foreach ($this->scoped(PharmacySaleReturn::query(), $branches)
            ->whereBetween('return_date', [$from, $to])
            ->selectRaw('location_id, COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('location_id')
            ->get() as $row) {
            $add('pharmacy', (int) $row->location_id, 'credit_notes', (float) $row->amount);
        }

        // Collected and refunded — money that moved in the window, once each.
        foreach ($this->invoiceMoney($from, $to, $branches)
            ->selectRaw('invoices.kind AS kind, invoices.location_id AS location_id, invoice_payments.is_refund AS is_refund')
            ->selectRaw('COALESCE(SUM(invoice_payments.amount), 0) AS amount')
            ->groupBy('invoices.kind', 'invoices.location_id', 'invoice_payments.is_refund')
            ->get() as $row) {
            $add($this->moduleOf($row->kind), (int) $row->location_id, $row->is_refund ? 'refunded' : 'collected', (float) $row->amount);
        }

        foreach ($this->pharmacyMoney($from, $to, $branches)
            ->selectRaw('pharmacy_sales.location_id AS location_id, pharmacy_sale_payments.is_refund AS is_refund')
            ->selectRaw('COALESCE(SUM(pharmacy_sale_payments.amount), 0) AS amount')
            ->groupBy('pharmacy_sales.location_id', 'pharmacy_sale_payments.is_refund')
            ->get() as $row) {
            $add('pharmacy', (int) $row->location_id, $row->is_refund ? 'refunded' : 'collected', (float) $row->amount);
        }

        // Outstanding — every bill still open, whenever it was raised.
        foreach ($this->scoped(Invoice::query(), $branches)
            ->outstanding()
            ->selectRaw('kind, location_id, COALESCE(SUM('.Invoice::OWED_SQL.'), 0) AS amount')
            ->groupBy('kind', 'location_id')
            ->get() as $row) {
            $add($this->moduleOf($row->kind), (int) $row->location_id, 'outstanding', (float) $row->amount);
        }

        foreach ($this->pharmacySales($branches)
            ->selectRaw('location_id, COALESCE(SUM('.PharmacySale::DUE_SQL.'), 0) AS amount')
            ->groupBy('location_id')
            ->get() as $row) {
            $add('pharmacy', (int) $row->location_id, 'outstanding', (float) $row->amount);
        }

        $totals = $this->blank();

        foreach ($modules as $figures) {
            foreach ($figures as $figure => $value) {
                $totals[$figure] += $value;
            }
        }

        $names = Location::query()
            ->withTrashed()
            ->whereIn('id', array_keys($byBranch))
            ->pluck('name', 'id');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->finish($totals),
            'modules' => array_map(
                fn (string $key) => ['key' => $key, 'label' => self::MODULES[$key], ...$this->finish($modules[$key])],
                array_keys(self::MODULES),
            ),
            'branches' => collect($byBranch)
                ->map(fn (array $figures, int $id) => [
                    'location_id' => $id,
                    'name' => (string) ($names[$id] ?? "Branch #{$id}"),
                    ...$this->finish($figures),
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'methods' => $this->methods($from, $to, $branches),
            ...$this->series($from, $to, $branches),
        ];
    }

    /**
     * Every bill, from every department, as one list — invoices and pharmacy
     * sales side by side, newest first.
     *
     * @param  list<int>|null  $branches
     * @param  array{module?: string|null, location_id?: int|null, from?: Carbon|null, to?: Carbon|null, outstanding?: bool, search?: string|null}  $filters
     */
    public function documents(?array $branches, array $filters, int $perPage): LengthAwarePaginator
    {
        $invoices = $this->scoped(Invoice::query(), $branches)
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereNot('invoices.status', Invoice::STATUS_DRAFT)
            ->toBase()
            ->selectRaw("'invoice' AS source, invoices.id AS id, invoices.invoice_number AS number")
            ->selectRaw('invoices.invoice_date AS date, invoices.location_id AS location_id')
            ->selectRaw("CASE invoices.kind WHEN 'lab' THEN 'laboratory' ELSE 'opd' END AS module")
            ->selectRaw("COALESCE(customers.name, invoices.walk_in_name, 'Walk-in') AS party")
            ->selectRaw('invoices.total_amount AS total, invoices.paid_amount AS paid')
            ->selectRaw('invoices.refunded_amount AS credited')
            ->selectRaw("CASE WHEN invoices.status IN ('pending', 'partially_paid') THEN GREATEST(invoices.total_amount - invoices.paid_amount - invoices.refunded_amount, 0) ELSE 0 END AS outstanding")
            ->selectRaw('invoices.status AS status');

        $sales = $this->scoped(PharmacySale::query(), $branches)
            ->leftJoin('customers', 'customers.id', '=', 'pharmacy_sales.customer_id')
            ->toBase()
            ->selectRaw("'pharmacy_sale' AS source, pharmacy_sales.id AS id, pharmacy_sales.sale_number AS number")
            ->selectRaw('pharmacy_sales.sale_date AS date, pharmacy_sales.location_id AS location_id')
            ->selectRaw("'pharmacy' AS module")
            ->selectRaw("COALESCE(customers.name, pharmacy_sales.walk_in_name, 'Walk-in') AS party")
            ->selectRaw('pharmacy_sales.total_amount AS total, pharmacy_sales.paid_amount AS paid')
            ->selectRaw('pharmacy_sales.returned_amount AS credited')
            // The sale's own columns — `customers` carries none of these names.
            ->selectRaw("CASE WHEN pharmacy_sales.status = 'completed' THEN ".PharmacySale::DUE_SQL.' ELSE 0 END AS outstanding')
            ->selectRaw("CASE pharmacy_sales.status WHEN 'cancelled' THEN 'cancelled' ELSE pharmacy_sales.payment_status END AS status");

        $all = $invoices->unionAll($sales);

        return (new Invoice)->getConnection()->query()
            ->fromSub($all, 'documents')
            ->when(! empty($filters['module']), fn (QueryBuilder $q) => $q->where('module', $filters['module']))
            ->when(! empty($filters['location_id']), fn (QueryBuilder $q) => $q->where('location_id', $filters['location_id']))
            ->when(! empty($filters['from']), fn (QueryBuilder $q) => $q->where('date', '>=', $filters['from']->copy()->startOfDay()))
            ->when(! empty($filters['to']), fn (QueryBuilder $q) => $q->where('date', '<=', $filters['to']->copy()->endOfDay()))
            ->when(! empty($filters['outstanding']), fn (QueryBuilder $q) => $q->where('outstanding', '>', 0))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (QueryBuilder $q) use ($filters) {
                $pattern = '%'.trim((string) $filters['search']).'%';

                $q->where(fn (QueryBuilder $any) => $any->where('number', 'ILIKE', $pattern)->orWhere('party', 'ILIKE', $pattern));
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (object $row) => [
                'source' => $row->source,
                'id' => (int) $row->id,
                'number' => (string) $row->number,
                'date' => Carbon::parse($row->date)->toIso8601String(),
                'location_id' => (int) $row->location_id,
                'module' => $row->module,
                'party' => (string) $row->party,
                'total' => round((float) $row->total, 2),
                'paid' => round((float) $row->paid, 2),
                'credited' => round((float) $row->credited, 2),
                'outstanding' => round((float) $row->outstanding, 2),
                'status' => (string) $row->status,
            ]);
    }

    /* ---------------------------------------------------------------- parts */

    /**
     * What each payment method took and gave back, across both registers.
     *
     * @param  list<int>|null  $branches
     * @return list<array{method: string, collected: float, refunded: float, net: float}>
     */
    private function methods(Carbon $from, Carbon $to, ?array $branches): array
    {
        $methods = [];

        $rows = $this->invoiceMoney($from, $to, $branches)
            ->selectRaw('invoice_payments.method AS method, invoice_payments.is_refund AS is_refund, COALESCE(SUM(invoice_payments.amount), 0) AS amount')
            ->groupBy('invoice_payments.method', 'invoice_payments.is_refund')
            ->get()
            ->concat($this->pharmacyMoney($from, $to, $branches)
                ->selectRaw('pharmacy_sale_payments.method AS method, pharmacy_sale_payments.is_refund AS is_refund, COALESCE(SUM(pharmacy_sale_payments.amount), 0) AS amount')
                ->groupBy('pharmacy_sale_payments.method', 'pharmacy_sale_payments.is_refund')
                ->get());

        foreach ($rows as $row) {
            $methods[$row->method] ??= ['method' => (string) $row->method, 'collected' => 0.0, 'refunded' => 0.0];
            $methods[$row->method][$row->is_refund ? 'refunded' : 'collected'] += (float) $row->amount;
        }

        return collect($methods)
            ->map(fn (array $m) => [
                ...$m,
                'collected' => round($m['collected'], 2),
                'refunded' => round($m['refunded'], 2),
                'net' => round($m['collected'] - $m['refunded'], 2),
            ])
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    /**
     * Billed and collected over the window — by day, or by month for a long one.
     *
     * @param  list<int>|null  $branches
     * @return array{granularity: string, series: list<array<string, mixed>>}
     */
    private function series(Carbon $from, Carbon $to, ?array $branches): array
    {
        $daily = $from->diffInDays($to) < self::DAILY_LIMIT;
        $format = $daily ? 'YYYY-MM-DD' : 'YYYY-MM';

        $buckets = [];
        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $daily ? $cursor->toDateString() : $cursor->format('Y-m');

            $buckets[$key] ??= [
                'key' => $key,
                'label' => $daily ? $cursor->format('d M') : $cursor->format('M Y'),
                'billed' => 0.0,
                'collected' => 0.0,
                'refunded' => 0.0,
            ];

            $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow()->startOfMonth();
        }

        $put = function (iterable $rows, string $figure) use (&$buckets): void {
            foreach ($rows as $row) {
                if (isset($buckets[$row->bucket])) {
                    $buckets[$row->bucket][$figure] += (float) $row->amount;
                }
            }
        };

        $put($this->raisedInvoices($from, $to, $branches)
            ->selectRaw("to_char(invoice_date, '{$format}') AS bucket, SUM(total_amount) AS amount")
            ->groupBy('bucket')->get(), 'billed');

        $put($this->pharmacySales($branches)
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw("to_char(sale_date, '{$format}') AS bucket, SUM(total_amount) AS amount")
            ->groupBy('bucket')->get(), 'billed');

        foreach ([[false, 'collected'], [true, 'refunded']] as [$refund, $figure]) {
            $put($this->invoiceMoney($from, $to, $branches)
                ->where('invoice_payments.is_refund', $refund)
                ->selectRaw("to_char(invoice_payments.paid_at, '{$format}') AS bucket, SUM(invoice_payments.amount) AS amount")
                ->groupBy('bucket')->get(), $figure);

            $put($this->pharmacyMoney($from, $to, $branches)
                ->where('pharmacy_sale_payments.is_refund', $refund)
                ->selectRaw("to_char(pharmacy_sale_payments.paid_at, '{$format}') AS bucket, SUM(pharmacy_sale_payments.amount) AS amount")
                ->groupBy('bucket')->get(), $figure);
        }

        return [
            'granularity' => $daily ? 'day' : 'month',
            'series' => array_values(array_map(fn (array $bucket) => [
                ...$bucket,
                'billed' => round($bucket['billed'], 2),
                'collected' => round($bucket['collected'], 2),
                'refunded' => round($bucket['refunded'], 2),
                'net' => round($bucket['collected'] - $bucket['refunded'], 2),
            ], $buckets)),
        ];
    }

    /** Invoices that were actually raised in the window — no drafts, nothing cancelled. */
    private function raisedInvoices(Carbon $from, Carbon $to, ?array $branches): Builder
    {
        return $this->scoped(Invoice::query(), $branches)
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED])
            ->whereBetween('invoice_date', [$from, $to]);
    }

    /**
     * The pharmacy's completed sales whose value is the pharmacy's own —
     * not a legacy sale whose money a visit invoice took over.
     */
    private function pharmacySales(?array $branches): Builder
    {
        return $this->scoped(PharmacySale::query(), $branches)
            ->where('status', PharmacySale::COMPLETED)
            ->where('payment_status', '!=', PharmacySale::BILLED_VIA_INVOICE);
    }

    /** Invoice payment rows that moved in the window, joined to their bill. */
    private function invoiceMoney(Carbon $from, Carbon $to, ?array $branches): Builder
    {
        return InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('invoices.location_id', $branches ?: [0]))
            ->whereBetween('invoice_payments.paid_at', [$from, $to]);
    }

    /**
     * Pharmacy payment rows that moved in the window, joined to their bill —
     * leaving out a bill cancelled before refunds were recorded, whose money
     * went back without a row to say so.
     */
    private function pharmacyMoney(Carbon $from, Carbon $to, ?array $branches): Builder
    {
        return PharmacySalePayment::query()
            ->join('pharmacy_sales', 'pharmacy_sales.id', '=', 'pharmacy_sale_payments.pharmacy_sale_id')
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('pharmacy_sales.location_id', $branches ?: [0]))
            ->whereBetween('pharmacy_sale_payments.paid_at', [$from, $to])
            ->where(fn (Builder $q) => $q
                ->where('pharmacy_sales.status', PharmacySale::COMPLETED)
                ->orWhereExists(fn (QueryBuilder $refund) => $refund
                    ->selectRaw('1')
                    ->from('pharmacy_sale_payments AS back')
                    ->whereColumn('back.pharmacy_sale_id', 'pharmacy_sales.id')
                    ->where('back.is_refund', true)));
    }

    /** @param  list<int>|null  $branches */
    private function scoped(Builder $query, ?array $branches): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->when($branches !== null, fn (Builder $q) => $q->whereIn("{$table}.location_id", $branches ?: [0]));
    }

    private function moduleOf(?string $kind): string
    {
        return Invoice::KIND_MODULE[$kind] ?? 'opd';
    }

    /** @return array<string, float|int> */
    private function blank(): array
    {
        return [
            'billed' => 0.0,
            'documents' => 0,
            'credit_notes' => 0.0,
            'collected' => 0.0,
            'refunded' => 0.0,
            'outstanding' => 0.0,
        ];
    }

    /**
     * @param  array<string, float|int>  $figures
     * @return array<string, float|int>
     */
    private function finish(array $figures): array
    {
        return [
            'billed' => round((float) $figures['billed'], 2),
            'documents' => (int) $figures['documents'],
            'credit_notes' => round((float) $figures['credit_notes'], 2),
            'collected' => round((float) $figures['collected'], 2),
            'refunded' => round((float) $figures['refunded'], 2),
            'net_collected' => round((float) $figures['collected'] - (float) $figures['refunded'], 2),
            'outstanding' => round((float) $figures['outstanding'], 2),
        ];
    }
}
