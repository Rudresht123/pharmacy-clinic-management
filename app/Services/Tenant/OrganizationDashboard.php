<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Models\Tenant\StoreMedicine;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The figures behind the organization dashboard — the network seen from head
 * office: what it earned, what it spent, how each branch is doing.
 *
 * PURE FIGURES. Who may see which panel is DashboardSummary's question, and it
 * is answered there before anything here runs; a method below is told what
 * it may include and never asks. That keeps one place where the gating lives.
 *
 * WHAT "REVENUE" MEANS HERE. The value of what was billed in the window: every
 * invoice that was actually raised (not a draft, not cancelled), line by line,
 * plus every completed pharmacy sale net of the goods brought back. Line by
 * line because the split by department and by service only exists on the
 * lines — and an invoice's total IS the sum of its lines (InvoicePricing), so
 * the split always adds up to the total printed beside it. Money collected is
 * a different number and lives on the Finance screen; this is what the
 * clinic sold.
 *
 * WHAT "PURCHASES" MEANS. Stock bought in from suppliers — posted purchase
 * inwards, at what the supplier's invoice said. Opening balances and patient
 * returns are not purchases. It is the only cost the software records, so it
 * is also what "expenses" and "profit" are measured against; the screen says
 * so rather than implying a full set of accounts.
 */
class OrganizationDashboard
{
    /** Longer than this, a period chart is drawn by month instead of by day. */
    private const DAILY_LIMIT = 62;

    /** How many rows the branch table and the two lists show. */
    private const ROWS = 5;

    /* =====================================================================
     | The banner
     |===================================================================== */

    /**
     * The organization's size, for the banner.
     *
     * Each count is null when the caller may not see what it counts, so the
     * banner leaves it out rather than printing a zero that means "hidden".
     *
     * @param  array{branches: bool, doctors: bool, staff: bool}  $show
     * @return array<string, mixed>
     */
    public function overview(Organization $organization, User $user, ?array $allowed, array $show): array
    {
        return [
            'since' => ($organization->activated_at ?? $organization->created_at)?->toDateString(),

            'branches' => $show['branches']
                ? $this->scoped(Location::query(), 'id', $allowed)->count()
                : null,

            // A doctor works where they are posted, so a branch manager counts
            // the doctors posted to their branches rather than the network's.
            'doctors' => $show['doctors']
                ? Doctor::query()
                    ->active()
                    ->when($allowed !== null, fn (Builder $q) => $q->whereHas(
                        'postings',
                        fn (Builder $posting) => $posting->whereIn('locations.id', $allowed ?: [0]),
                    ))
                    ->count()
                : null,

            // Everybody but the person reading — the same count the headline
            // card has always shown.
            'staff' => $show['staff']
                ? User::query()
                    ->where('id', '!=', $user->getKey())
                    ->when($allowed !== null, fn (Builder $q) => $q->whereHas(
                        'memberships',
                        fn (Builder $membership) => $membership->whereIn('location_id', $allowed ?: [0]),
                    ))
                    ->count()
                : null,
        ];
    }

    /* =====================================================================
     | The five cards
     |===================================================================== */

    /**
     * The figures across the top, each against the period before.
     *
     * A card the caller may not see is left out, not zeroed. Profit needs
     * both revenue and purchases, so it appears only beside both.
     *
     * @param  array{patients: bool, appointments: bool, revenue: bool, purchases: bool}  $show
     * @return list<array<string, mixed>>
     */
    public function kpis(DashboardWindow $window, ?array $allowed, array $show): array
    {
        $previous = $window->previous();
        $unit = $this->unitFor($window);
        $buckets = array_column($this->buckets($window->from, $window->to, $unit), 'key');

        $cards = [];

        if ($show['patients']) {
            $registered = fn (Carbon $from, Carbon $to) => $this->scoped(Customer::query(), 'registered_location_id', $allowed)
                ->whereBetween('created_at', [$from, $to]);

            $now = $registered($window->from, $window->to)->count();
            $before = $registered($previous->from, $previous->to)->count();

            $cards[] = [
                'key' => 'patients',
                'label' => 'Total Patients',
                // The whole book as it stood at the end of the window; the
                // change is how fast it grew against the period before.
                'value' => $this->scoped(Customer::query(), 'registered_location_id', $allowed)
                    ->where('created_at', '<=', $window->to)
                    ->count(),
                'change' => $this->change($now, $before),
                'series' => $this->align($buckets, $registered($window->from, $window->to)
                    ->selectRaw($this->bucket('created_at', $unit).' AS bucket, COUNT(*) AS total')
                    ->groupBy('bucket')
                    ->pluck('total', 'bucket')
                    ->all()),
            ];
        }

        if ($show['appointments']) {
            $booked = fn (Carbon $from, Carbon $to) => $this->scoped(Appointment::query(), 'location_id', $allowed)
                ->where('status', '!=', Appointment::STATUS_CANCELLED)
                ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()]);

