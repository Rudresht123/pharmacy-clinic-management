<?php

namespace App\Services\Laboratory;

use App\Models\Tenant\LabOrder;
use App\Models\Tenant\LabOrderItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The laboratory dashboard's figures, from the orders themselves.
 *
 * REAL DATA ONLY. Every number is a count of tests on lab orders at the
 * branches the person may see; nothing is sampled or estimated, and a lab with
 * no orders shows zeros and empty charts rather than an illustration.
 *
 * A TEST is one line on an order (a CBC and an LFT ordered together are two
 * tests). "Completed" is a test whose result was signed, dated by when; the
 * other figures are dated by the day the test was ordered. Pending is a count
 * of right now, not of the period.
 */
class LabDashboard
{
    /** Each range: how many buckets, and what one bucket is. */
    public const RANGES = [
        '7d' => [7, 'day'],
        '30d' => [30, 'day'],
        '3m' => [13, 'week'],
        '1y' => [12, 'month'],
    ];

    /** How many departments the chart names before folding the rest into "Others". */
    private const DEPARTMENTS_SHOWN = 4;

    /**
     * @param  list<int>|null  $branches  null means every branch
     * @return array<string, mixed>
     */
    public function summary(?array $branches, string $range, ?CarbonInterface $today = null): array
    {
        $today = Carbon::instance($today ?? now())->startOfDay();
        [$count, $unit] = self::RANGES[$range] ?? self::RANGES['30d'];

        $buckets = $this->buckets($today, $count, $unit);
        $from = $buckets[0]['start'];
        $days = (int) $from->diffInDays($today) + 1;
        $previous = [$from->copy()->subDays($days), $from->copy()->subDay()];

        $ordered = $this->perBucket($this->ordered($branches, $from, $today), 'lab_orders.order_date', $unit);
        $completed = $this->perBucket($this->completed($branches, $from, $today), 'lab_order_items.completed_at', $unit);
        $cancelled = $this->perBucket($this->cancelled($branches, $from, $today), 'lab_orders.order_date', $unit);

        $series = fn (array $counts) => array_map(fn (array $bucket) => (int) ($counts[$bucket['key']] ?? 0), $buckets);

        $orderedSeries = $series($ordered);
        $completedSeries = $series($completed);
        $cancelledSeries = $series($cancelled);

        $pending = $this->items($branches)
            ->where('lab_order_items.status', LabOrderItem::PENDING)
            ->whereIn('lab_orders.status', [LabOrder::PENDING, LabOrder::IN_PROGRESS]);

        return [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'kpis' => [
                'ordered' => $this->kpi($orderedSeries, $this->ordered($branches, ...$previous)->count()),
                'completed' => $this->kpi($completedSeries, $this->completed($branches, ...$previous)->count()),
                'pending' => [
                    'value' => (clone $pending)->count(),
                    'orders' => (clone $pending)->distinct()->count('lab_orders.id'),
                ],
                'cancelled' => $this->kpi($cancelledSeries, $this->cancelled($branches, ...$previous)->count()),
            ],
            'overview' => [
                'points' => array_map(fn (array $bucket) => [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'title' => $bucket['title'],
                ], $buckets),
                'ordered' => $orderedSeries,
                'completed' => $completedSeries,
            ],
            'departments' => $this->departments($branches, $from, $today),
            /*
             * The same period by test name. Tests typed by hand (every order
             * placed before the test master) have no department, so where the
             * department split says nothing the screen shows this instead.
             */
            'top_tests' => $this->topTests($branches, $from, $today),
            'recent' => $this->recent($branches),
        ];
    }

