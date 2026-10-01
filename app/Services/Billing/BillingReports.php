<?php

namespace App\Services\Billing;

use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the billing counter's overview screen reads.
 *
 * Figures only — no formatting, no labels. The screen decides how money is
 * written and what a card is called; this decides what is true.
 *
 * DRAFTS ARE EXCLUDED FROM EVERY FIGURE. A draft is a visit's running tab,
 * not a bill anybody has been asked to pay, and counting one as "invoiced"
 * would report income the clinic has not claimed. They are reported
 * separately, as open visits, because knowing how many are mid-flight is its
 * own question.
 *
 * CANCELLED IS EXCLUDED FROM MONEY, COUNTED IN STATUS. A cancelled bill owes
 * nothing and earned nothing, so it must not move the totals — but "how many
 * did we cancel" is exactly what somebody reading the status breakdown wants.
 */
class BillingReports
{
    /**
     * @param  list<int>|null  $branches  null means every branch
     * @return array<string, mixed>
     */
    public function overview(Carbon $from, Carbon $to, ?array $branches, ?int $branchId = null): array
    {
        $scope = fn (Builder $query) => $query
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('location_id', $branchId));

        /* Bills that were actually raised — drafts and cancellations are not. */
        $raised = fn () => $scope(Invoice::query())
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->whereNot('status', Invoice::STATUS_CANCELLED)
            ->whereBetween('invoice_date', [$from, $to]);

        $invoiced = (float) $raised()->sum('total_amount');
        $paid = (float) $raised()->sum('paid_amount');
        $invoiceCount = (int) $raised()->count();
        $paidCount = (int) $raised()->where('payment_status', Invoice::PAID)->count();

        /*
         * Outstanding is asked of EVERY open bill, not only those raised in
         * the window. Money owed from last week is still owed today, and a
         * counter reading "outstanding" wants the debt, not the debt that
         * happens to have been incurred since Monday.
         */
        $outstanding = (float) $scope(Invoice::query())
            ->outstanding()
            ->sum(DB::raw('total_amount - paid_amount'));
        $outstandingCount = (int) $scope(Invoice::query())->outstanding()->count();
        $outstandingPatients = (int) $scope(Invoice::query())
            ->outstanding()
            ->distinct()
            ->count(DB::raw('COALESCE(customer_id::text, walk_in_phone)'));

        /* Distinct people billed in the window — a clinic reading "invoices"
           already knows how many bills went out; this is how many DIFFERENT
           patients that was. */
        $billedPatients = (int) $raised()
            ->distinct()
            ->count(DB::raw('COALESCE(customer_id::text, walk_in_phone)'));

        $today = $scope(Invoice::query())
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->whereNot('status', Invoice::STATUS_CANCELLED)
            ->whereDate('invoice_date', Carbon::today());