            $now = $booked($window->from, $window->to)->count();

            $cards[] = [
                'key' => 'appointments',
                'label' => 'Total Appointments',
                'value' => $now,
                'change' => $this->change($now, $booked($previous->from, $previous->to)->count()),
                'series' => $this->align($buckets, $booked($window->from, $window->to)
                    ->selectRaw($this->bucket('appointment_date', $unit).' AS bucket, COUNT(*) AS total')
                    ->groupBy('bucket')
                    ->pluck('total', 'bucket')
                    ->all()),
            ];
        }

        $revenue = $show['revenue'] ? $this->revenueSeries($window->from, $window->to, $allowed, null, $unit) : [];
        $purchases = $show['purchases'] ? $this->purchaseSeries($window->from, $window->to, $allowed, null, $unit) : [];

        $revenueNow = array_sum($revenue);
        $purchasesNow = array_sum($purchases);

        $revenueBefore = $show['revenue'] ? array_sum($this->revenueSeries($previous->from, $previous->to, $allowed, null, 'all')) : 0.0;
        $purchasesBefore = $show['purchases'] ? array_sum($this->purchaseSeries($previous->from, $previous->to, $allowed, null, 'all')) : 0.0;

        if ($show['revenue']) {
            $cards[] = [
                'key' => 'revenue',
                'label' => 'Total Revenue',
                'value' => round($revenueNow, 2),
                'money' => true,
                'change' => $this->change($revenueNow, $revenueBefore),
                'series' => $this->align($buckets, $revenue),
            ];
        }

        if ($show['purchases']) {
            $cards[] = [
                'key' => 'purchases',
                'label' => 'Total Purchases',
                'value' => round($purchasesNow, 2),
                'money' => true,
                'change' => $this->change($purchasesNow, $purchasesBefore),
                'series' => $this->align($buckets, $purchases),
            ];
        }

        if ($show['revenue'] && $show['purchases']) {
            $cards[] = [
                'key' => 'profit',
                'label' => 'Net Profit',
                'value' => round($revenueNow - $purchasesNow, 2),
                'money' => true,
                'hint' => 'Revenue less stock purchases',
                'change' => $this->change($revenueNow - $purchasesNow, $revenueBefore - $purchasesBefore),
                'series' => array_map(
                    fn (string $key) => round(($revenue[$key] ?? 0) - ($purchases[$key] ?? 0), 2),
                    $buckets,
                ),
            ];
        }

        return $cards;
    }

    /* =====================================================================
     | Revenue overview
     |===================================================================== */

    /**
     * Revenue by department over time, ending where the window ends.
     *
     *   monthly  the twelve months up to and including the window's last
     *   weekly   the twelve weeks up to and including the window's last
     *   daily    the window's own days, or its last 31 when it is longer
     *
     * @return array<string, mixed>
     */
    public function revenueOverview(DashboardWindow $window, ?array $allowed, string $granularity, ?int $branch): array
    {
        $end = $window->to->copy();

        [$from, $unit] = match ($granularity) {
            'daily' => [
                $window->days() > 31 ? $end->copy()->subDays(30)->startOfDay() : $window->from->copy(),
                'day',
            ],
            'weekly' => [$end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(11), 'week'],
            default => [$end->copy()->startOfMonth()->subMonthsNoOverflow(11), 'month'],
        };

        $byBucket = $this->revenueByCategory($from, $end, $allowed, $branch, $unit, 'department');

        $series = array_map(function (array $bucket) use ($byBucket) {
            $parts = $byBucket[$bucket['key']] ?? [];

            $point = [
                ...$bucket,
                'opd' => round($parts['opd'] ?? 0, 2),
                'pharmacy' => round($parts['pharmacy'] ?? 0, 2),
                'laboratory' => round($parts['laboratory'] ?? 0, 2),
                'procedures' => round($parts['procedures'] ?? 0, 2),
            ];

            $point['total'] = round($point['opd'] + $point['pharmacy'] + $point['laboratory'] + $point['procedures'], 2);

            return $point;
        }, $this->buckets($from, $end, $unit));

        return [
            'granularity' => $granularity,
            'location_id' => $branch,
            'series' => $series,
        ];
    }

    /* =====================================================================
     | Branch performance
     |===================================================================== */

    /**
     * Each branch's period, best first.
     *
     * The money columns are null rather than zero for somebody who may not
     * read money, and the appointments column likewise without the module —
     * the table then shows a dash where a zero would be a claim.
     *
     * @param  array{appointments: bool, money: bool}  $show
     * @return array<string, mixed>
     */
    public function branchPerformance(DashboardWindow $window, ?array $allowed, array $show): array
    {
        $branches = $this->scoped(Location::query(), 'id', $allowed)->orderBy('name')->get(['id', 'name', 'city', 'is_active']);

        if ($branches->isEmpty()) {
            return ['total' => 0, 'rows' => []];
        }

        $ids = $branches->pluck('id')->all();
        $previous = $window->previous();

        $patients = Customer::query()
            ->whereIn('registered_location_id', $ids)
            ->where('created_at', '<=', $window->to)
            ->selectRaw('registered_location_id, COUNT(*) AS total')
            ->groupBy('registered_location_id')
            ->pluck('total', 'registered_location_id');

        $appointments = $show['appointments']
            ? Appointment::query()
                ->whereIn('location_id', $ids)
                ->where('status', '!=', Appointment::STATUS_CANCELLED)
                ->whereBetween('appointment_date', [$window->from->toDateString(), $window->to->toDateString()])
                ->selectRaw('location_id, COUNT(*) AS total')
                ->groupBy('location_id')
                ->pluck('total', 'location_id')
            : collect();

        $revenue = $show['money'] ? $this->revenueByBranch($window->from, $window->to, $ids) : [];
        $revenueBefore = $show['money'] ? $this->revenueByBranch($previous->from, $previous->to, $ids) : [];
        $purchases = $show['money'] ? $this->purchasesByBranch($window->from, $window->to, $ids) : [];

        $rows = $branches->map(function (Location $branch) use ($show, $patients, $appointments, $revenue, $revenueBefore, $purchases) {
            $earned = (float) ($revenue[$branch->id] ?? 0);

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
                'is_active' => (bool) $branch->is_active,
                'patients' => (int) ($patients[$branch->id] ?? 0),
                'appointments' => $show['appointments'] ? (int) ($appointments[$branch->id] ?? 0) : null,
                'revenue' => $show['money'] ? round($earned, 2) : null,
                'profit' => $show['money'] ? round($earned - (float) ($purchases[$branch->id] ?? 0), 2) : null,
                'growth' => $show['money'] ? $this->change($earned, (float) ($revenueBefore[$branch->id] ?? 0)) : null,
            ];
        });

        // Best first: by what they earned where money may be read, otherwise
        // by the size of their book.
        $sorted = $show['money']
            ? $rows->sortByDesc('revenue')
            : $rows->sortByDesc('patients');

        return [
            'total' => $branches->count(),
            'rows' => $sorted->take(self::ROWS)->values()->all(),
        ];
    }

    /* =====================================================================
     | Patient visits
     |===================================================================== */

    /**
     * Visits in the window, new against returning.
     *
     * A visit is a patient who actually came — checked in or further along.
     * A booking still waiting, a cancellation and a no-show are not visits.
     *
     * "New" is answered the way the doctor's own screen answers it
     * (App\Services\Opd\DoctorDay): have we seen this patient on any earlier
     * day. Nothing stores it, so it is worked out here in the same query
     * rather than a second definition drifting from the first.
     *
     * @return array<string, mixed>
     */
    public function visitTrends(DashboardWindow $window, ?array $allowed, ?int $branch): array
    {
        $unit = $window->days() > self::DAILY_LIMIT ? 'month' : 'day';

        $rows = $this->scoped(Appointment::query(), 'location_id', $allowed)
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->whereNotIn('status', [
                Appointment::STATUS_BOOKED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ])
            ->whereBetween('appointment_date', [$window->from->toDateString(), $window->to->toDateString()])
            ->selectRaw($this->bucket('appointment_date', $unit).' AS bucket')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw(
                'SUM(CASE WHEN NOT EXISTS ('
                .'SELECT 1 FROM appointments AS prev'
                .' WHERE prev.customer_id = appointments.customer_id'
                .' AND prev.appointment_date < appointments.appointment_date'
                .' AND prev.deleted_at IS NULL'
                .') THEN 1 ELSE 0 END) AS new_visits'
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = array_map(function (array $bucket) use ($rows) {
            $row = $rows->get($bucket['key']);
            $visits = (int) ($row->visits ?? 0);
            $new = (int) ($row->new_visits ?? 0);

            return [...$bucket, 'new' => $new, 'returning' => $visits - $new];
        }, $this->buckets($window->from, $window->to, $unit));

        return [
            'granularity' => $unit,
            'location_id' => $branch,
            'series' => $points,
            'new' => array_sum(array_column($points, 'new')),
            'returning' => array_sum(array_column($points, 'returning')),
        ];
    }

    /* =====================================================================
     | Top services
     |===================================================================== */

    /**
     * Revenue by the kind of service that earned it, largest first.
     *
     * @return array<string, mixed>
     */
    public function topServices(?array $allowed, string $period): array
    {
        [$from, $to] = $this->period($period);

        $parts = $this->revenueByCategory($from, $to, $allowed, null, 'all', 'service')['all'] ?? [];
        $total = array_sum($parts);

        $labels = [
            'consultation' => 'Consultation',
            'pharmacy' => 'Pharmacy Sales',
            'laboratory' => 'Laboratory Tests',
            'procedures' => 'Procedures',
            'others' => 'Others',
        ];

        $rows = collect($labels)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'amount' => round((float) ($parts[$key] ?? 0), 2),
                'share' => $total > 0 ? round(((float) ($parts[$key] ?? 0) / $total) * 100, 1) : 0.0,
            ])
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => round($total, 2),
            'rows' => $rows,
        ];
    }

    /* =====================================================================
     | Pharmacy stock alerts
     |===================================================================== */

    /**
     * What the network's shelves need somebody to act on.
     *
     * Expired stock still on a shelf comes first — it must not be sold and is
     * already money lost — then what has run out, then what is running low.
     * "Low" is the store's own reorder level, the same test the pharmacy's
     * own dashboard uses, and "usable" stock is active and in date.
     *
     * @return array<string, mixed>
     */
    public function stockAlerts(?array $allowed): array
    {
        $stores = $this->scoped(PharmacyStore::query(), 'location_id', $allowed)
            ->with('location:id,name')
            ->get(['id', 'name', 'location_id'])
            ->keyBy('id');

        if ($stores->isEmpty()) {
            return ['counts' => ['expired' => 0, 'out' => 0, 'low' => 0], 'rows' => []];
        }

        $today = Carbon::today()->toDateString();
        $storeIds = $stores->keys()->all();

        $expired = MedicineBatch::query()
            ->whereIn('pharmacy_store_id', $storeIds)
            ->whereIn('status', [MedicineBatch::ACTIVE, MedicineBatch::EXPIRED])
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '<=', $today);

        $levels = StoreMedicine::query()
            ->leftJoin('medicine_batches AS stock', function (JoinClause $join) use ($today) {
                $join->on('stock.pharmacy_store_id', '=', 'store_medicines.pharmacy_store_id')
                    ->on('stock.medicine_id', '=', 'store_medicines.medicine_id')
                    ->where('stock.status', '=', MedicineBatch::ACTIVE)
                    ->where('stock.expiry_date', '>', $today)
                    ->whereNull('stock.deleted_at');
            })
            ->whereIn('store_medicines.pharmacy_store_id', $storeIds)
            ->where('store_medicines.is_active', true)
            ->select('store_medicines.pharmacy_store_id', 'store_medicines.medicine_id', 'store_medicines.reorder_level')
            ->selectRaw('COALESCE(SUM(stock.quantity_available), 0) AS on_hand')
            ->groupBy('store_medicines.id', 'store_medicines.pharmacy_store_id', 'store_medicines.medicine_id', 'store_medicines.reorder_level')
            ->havingRaw('COALESCE(SUM(stock.quantity_available), 0) <= store_medicines.reorder_level');

        $counts = (new StoreMedicine)->getConnection()->query()
            ->fromSub($levels->toBase(), 'levels')
            ->selectRaw('COUNT(*) AS short, COUNT(*) FILTER (WHERE on_hand = 0) AS empty')
            ->first();

        $expiredRows = (clone $expired)
            ->with('medicine')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->limit(self::ROWS)
            ->get();

        $shortRows = (clone $levels)
            ->orderByRaw('COALESCE(SUM(stock.quantity_available), 0) ASC')
            ->orderBy('store_medicines.medicine_id')
            ->limit(self::ROWS)
            ->get();

        $medicines = Medicine::withTrashed()
            ->whereIn('id', $shortRows->pluck('medicine_id'))
            ->get(['id', 'generic_name', 'brand_name', 'strength', 'dosage_form', 'base_unit'])
            ->keyBy('id');

        $where = fn (int $storeId) => $stores->get($storeId)?->location?->name
            ?? $stores->get($storeId)?->name;

        $rows = $expiredRows
            ->map(fn (MedicineBatch $batch) => [
                'key' => 'batch-'.$batch->id,
                'name' => $batch->medicine?->displayName() ?? 'Unknown',
                'branch' => $where((int) $batch->pharmacy_store_id),
                'quantity' => (int) $batch->quantity_available,
                'unit' => $this->unit($batch->medicine?->base_unit, (int) $batch->quantity_available),
                'status' => 'expired',
                'expiry_date' => $batch->expiry_date?->toDateString(),
            ])
            ->concat($shortRows->map(function ($row) use ($medicines, $where) {
                $onHand = (int) $row->on_hand;
                $medicine = $medicines->get($row->medicine_id);

                return [
                    'key' => 'stock-'.$row->pharmacy_store_id.'-'.$row->medicine_id,
                    'name' => $medicine?->displayName() ?? 'Unknown',
                    'branch' => $where((int) $row->pharmacy_store_id),
                    'quantity' => $onHand,
                    'unit' => $this->unit($medicine?->base_unit, $onHand),
                    'status' => $onHand === 0 ? 'out' : 'low',
                    'expiry_date' => null,
                ];
            }))
            // Expired first, then nothing left, then running low.
            ->sortBy(fn (array $row) => ['expired' => 0, 'out' => 1, 'low' => 2][$row['status']])
            ->take(self::ROWS)
            ->values()
            ->all();

        $short = (int) ($counts->short ?? 0);
        $empty = (int) ($counts->empty ?? 0);

        return [
            'counts' => [
                'expired' => (clone $expired)->count(),
                'out' => $empty,
                'low' => $short - $empty,
            ],
            'rows' => $rows,
        ];
    }

    /* =====================================================================
     | Profit & loss
     |===================================================================== */

    /**
     * The year so far, month by month: what came in, what went out on stock,
     * and what was left — each against the same span a year earlier.
     *
     * @return array<string, mixed>
     */
    public function profitLoss(?array $allowed, string $period): array
    {
        $today = Carbon::today();

        [$from, $to] = $period === 'last_year'
            ? [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()]
            : [$today->copy()->startOfYear(), $today->copy()->endOfDay()];

        $before = [$from->copy()->subYear(), $to->copy()->subYear()];

        $months = $this->buckets($from, $to, 'month');
        $buckets = array_column($months, 'key');

        $revenue = $this->revenueSeries($from, $to, $allowed, null, 'month');
        $expenses = $this->purchaseSeries($from, $to, $allowed, null, 'month');

        $revenueNow = array_sum($revenue);
        $expensesNow = array_sum($expenses);
        $revenueThen = array_sum($this->revenueSeries($before[0], $before[1], $allowed, null, 'all'));
        $expensesThen = array_sum($this->purchaseSeries($before[0], $before[1], $allowed, null, 'all'));

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // What each point of the three series below is — the chart's axis.
            'months' => $months,
            'revenue' => [
                'value' => round($revenueNow, 2),
                'change' => $this->change($revenueNow, $revenueThen),
                'series' => $this->align($buckets, $revenue),
            ],
            'expenses' => [
                'value' => round($expensesNow, 2),
                'change' => $this->change($expensesNow, $expensesThen),
                'series' => $this->align($buckets, $expenses),
            ],
            'profit' => [
                'value' => round($revenueNow - $expensesNow, 2),
                'change' => $this->change($revenueNow - $expensesNow, $revenueThen - $expensesThen),
                'series' => array_map(
                    fn (string $key) => round(($revenue[$key] ?? 0) - ($expenses[$key] ?? 0), 2),
                    $buckets,
                ),
            ],
        ];
    }

    /* =====================================================================
     | Recent activity across the branches
     |===================================================================== */

    /**
     * The records whose arrival is news across the network. The rest of the
     * log — a prescription line, a template version, a lock — is accurate
     * and belongs on the activity screen, not on the front page.
     */
    private const NEWSWORTHY = [
        'Customer', 'Doctor', 'Location', 'Appointment', 'Invoice', 'BillableService',
        'PharmacySale', 'PharmacySaleReturn', 'StockInward', 'StockTransfer', 'StockAdjustment',
        'BranchMembership', 'Role',
    ];

    /** Of those, the ones whose EDITS are news too — a branch, a doctor. */
    private const NEWSWORTHY_EDITS = ['Location', 'Doctor', 'BillableService', 'Role'];

    /**
     * What people across the branches have just done, said as a sentence
     * with the branch it happened at — "New patient Asha Verma registered at
     * Lucknow".
     *
     * People only: the scheduler and the seeders write the log too (a nightly
     * job touching eight hundred prescriptions), and a front page of those
     * says nothing about the branches. An appointment moving through its
     * workflow is an edit on every step, so edits count only for the few
     * records whose change is itself the news.
     *
     * @return list<array<string, mixed>>
     */
    public function branchActivity(): array
    {
        $entries = ActivityLog::query()
            ->where('actor_type', 'tenant')
            ->whereIn('entity_type', self::NEWSWORTHY)
            ->where(fn (Builder $q) => $q
                ->where('event', '!=', 'updated')
                ->orWhereIn('entity_type', self::NEWSWORTHY_EDITS))
            ->latest('id')
            ->limit(self::ROWS)
            ->get();

        $branches = Location::withTrashed()
            ->whereIn('id', $entries->pluck('location_id')->filter()->unique())
            ->pluck('name', 'id');

        return $entries->map(function (ActivityLog $row) use ($branches) {
            $branch = $row->location_id ? ($branches[$row->location_id] ?? null) : null;
            $text = $this->sentence($row->event, $row->entity_type);

            return [
                'id' => $row->id,
                'event' => $row->event,
                'entity_type' => $row->entity_type,
                'text' => $branch ? "{$text} at {$branch}" : $text,
                // What the record is called, kept out of the sentence: a
                // name in the middle of one ("New posting Neha Sharma at
                // Gorakhpur added") stops it reading as a sentence.
                'detail' => $row->entity_label,
                'branch' => $branch,
                'actor_name' => $row->actor_name,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /** How one log entry reads aloud — "New doctor added". */
    private function sentence(string $event, string $entity): string
    {
        if ($event === 'created') {
            $said = match ($entity) {
                'Customer' => 'New patient registered',
                'Doctor' => 'New doctor added',
                'Location' => 'New branch opened',
                'Appointment' => 'Appointment booked',
                'Invoice' => 'Bill raised',
                'BillableService' => 'New service added',
                'PharmacySale' => 'Pharmacy sale made',
                'PharmacySaleReturn' => 'Pharmacy return taken',
                'StockInward' => 'Pharmacy stock received',
                'StockTransfer' => 'Stock transfer started',
                'StockAdjustment' => 'Stock adjusted',
                'BranchMembership' => 'Staff member posted',
                'Role' => 'New role created',
                default => null,
            };

            if ($said !== null) {
                return $said;
            }
        }

        $noun = match ($entity) {
            'Customer' => 'patient',
            'Location' => 'branch',
            'Invoice' => 'bill',
            'BillableService' => 'service',
            'BranchMembership' => 'staff posting',
            default => Str::of($entity)->snake(' ')->toString(),
        };

        return match ($event) {
            'created' => "New {$noun} added",
            'deleted' => Str::ucfirst($noun).' removed',
            'restored' => Str::ucfirst($noun).' restored',
            default => Str::ucfirst($noun).' updated',
        };
    }

    /* =====================================================================
     | Money, bucketed
     |===================================================================== */

    /**
     * Billed revenue per bucket, every department together.
     *
     * @return array<string, float> bucket key => amount
     */
    private function revenueSeries(Carbon $from, Carbon $to, ?array $allowed, ?int $branch, string $unit): array
    {
        return array_map(
            fn (array $parts) => array_sum($parts),
            $this->revenueByCategory($from, $to, $allowed, $branch, $unit, 'department'),
        );
    }

    /**
     * Billed revenue per bucket, split two ways depending on the question:
     *
     *   department  OPD, Pharmacy, Laboratory, Procedures — who earned it
     *   service     Consultation, Pharmacy, Laboratory, Procedures, Others —
     *               what was sold
     *
     * @return array<string, array<string, float>> bucket key => category => amount
     */
    private function revenueByCategory(Carbon $from, Carbon $to, ?array $allowed, ?int $branch, string $unit, string $scheme): array
    {
        $out = [];

        $lines = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED])
            ->whereBetween('invoices.invoice_date', [$from, $to])
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('invoices.location_id', $allowed ?: [0]))
            ->when($branch !== null, fn (Builder $q) => $q->where('invoices.location_id', $branch))
            ->selectRaw($this->bucket('invoices.invoice_date', $unit).' AS bucket')
            ->selectRaw('invoices.kind AS kind, invoice_items.source_type AS source')
            ->selectRaw('COALESCE(SUM(invoice_items.line_total), 0) AS amount')
            ->groupBy('bucket', 'invoices.kind', 'invoice_items.source_type')
            ->get();

        foreach ($lines as $line) {
            $category = $this->category((string) $line->kind, $line->source, $scheme);
            $out[$line->bucket][$category] = ($out[$line->bucket][$category] ?? 0.0) + (float) $line->amount;
        }

        $sales = $this->pharmacySales($allowed, $branch)
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw($this->bucket('sale_date', $unit).' AS bucket')
            ->selectRaw('COALESCE(SUM(total_amount - returned_amount), 0) AS amount')
            ->groupBy('bucket')
            ->get();

        foreach ($sales as $sale) {
            $out[$sale->bucket]['pharmacy'] = ($out[$sale->bucket]['pharmacy'] ?? 0.0) + (float) $sale->amount;
        }

        return $out;
    }

    /**
     * Which heading an invoice line's money belongs under.
     *
     * A lab bill is the laboratory's whatever its lines are — a collection
     * fee on it is still the lab's takings. On any other bill the line's own
     * source decides, and what the OPD bills that is not a consultation or a
     * procedure (registration, per-visit services, a typed line) is the OPD's
     * — or, asked what was sold, "Others".
     */
    private function category(string $kind, ?string $source, string $scheme): string
    {
        if ($kind === Invoice::KIND_LAB || $source === InvoiceItem::SOURCE_LAB_TEST) {
            return 'laboratory';
        }

        if ($source === InvoiceItem::SOURCE_PROCEDURE) {
            return 'procedures';
        }

        if ($source === InvoiceItem::SOURCE_PHARMACY_SALE_ITEM) {
            return 'pharmacy';
        }

        if ($scheme === 'service') {
            return $source === InvoiceItem::SOURCE_CONSULTATION ? 'consultation' : 'others';
        }

        return 'opd';
    }

    /**
     * Stock bought in, per bucket.
     *
     * @return array<string, float>
     */
    private function purchaseSeries(Carbon $from, Carbon $to, ?array $allowed, ?int $branch, string $unit): array
    {
        return $this->purchases($allowed, $branch)
            ->whereBetween('received_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw($this->bucket('received_date', $unit).' AS bucket')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('bucket')
            ->pluck('amount', 'bucket')
            ->map(fn ($amount) => (float) $amount)
            ->all();
    }

    /**
     * Billed revenue per branch over a window.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function revenueByBranch(Carbon $from, Carbon $to, array $ids): array
    {
        $out = [];

        $invoiced = Invoice::query()
            ->whereIn('location_id', $ids)
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED])
            ->whereBetween('invoice_date', [$from, $to])
            ->selectRaw('location_id, COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('location_id')
            ->get();

        $sold = $this->pharmacySales($ids, null)
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw('location_id, COALESCE(SUM(total_amount - returned_amount), 0) AS amount')
            ->groupBy('location_id')
            ->get();

        foreach ($invoiced->concat($sold) as $row) {
            $out[(int) $row->location_id] = ($out[(int) $row->location_id] ?? 0.0) + (float) $row->amount;
        }

        return $out;
    }

    /**
     * Stock bought in per branch over a window.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function purchasesByBranch(Carbon $from, Carbon $to, array $ids): array
    {
        return $this->purchases($ids, null)
            ->whereBetween('received_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('location_id, COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('location_id')
            ->pluck('amount', 'location_id')
            ->map(fn ($amount) => (float) $amount)
            ->all();
    }

    /**
     * The pharmacy's completed sales whose value is its own — not a legacy
     * sale whose money a visit invoice took over, which the invoice already
     * counts (the same rule FinanceReports keeps).
     */
    private function pharmacySales(?array $allowed, ?int $branch): Builder
    {
        return $this->scoped(PharmacySale::query(), 'location_id', $allowed)
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->where('status', PharmacySale::COMPLETED)
            ->where('payment_status', '!=', PharmacySale::BILLED_VIA_INVOICE);
    }

    /** Posted purchases from suppliers — not opening balances, not returns. */
    private function purchases(?array $allowed, ?int $branch): Builder
    {
        return $this->scoped(StockInward::query(), 'location_id', $allowed)
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->where('status', StockInward::POSTED)
            ->where('inward_type', StockInward::PURCHASE);
    }

    /* =====================================================================
     | Time
     |===================================================================== */

    /**
     * The window a named period covers.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(string $period): array
    {
        $today = Carbon::today();

        return match ($period) {
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };
    }

    /** By day for a short window, by month for a long one. */
    private function unitFor(DashboardWindow $window): string
    {
        return $window->days() > self::DAILY_LIMIT ? 'month' : 'day';
    }

    /**
     * The SQL that names a row's bucket. Its output matches the keys
     * buckets() builds, which is how the two are joined up.
     */
    private function bucket(string $column, string $unit): string
    {
        return match ($unit) {
            'day' => "to_char({$column}, 'YYYY-MM-DD')",
            'week' => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            'month' => "to_char({$column}, 'YYYY-MM')",
            default => "'all'",
        };
    }

    /**
     * Every bucket in a span, present or not.
     *
     * Built from the calendar rather than from the rows that came back: a
     * day with nothing in it is a quiet day and belongs on the chart, where
     * leaving it out would draw a busier period than there was.
     *
     * @return list<array{key: string, label: string, title: string}>
     */
    private function buckets(Carbon $from, Carbon $to, string $unit): array
    {
        if ($unit === 'all') {
            return [['key' => 'all', 'label' => '', 'title' => '']];
        }

        $cursor = match ($unit) {
            'week' => $from->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };

        $out = [];

        while ($cursor->lte($to)) {
            $out[] = match ($unit) {
                'day' => ['key' => $cursor->toDateString(), 'label' => $cursor->format('j M'), 'title' => $cursor->format('j M Y')],
                'week' => ['key' => $cursor->toDateString(), 'label' => $cursor->format('j M'), 'title' => 'Week of '.$cursor->format('j M Y')],
                default => ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M'), 'title' => $cursor->format('M Y')],
            };

            match ($unit) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        return $out;
    }

    /**
     * Values in bucket order, a zero where a bucket had nothing.
     *
     * @param  list<string>  $keys
     * @param  array<string, int|float|string>  $values
     * @return list<float>
     */
    private function align(array $keys, array $values): array
    {
        return array_map(fn (string $key) => round((float) ($values[$key] ?? 0), 2), $keys);
    }

    /**
     * Null when there is nothing to compare against.
     *
     * A rise from zero has no percentage, and printing one — 100%, or worse
     * ∞ — is a number somebody would repeat. Measured against the size of the
     * earlier figure, so a loss shrinking reads as the improvement it is.
     */
    private function change(float|int $now, float|int $before): ?int
    {
        return $before != 0 ? (int) round((($now - $before) / abs($before)) * 100) : null;
    }

    /** "12 strips", "1 strip". */
    private function unit(?string $unit, int $quantity): string
    {
        return $unit ? Str::plural($unit, $quantity) : '';
    }

    /**
     * Narrow a query to the branches somebody may see. Null is every branch;
     * an empty list is none, and `[0]` keeps that a refusal.
     */
    private function scoped(Builder $query, string $column, ?array $allowed): Builder
    {
        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn($query->getModel()->getTable().'.'.$column, $allowed ?: [0]);
    }
}