    /**
     * Tests on live orders at these branches.
     *
     * @param  list<int>|null  $branches
     * @return Builder<LabOrderItem>
     */
    private function items(?array $branches): Builder
    {
        return LabOrderItem::query()
            ->join('lab_orders', 'lab_orders.id', '=', 'lab_order_items.lab_order_id')
            ->whereNull('lab_orders.deleted_at')
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('lab_orders.location_id', $branches ?: [0]));
    }

    /** @param  list<int>|null  $branches */
    private function ordered(?array $branches, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $this->items($branches)
            ->whereBetween('lab_orders.order_date', [$from->toDateString(), $to->toDateString()]);
    }

    /** @param  list<int>|null  $branches */
    private function completed(?array $branches, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $this->items($branches)
            ->where('lab_order_items.status', LabOrderItem::COMPLETED)
            ->whereBetween('lab_order_items.completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /** @param  list<int>|null  $branches */
    private function cancelled(?array $branches, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $this->ordered($branches, $from, $to)->where('lab_order_items.status', LabOrderItem::CANCELLED);
    }

    /**
     * Counts per bucket, keyed by the bucket's first day.
     *
     * @return array<string, int>
     */
    private function perBucket(Builder $query, string $column, string $unit): array
    {
        // `$unit` comes from RANGES, never from the request.
        return $query
            ->selectRaw("date_trunc('{$unit}', {$column})::date AS bucket, count(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  list<int>  $series
     * @return array{value: int, previous: int, change: float|null, series: list<int>}
     */
    private function kpi(array $series, int $previous): array
    {
        $value = array_sum($series);

        return [
            'value' => $value,
            'previous' => $previous,
            // No change is claimed against nothing: a first period has no "up 100%".
            'change' => $previous === 0 ? null : round(($value - $previous) / $previous * 100, 1),
            'series' => $series,
        ];
    }

    /**
     * Tests ordered in the period by the department their test belongs to.
     * A test typed by hand has no department and is counted as such.
     *
     * @param  list<int>|null  $branches
     * @return list<array{key: string, label: string, value: int}>
     */
    private function departments(?array $branches, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->ordered($branches, $from, $to)
            ->where('lab_order_items.status', '!=', LabOrderItem::CANCELLED)
            ->leftJoin('lab_tests', 'lab_tests.id', '=', 'lab_order_items.lab_test_id')
            ->leftJoin('lab_departments', 'lab_departments.id', '=', 'lab_tests.department_id')
            ->selectRaw("COALESCE(lab_departments.name, '') AS department, count(*) AS total")
            ->groupBy('department')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->department === '' ? 'typed' : 'dept-'.mb_strtolower($row->department),
                'label' => $row->department === '' ? 'Typed tests (no department)' : $row->department,
                'value' => (int) $row->total,
            ]);

        if ($rows->count() <= self::DEPARTMENTS_SHOWN + 1) {
            return $rows->values()->all();
        }

        return [
            ...$rows->take(self::DEPARTMENTS_SHOWN)->values()->all(),
            ['key' => 'others', 'label' => 'Others', 'value' => (int) $rows->skip(self::DEPARTMENTS_SHOWN)->sum('value')],
        ];
    }

    /**
     * The most ordered tests in the period, the rest folded into "Others".
     *
     * @param  list<int>|null  $branches
     * @return list<array{key: string, label: string, value: int}>
     */
    private function topTests(?array $branches, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->ordered($branches, $from, $to)
            ->where('lab_order_items.status', '!=', LabOrderItem::CANCELLED)
            ->selectRaw('min(lab_order_items.test_name) AS test, count(*) AS total')
            ->groupByRaw('lower(btrim(lab_order_items.test_name))')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'key' => 'test-'.mb_strtolower(trim((string) $row->test)),
                'label' => trim((string) $row->test),
                'value' => (int) $row->total,
            ]);

        if ($rows->count() <= self::DEPARTMENTS_SHOWN + 1) {
            return $rows->values()->all();
        }

        return [
            ...$rows->take(self::DEPARTMENTS_SHOWN)->values()->all(),
            ['key' => 'others', 'label' => 'Others', 'value' => (int) $rows->skip(self::DEPARTMENTS_SHOWN)->sum('value')],
        ];
    }

    /**
     * The latest orders, as the dashboard's short list shows them.
     *
     * @param  list<int>|null  $branches
     * @return list<array<string, mixed>>
     */
    private function recent(?array $branches): array
    {
        return LabOrder::query()
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches ?: [0]))
            ->with(['patient', 'items.labTest.department'])
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (LabOrder $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'patient_name' => $order->patient?->name,
                'patient_code' => $order->patient?->code,
                'tests' => $order->testSummary(),
                'department' => $order->items->map(fn (LabOrderItem $item) => $item->labTest?->department?->name)->filter()->unique()->implode(', ') ?: null,
                'ordered_at' => $order->created_at?->toIso8601String(),
                'status' => $order->status,
            ])
            ->all();
    }

    /**
     * The buckets of a range, oldest first, each with its first day and the
     * words a chart axis and a tooltip use.
     *
     * @return list<array{start: Carbon, key: string, label: string, title: string}>
     */
    private function buckets(Carbon $today, int $count, string $unit): array
    {
        $buckets = [];

        for ($back = $count - 1; $back >= 0; $back--) {
            $start = match ($unit) {
                'week' => $today->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($back),
                'month' => $today->copy()->startOfMonth()->subMonths($back),
                default => $today->copy()->subDays($back),
            };

            $buckets[] = [
                'start' => $start,
                'key' => $start->toDateString(),
                'label' => $unit === 'month' ? $start->format('M') : $start->format('d M'),
                'title' => match ($unit) {
                    'week' => 'Week of '.$start->format('d M Y'),
                    'month' => $start->format('F Y'),
                    default => $start->format('D, d M Y'),
                },
            ];
        }

        return $buckets;
    }
}