        return [
            'totals' => [
                'invoiced' => round($invoiced, 2),
                'invoiced_count' => $invoiceCount,
                'paid' => round($paid, 2),
                'paid_count' => $paidCount,
                'outstanding' => round($outstanding, 2),
                'outstanding_count' => $outstandingCount,
                'outstanding_patients' => $outstandingPatients,
                'billed_patients' => $billedPatients,
                'today_count' => (int) $today->count(),
                'today_amount' => round((float) $today->sum('total_amount'), 2),
            ],

            'previous' => $this->previousWindow($from, $to, $branches, $branchId),
            'trend' => $this->trend($from, $to, $branches, $branchId),
            'statuses' => $this->statuses($from, $to, $branches, $branchId),

            'categories' => $this->categories($from, $to, $branches, $branchId),
            'methods' => $this->methods($from, $to, $branches, $branchId),
            'ageing' => $this->ageing($branches, $branchId),
            'outstanding_patients_list' => $this->outstandingPatients($branches, $branchId),

            /* Visits still collecting charges — not money, but the other half
               of what the desk is watching. */
            'open_visits' => (int) $scope(Invoice::query())
                ->where('status', Invoice::STATUS_DRAFT)
                ->count(),
        ];
    }

    /**
     * Where the money came from — consultation, pharmacy, lab, everything else.
     *
     * Grouped by the LINE's source rather than the invoice's, because one
     * consolidated bill carries all four: reporting it under a single heading
     * would tell a clinic its revenue was "invoices", which it already knew.
     *
     * @param  list<int>|null  $branches
     * @return list<array{key: string, label: string, amount: float, invoices: int}>
     */
    private function categories(Carbon $from, Carbon $to, ?array $branches, ?int $branchId): array
    {
        $rows = InvoiceItem::query()
            ->whereHas('invoice', fn (Builder $q) => $q
                ->when($branches !== null, fn (Builder $i) => $i->whereIn('location_id', $branches))
                ->when($branchId !== null, fn (Builder $i) => $i->where('location_id', $branchId))
                ->whereNot('status', Invoice::STATUS_DRAFT)
                ->whereNot('status', Invoice::STATUS_CANCELLED)
                ->whereBetween('invoice_date', [$from, $to]))
            ->selectRaw('source_type')
            ->selectRaw('SUM(line_total) AS amount')
            ->selectRaw('COUNT(DISTINCT invoice_id) AS invoices')
            ->groupBy('source_type')
            ->get();

        /*
         * Five headings, not six. `service` and `custom` are one bucket on
         * this chart — a clinic reading its revenue split wants to know how
         * much came from the doctor, the shelf, the bench or the procedure
         * room, and splitting what is left over two ways buries that.
         */
        $buckets = [
            'consultation' => ['label' => 'OPD', 'amount' => 0.0, 'invoices' => 0],
            'pharmacy' => ['label' => 'Pharmacy', 'amount' => 0.0, 'invoices' => 0],
            'laboratory' => ['label' => 'Laboratory', 'amount' => 0.0, 'invoices' => 0],
            'procedure' => ['label' => 'Procedures', 'amount' => 0.0, 'invoices' => 0],
            'other' => ['label' => 'Others', 'amount' => 0.0, 'invoices' => 0],
        ];

        foreach ($rows as $row) {
            $key = match ($row->source_type) {
                InvoiceItem::SOURCE_CONSULTATION => 'consultation',
                InvoiceItem::SOURCE_PHARMACY_SALE_ITEM => 'pharmacy',
                InvoiceItem::SOURCE_LAB_TEST => 'laboratory',
                InvoiceItem::SOURCE_PROCEDURE => 'procedure',
                default => 'other',
            };

            $buckets[$key]['amount'] += (float) $row->amount;
            /*
             * Summed rather than max'd: an invoice carrying both a
             * consultation and a procedure counts once in each bucket, which
             * is the honest answer to "how many bills included pharmacy".
             */
            $buckets[$key]['invoices'] += (int) $row->invoices;
        }

        return array_values(array_map(
            fn (string $key) => [
                'key' => $key,
                'label' => $buckets[$key]['label'],
                'amount' => round($buckets[$key]['amount'], 2),
                'invoices' => $buckets[$key]['invoices'],
            ],
            array_keys($buckets),
        ));
    }

    /**
     * What each tender took, with its share — the payment-methods panel.
     *
     * @param  list<int>|null  $branches
     * @return list<array{method: string, amount: float, share: float}>
     */
    private function methods(Carbon $from, Carbon $to, ?array $branches, ?int $branchId): array
    {
        $rows = InvoicePayment::query()
            ->whereHas('invoice', fn (Builder $q) => $q
                ->when($branches !== null, fn (Builder $i) => $i->whereIn('location_id', $branches))
                ->when($branchId !== null, fn (Builder $i) => $i->where('location_id', $branchId)))
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('method')
            ->selectRaw('SUM(CASE WHEN is_refund THEN -amount ELSE amount END) AS net')
            ->groupBy('method')
            ->get();

        $total = $rows->sum(fn ($row) => max(0.0, (float) $row->net));

        return $rows
            /* A method whose refunds cancelled its takings contributed
               nothing; printing it at 0% is noise on a five-row panel. */
            ->filter(fn ($row) => (float) $row->net > 0)
            ->sortByDesc('net')
            ->map(fn ($row) => [
                'method' => (string) $row->method,
                'amount' => round((float) $row->net, 2),
                'share' => $total > 0 ? round(((float) $row->net / $total) * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * How long the outstanding money has been outstanding.
     *
     * The buckets a desk actually chases in: this week, this month, stale,
     * and the ones somebody senior needs to look at. Asked of every open
     * bill regardless of window — a debt from March is still a debt today,
     * and ageing it against a seven-day window would report none of it.
     *
     * @param  list<int>|null  $branches
     * @return list<array{key: string, label: string, amount: float, invoices: int}>
     */
    private function ageing(?array $branches, ?int $branchId): array
    {
        $buckets = [
            ['key' => 'fresh', 'label' => '0–7 days', 'min' => 0, 'max' => 7],
            ['key' => 'recent', 'label' => '8–30 days', 'min' => 8, 'max' => 30],
            ['key' => 'stale', 'label' => '31–60 days', 'min' => 31, 'max' => 60],
            ['key' => 'old', 'label' => '60+ days', 'min' => 61, 'max' => null],
        ];

        $out = [];

        foreach ($buckets as $bucket) {
            $query = Invoice::query()
                ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
                ->when($branchId !== null, fn (Builder $q) => $q->where('location_id', $branchId))
                ->outstanding()
                ->whereDate('invoice_date', '<=', Carbon::today()->subDays($bucket['min']));

            if ($bucket['max'] !== null) {
                $query->whereDate('invoice_date', '>=', Carbon::today()->subDays($bucket['max']));
            }

            $out[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'amount' => round((float) (clone $query)->sum(DB::raw('total_amount - paid_amount')), 2),
                'invoices' => (int) (clone $query)->count(),
            ];
        }

        return $out;
    }

    /**
     * Who owes money, most recently seen first — the outstanding-patients
     * table. Grouped by patient rather than by bill, because a desk chasing
     * a debt calls the PERSON once, not once per invoice they left unpaid.
     *
     * Not windowed, for the same reason `ageing()` is not: money owed from
     * last month is still owed today.
     *
     * @param  list<int>|null  $branches
     * @return list<array{patient: string, last_visit: string, total: float, paid: float, due: float, days: int}>
     */
    private function outstandingPatients(?array $branches, ?int $branchId): array
    {
        return $this->outstandingQuery($branches, $branchId)
            ->limit(8)
            ->get()
            ->map(fn ($row) => $this->outstandingRow($row))
            ->all();
    }

    /**
     * The same list, a page at a time — the dashboard table pages through it
     * rather than stopping at the first eight.
     *
     * @param  list<int>|null  $branches
     */
    public function outstandingPatientsPage(?array $branches, ?int $branchId, int $perPage): LengthAwarePaginator
    {
        return $this->outstandingQuery($branches, $branchId)
            ->paginate($perPage)
            ->through(fn ($row) => $this->outstandingRow($row));
    }

    /** @param  list<int>|null  $branches */
    private function outstandingQuery(?array $branches, ?int $branchId): Builder
    {
        return Invoice::query()
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('invoices.location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('invoices.location_id', $branchId))
            ->outstanding()
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('COALESCE(invoices.customer_id::text, invoices.walk_in_phone) AS patient_key')
            ->selectRaw('COALESCE(customers.name, invoices.walk_in_name, invoices.walk_in_phone, \'Walk-in\') AS patient_name')
            ->selectRaw('MAX(invoices.customer_id) AS customer_id')
            ->selectRaw('MAX(customers.phone) AS phone')
            ->selectRaw('MAX(invoices.walk_in_phone) AS walk_in_phone')
            ->selectRaw('MAX(invoices.invoice_date) AS last_visit')
            ->selectRaw('SUM(invoices.total_amount) AS total')
            ->selectRaw('SUM(invoices.paid_amount) AS paid')
            ->groupByRaw('COALESCE(invoices.customer_id::text, invoices.walk_in_phone), COALESCE(customers.name, invoices.walk_in_name, invoices.walk_in_phone, \'Walk-in\')')
            ->orderByRaw('MAX(invoices.invoice_date) DESC')
            // A tie-break, so two people last seen the same day keep their
            // order from one page to the next.
            ->orderByRaw('COALESCE(invoices.customer_id::text, invoices.walk_in_phone)');
    }

    /** @return array{patient: string, customer_id: int|null, phone: string|null, last_visit: string, total: float, paid: float, due: float, days: int} */
    private function outstandingRow(object $row): array
    {
        $total = round((float) $row->total, 2);
        $paid = round((float) $row->paid, 2);
        $lastVisit = Carbon::parse($row->last_visit);

        return [
            'patient' => (string) $row->patient_name,
            'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
            'phone' => $row->phone ?? $row->walk_in_phone,
            'last_visit' => $lastVisit->toDateString(),
            'total' => $total,
            'paid' => $paid,
            'due' => round(max(0.0, $total - $paid), 2),
            'days' => (int) $lastVisit->diffInDays(Carbon::today()),
        ];
    }

    /**
     * The same window, one window earlier — for the "+12%" on each card.
     *
     * A percentage against nothing is not a percentage, so a previous total
     * of zero reports null rather than an infinity the screen would have to
     * special-case anyway.
     *
     * @param  list<int>|null  $branches
     * @return array<string, float>
     */
    private function previousWindow(Carbon $from, Carbon $to, ?array $branches, ?int $branchId): array
    {
        $length = $from->diffInDays($to) + 1;

        $previousTo = (clone $from)->subDay()->endOfDay();
        $previousFrom = (clone $previousTo)->subDays($length - 1)->startOfDay();

        $query = Invoice::query()
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('location_id', $branchId))
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->whereNot('status', Invoice::STATUS_CANCELLED)
            ->whereBetween('invoice_date', [$previousFrom, $previousTo]);

        return [
            'invoiced' => round((float) (clone $query)->sum('total_amount'), 2),
            'paid' => round((float) (clone $query)->sum('paid_amount'), 2),
            'count' => (int) (clone $query)->count(),
            'billed_patients' => (int) (clone $query)
                ->distinct()
                ->count(DB::raw('COALESCE(customer_id::text, walk_in_phone)')),
        ];
    }

    /**
     * Invoiced and collected, day by day.
     *
     * Every day in the window appears, including the quiet ones — a chart
     * that skips empty days compresses a slow week into a busy-looking one.
     *
     * @param  list<int>|null  $branches
     * @return list<array{date: string, invoiced: float, paid: float, outstanding: float, invoices: int}>
     */
    private function trend(Carbon $from, Carbon $to, ?array $branches, ?int $branchId): array
    {
        $days = $from->diffInDays($to) + 1;

        /*
         * The bar is a day, a week or a month depending on how long the
         * window is. A year plotted daily is 365 bars two pixels wide, which
         * is a texture rather than a chart; a week plotted monthly is one
         * bar, which is a number. The thresholds are where each stops being
         * readable.
         */
        [$unit, $sql] = match (true) {
            $days <= 31 => ['day', "DATE_TRUNC('day', invoice_date)"],
            $days <= 120 => ['week', "DATE_TRUNC('week', invoice_date)"],
            default => ['month', "DATE_TRUNC('month', invoice_date)"],
        };

        $rows = Invoice::query()
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('location_id', $branchId))
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->whereNot('status', Invoice::STATUS_CANCELLED)
            ->whereBetween('invoice_date', [$from, $to])
            ->selectRaw("{$sql} AS bucket")
            ->selectRaw('SUM(total_amount) AS invoiced')
            ->selectRaw('SUM(paid_amount) AS paid')
            /* How MANY, not just how much — the invoice-count tile draws its
               own sparkline from this, and a count tile plotting a revenue
               curve is a tile telling somebody something that is not true. */
            ->selectRaw('COUNT(*) AS invoices')
            ->groupByRaw($sql)
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->bucket)->toDateString());

        $byCategory = $this->trendByCategory($from, $to, $branches, $branchId, $sql);

        $points = [];
        $cursor = match ($unit) {
            'week' => (clone $from)->startOfWeek(),
            'month' => (clone $from)->startOfMonth(),
            default => (clone $from)->startOfDay(),
        };

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $row = $rows[$key] ?? null;

            $invoiced = round((float) ($row->invoiced ?? 0), 2);
            $paid = round((float) ($row->paid ?? 0), 2);

            $points[] = [
                'date' => $key,
                'unit' => $unit,
                'invoiced' => $invoiced,
                'paid' => $paid,
                /* What that bucket's bills still owe — the line the chart
                   draws over the bars. */
                'outstanding' => round(max(0.0, $invoiced - $paid), 2),
                'invoices' => (int) ($row->invoices ?? 0),
                /* The same bucket, split by where the money came from — the
                   stacked bars on the collection-trend chart. */
                'by_category' => $byCategory[$key] ?? [
                    'consultation' => 0.0,
                    'pharmacy' => 0.0,
                    'laboratory' => 0.0,
                    'procedure' => 0.0,
                    'other' => 0.0,
                ],
            ];

            match ($unit) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $points;
    }

    /**
     * The trend's bars, split by line source — one query per window rather
     * than per bucket, then keyed the same way `trend()` keys its own rows
     * so the two line up without a second pass over the dates.
     *
     * @param  list<int>|null  $branches
     * @return array<string, array{consultation: float, pharmacy: float, laboratory: float, procedure: float, other: float}>
     */
    private function trendByCategory(Carbon $from, Carbon $to, ?array $branches, ?int $branchId, string $bucketSql): array
    {
        $bucket = str_replace('invoice_date', 'invoices.invoice_date', $bucketSql);

        $rows = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('invoices.location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('invoices.location_id', $branchId))
            ->whereNot('invoices.status', Invoice::STATUS_DRAFT)
            ->whereNot('invoices.status', Invoice::STATUS_CANCELLED)
            ->whereBetween('invoices.invoice_date', [$from, $to])
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('invoice_items.source_type AS source_type')
            ->selectRaw('SUM(invoice_items.line_total) AS amount')
            ->groupByRaw("{$bucket}, invoice_items.source_type")
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $key = Carbon::parse($row->bucket)->toDateString();

            $out[$key] ??= [
                'consultation' => 0.0,
                'pharmacy' => 0.0,
                'laboratory' => 0.0,
                'procedure' => 0.0,
                'other' => 0.0,
            ];

            $categoryKey = match ($row->source_type) {
                InvoiceItem::SOURCE_CONSULTATION => 'consultation',
                InvoiceItem::SOURCE_PHARMACY_SALE_ITEM => 'pharmacy',
                InvoiceItem::SOURCE_LAB_TEST => 'laboratory',
                InvoiceItem::SOURCE_PROCEDURE => 'procedure',
                default => 'other',
            };

            $out[$key][$categoryKey] += round((float) $row->amount, 2);
        }

        return $out;
    }

    /**
     * How the window's bills ended up — the donut.
     *
     * Cancelled IS counted here, unlike in the money: "how many did we cancel"
     * is the question this breakdown exists to answer.
     *
     * @param  list<int>|null  $branches
     * @return array<string, int>
     */
    private function statuses(Carbon $from, Carbon $to, ?array $branches, ?int $branchId): array
    {
        $counts = Invoice::query()
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('location_id', $branchId))
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->whereBetween('invoice_date', [$from, $to])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'paid' => (int) ($counts[Invoice::STATUS_PAID] ?? 0),
            'partially_paid' => (int) ($counts[Invoice::STATUS_PARTIALLY_PAID] ?? 0),
            'pending' => (int) ($counts[Invoice::STATUS_PENDING] ?? 0),
            'cancelled' => (int) ($counts[Invoice::STATUS_CANCELLED] ?? 0),
        ];
    }

    /**
     * What each tender took, for the payments screen's method tabs.
     *
     * Refunds subtract, because a method's total is what it NET took — a card
     * payment refunded the same day did not earn the clinic anything, and
     * reporting it as if it did is how a till stops reconciling.
     *
     * @param  list<int>|null  $branches
     * @return array<string, float>
     */
    public function byMethod(Carbon $from, Carbon $to, ?array $branches): array
    {
        return InvoicePayment::query()
            ->whereHas('invoice', fn (Builder $q) => $q
                ->when($branches !== null, fn (Builder $inner) => $inner->whereIn('location_id', $branches)))
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('method')
            ->selectRaw('SUM(CASE WHEN is_refund THEN -amount ELSE amount END) AS net')
            ->groupBy('method')
            ->pluck('net', 'method')
            ->map(fn ($value) => round((float) $value, 2))
            ->all();
    }

    /**
     * The payments register's own cards and tender tabs.
     *
     * Collected and refunded are kept apart rather than netted, same as the
     * two cards on screen — "how much came in" and "how much went back out"
     * are different questions, and a till reconciles against both.
     *
     * The method breakdown carries a COUNT as well as the amount: the tabs
     * read "Cash (58)", and {@see methods()} only ever had to answer "what
     * share", not "how many".
     *
     * @param  list<int>|null  $branches
     * @return array{
     *     total_collected: array{value: float, change: float|null},
     *     transactions: array{value: int, change: float|null},
     *     total_refunds: array{value: float, change: float|null},
     *     methods: list<array{method: string, count: int, amount: float, share: float}>,
     * }
     */
    public function paymentsSummary(Carbon $from, Carbon $to, ?array $branches, ?int $branchId): array
    {
        $between = fn (Carbon $from, Carbon $to) => InvoicePayment::query()
            ->whereHas('invoice', fn (Builder $q) => $q
                ->when($branches !== null, fn (Builder $inner) => $inner->whereIn('location_id', $branches))
                ->when($branchId !== null, fn (Builder $inner) => $inner->where('location_id', $branchId)))
            ->whereBetween('paid_at', [$from, $to]);

        $collected = (float) (clone $between($from, $to))->where('is_refund', false)->sum('amount');
        $refunded = (float) (clone $between($from, $to))->where('is_refund', true)->sum('amount');
        $count = (int) (clone $between($from, $to))->count();

        $methodRows = (clone $between($from, $to))
            ->selectRaw('method')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('SUM(CASE WHEN is_refund THEN -amount ELSE amount END) AS net')
            ->groupBy('method')
            ->get();

        $netTotal = $methodRows->sum(fn ($row) => max(0.0, (float) $row->net));

        $methods = $methodRows
            ->map(fn ($row) => [
                'method' => (string) $row->method,
                'count' => (int) $row->cnt,
                'amount' => round((float) $row->net, 2),
                'share' => $netTotal > 0 ? round((max(0.0, (float) $row->net) / $netTotal) * 100, 1) : 0.0,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        // One window earlier, for the "+12%" under each card — same shape as previousWindow().
        $length = $from->diffInDays($to) + 1;
        $previousTo = (clone $from)->subDay()->endOfDay();
        $previousFrom = (clone $previousTo)->subDays($length - 1)->startOfDay();

        $previousCollected = (float) (clone $between($previousFrom, $previousTo))->where('is_refund', false)->sum('amount');
        $previousRefunded = (float) (clone $between($previousFrom, $previousTo))->where('is_refund', true)->sum('amount');
        $previousCount = (int) (clone $between($previousFrom, $previousTo))->count();

        $change = fn (float $now, float $before) => $before > 0 ? round((($now - $before) / $before) * 100) : null;

        return [
            'total_collected' => ['value' => round($collected, 2), 'change' => $change($collected, $previousCollected)],
            'transactions' => ['value' => $count, 'change' => $change($count, $previousCount)],
            'total_refunds' => ['value' => round($refunded, 2), 'change' => $change($refunded, $previousRefunded)],
            'methods' => $methods,
        ];
    }

    /**
     * The outstanding register's own cards — unpaid against partially paid.
     *
     * Not windowed unless asked: a debt from last month is still owed today
     * (see outstandingQuery()'s own note), so `$from`/`$to` only narrow by the
     * bill's own date when the screen's date filter is actually set, rather
     * than defaulting to a window that would hide older debt.
     *
     * @param  list<int>|null  $branches
     * @return array{
     *     total_outstanding: float,
     *     total_invoices: int,
     *     unpaid: array{count: int, amount: float},
     *     partial: array{count: int, amount: float},
     * }
     */
    public function outstandingSummary(?array $branches, ?int $branchId, ?Carbon $from, ?Carbon $to): array
    {
        $query = fn () => Invoice::query()
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
            ->when($branchId !== null, fn (Builder $q) => $q->where('location_id', $branchId))
            ->outstanding()
            ->when($from !== null, fn (Builder $q) => $q->whereDate('invoice_date', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('invoice_date', '<=', $to->toDateString()));

        $owed = fn (Builder $q) => round((float) $q->sum(DB::raw('total_amount - paid_amount')), 2);

        $unpaid = $query()->where('payment_status', Invoice::UNPAID);
        $partial = $query()->where('payment_status', Invoice::PARTIAL);

        return [
            'total_outstanding' => $owed($query()),
            'total_invoices' => (int) $query()->count(),
            'unpaid' => ['count' => (int) (clone $unpaid)->count(), 'amount' => $owed(clone $unpaid)],
            'partial' => ['count' => (int) (clone $partial)->count(), 'amount' => $owed(clone $partial)],
        ];
    }
}
