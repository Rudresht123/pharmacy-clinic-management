<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
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
     * Visits over a named period, new against returning, with the three
     * totals over the chart each against the period before.
     *
     * A visit is a patient who actually came — checked in or further along.
     * A booking still waiting, a cancellation and a no-show are not visits.
     *
     * "New" is answered the way the doctor's own screen answers it
     * (App\Services\Opd\DoctorDay): have we seen this patient on any earlier
     * day. Nothing stores it, so it is worked out here in the same query
     * rather than a second definition drifting from the first.
     *
     * The card picks its own period, as Top Services does, rather than
     * following the page's range: its header carries the choice.
     *
     * @return array<string, mixed>
     */
    public function visitTrends(?array $allowed, string $period, ?int $branch): array
    {
        [$from, $to] = $this->period($period);

        $window = new DashboardWindow($from, $to);
        $previous = $window->previous();
        $unit = $window->days() > self::DAILY_LIMIT ? 'month' : 'day';

        $rows = $this->visits($window->from, $window->to, $allowed, $branch)
            ->selectRaw($this->bucket('appointment_date', $unit).' AS bucket')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = array_map(function (array $bucket) use ($rows) {
            $row = $rows->get($bucket['key']);
            $visits = (int) ($row->visits ?? 0);
            $new = (int) ($row->new_visits ?? 0);

            return [...$bucket, 'new' => $new, 'returning' => $visits - $new];
        }, $this->buckets($window->from, $window->to, $unit));

        $new = array_sum(array_column($points, 'new'));
        $returning = array_sum(array_column($points, 'returning'));

        $before = $this->visits($previous->from, $previous->to, $allowed, $branch)->first();
        $visitsBefore = (int) ($before->visits ?? 0);
        $newBefore = (int) ($before->new_visits ?? 0);

        return [
            'period' => $period,
            'granularity' => $unit,
            'location_id' => $branch,
            'compare' => match ($period) {
                'this_year' => 'vs last year',
                'last_month' => 'vs month before',
                default => 'vs last month',
            },
            'series' => $points,
            'new' => $new,
            'returning' => $returning,
            'totals' => [
                'visits' => ['value' => $new + $returning, 'change' => $this->change($new + $returning, $visitsBefore)],
                'new' => ['value' => $new, 'change' => $this->change($new, $newBefore)],
                'returning' => ['value' => $returning, 'change' => $this->change($returning, $visitsBefore - $newBefore)],
            ],
        ];
    }

    /**
     * Visits between two days, counted and split new / returning — grouped
     * further by whoever asks.
     */
    private function visits(Carbon $from, Carbon $to, ?array $allowed, ?int $branch): Builder
    {
        return $this->scoped(Appointment::query(), 'location_id', $allowed)
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->whereNotIn('status', [
                Appointment::STATUS_BOOKED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ])
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw(
                'SUM(CASE WHEN NOT EXISTS ('
                .'SELECT 1 FROM appointments AS prev'
                .' WHERE prev.customer_id = appointments.customer_id'
                .' AND prev.appointment_date < appointments.appointment_date'
                .' AND prev.deleted_at IS NULL'
                .') THEN 1 ELSE 0 END) AS new_visits'
            );
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
            'laboratory' => 'Lab Tests',
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
     | Pharmacy inventory
     |===================================================================== */

    /** How far ahead "expiring" looks — the span the card's tile names. */
    private const EXPIRY_DAYS = 30;

    /** How many lines the low-stock table carries; it scrolls past five. */
    private const LOW_ROWS = 8;

    /** Categories the stock ring names before the rest become "Others". */
    private const CATEGORIES = 5;

    /**
     * The shelves at a glance: four counts, the lines running low, and the
     * stock on hand split by category.
     *
     * "Low" is the store's own reorder level, the same test the pharmacy's
     * own dashboard uses, and stock counts only while it is usable — active
     * and in date. Out of stock is the bottom of low stock rather than a
     * separate set, so the low tile counts what is running out but not yet
     * gone, and no medicine is counted on both tiles.
     *
     * @return array<string, mixed>
     */
    public function pharmacyInventory(?array $allowed, ?int $branch): array
    {
        $stores = $this->scoped(PharmacyStore::query(), 'location_id', $allowed)
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->with('location:id,name')
            ->get(['id', 'name', 'location_id'])
            ->keyBy('id');

        $panel = [
            'location_id' => $branch,
            'expiry_days' => self::EXPIRY_DAYS,
            'counts' => ['low' => 0, 'expiring' => 0, 'out' => 0, 'medicines' => 0],
            'rows' => [],
            'units' => 0,
            'categories' => [],
        ];

        if ($stores->isEmpty()) {
            return $panel;
        }

        $today = Carbon::today()->toDateString();
        $storeIds = $stores->keys()->all();

        // Every line a store keeps at or under its reorder level — what is
        // left of it in usable stock, emptiest first.
        $short = StoreMedicine::query()
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
            ->havingRaw('COALESCE(SUM(stock.quantity_available), 0) <= store_medicines.reorder_level')
            ->orderByRaw('COALESCE(SUM(stock.quantity_available), 0) ASC')
            ->orderBy('store_medicines.medicine_id')
            ->get();

        $out = $short->filter(fn ($row) => (int) $row->on_hand === 0)->count();

        $panel['counts'] = [
            'low' => $short->count() - $out,
            'expiring' => MedicineBatch::query()
                ->whereIn('pharmacy_store_id', $storeIds)
                ->where('status', MedicineBatch::ACTIVE)
                ->where('quantity_available', '>', 0)
                ->whereDate('expiry_date', '>', $today)
                ->whereDate('expiry_date', '<=', Carbon::today()->addDays(self::EXPIRY_DAYS)->toDateString())
                ->count(),
            'out' => $out,
            'medicines' => StoreMedicine::query()
                ->whereIn('pharmacy_store_id', $storeIds)
                ->where('is_active', true)
                ->distinct()
                ->count('medicine_id'),
        ];

        $lines = $short->take(self::LOW_ROWS);

        $medicines = Medicine::withTrashed()
            ->whereIn('id', $lines->pluck('medicine_id'))
            ->get(['id', 'generic_name', 'brand_name', 'strength'])
            ->keyBy('id');

        // Which branch a line is at matters only when there is more than one.
        $manyBranches = $stores->pluck('location_id')->unique()->count() > 1;

        $panel['rows'] = $lines->map(function ($row) use ($medicines, $stores, $manyBranches) {
            $medicine = $medicines->get($row->medicine_id);
            $store = $stores->get((int) $row->pharmacy_store_id);
            $onHand = (int) $row->on_hand;

            return [
                'key' => $row->pharmacy_store_id.'-'.$row->medicine_id,
                // What is on the strip first, what it is underneath.
                'name' => $medicine?->brand_name ?: ($medicine?->generic_name ?? 'Unknown'),
                'detail' => implode(' · ', array_filter([
                    $medicine?->brand_name ? $medicine->generic_name : null,
                    $medicine?->strength,
                    $manyBranches ? ($store?->location?->name ?? $store?->name) : null,
                ])),
                'stock' => $onHand,
                'min_level' => (int) $row->reorder_level,
                'status' => $onHand === 0 ? 'out' : 'low',
            ];
        })->values()->all();

        // Usable units on the shelves, by the category the medicine is filed
        // under — the biggest few by name, everything else together.
        $byCategory = MedicineBatch::query()
            ->join('medicines', 'medicines.id', '=', 'medicine_batches.medicine_id')
            ->whereIn('medicine_batches.pharmacy_store_id', $storeIds)
            ->where('medicine_batches.status', MedicineBatch::ACTIVE)
            ->where('medicine_batches.quantity_available', '>', 0)
            ->whereDate('medicine_batches.expiry_date', '>', $today)
            ->selectRaw("NULLIF(TRIM(medicines.category), '') AS category")
            ->selectRaw('SUM(medicine_batches.quantity_available) AS units')
            ->groupByRaw("NULLIF(TRIM(medicines.category), '')")
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'units' => (int) $row->units])
            ->sortByDesc('units')
            ->values();

        $units = (int) $byCategory->sum('units');
        $named = $byCategory->whereNotNull('category')->take(self::CATEGORIES);
        $rest = $units - (int) $named->sum('units');

        $slices = $named
            ->map(fn (array $row) => ['key' => Str::slug($row['category']), 'label' => $row['category'], 'units' => $row['units']])
            ->when($rest > 0, fn ($slices) => $slices->push(['key' => 'others', 'label' => 'Others', 'units' => $rest]));

        $panel['units'] = $units;
        $panel['categories'] = $slices
            ->map(fn (array $slice) => [...$slice, 'share' => $units > 0 ? round($slice['units'] / $units * 100, 1) : 0.0])
            ->values()
            ->all();

        return $panel;
    }

    /* =====================================================================
     | Follow-ups
     |===================================================================== */

    /** How far either side of today the follow-up card looks. */
    private const FOLLOW_UP_DAYS = 14;

    /**
     * Patients asked back who have not come yet — the ones overdue first,
     * then today's, then the next fortnight's.
     *
     * Worked out the way the doctor's own list works it out
     * (App\Services\Opd\DoctorRecords::followUps): a follow-up is advice
     * written at a visit, so it falls due on the visit's date plus its days,
     * and it is kept until the patient turns up to any later visit. Network
     * wide rather than one doctor's, at the branches the visit was seen at.
     *
     * `$reasons` is false for somebody who may not read clinical notes: the
     * diagnosis is a clinical record, and the list is still worth having
     * without it.
     *
     * @return array<string, mixed>
     */
    public function followUps(?array $allowed, bool $reasons): array
    {
        $today = Carbon::today();
        $due = '(appointments.appointment_date + consultations.follow_up_days)';

        $rows = Consultation::query()
            ->join('appointments', 'appointments.id', '=', 'consultations.appointment_id')
            ->whereNull('appointments.deleted_at')
            ->whereNotNull('consultations.follow_up_days')
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('appointments.location_id', $allowed ?: [0]))
            ->whereRaw("{$due} BETWEEN ? AND ?", [
                $today->copy()->subDays(self::FOLLOW_UP_DAYS)->toDateString(),
                $today->copy()->addDays(self::FOLLOW_UP_DAYS)->toDateString(),
            ])
            // Not back yet: no later day they actually turned up to.
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM appointments AS later'
                .' WHERE later.customer_id = consultations.customer_id'
                .' AND later.appointment_date > appointments.appointment_date'
                .' AND later.deleted_at IS NULL'
                .' AND later.status NOT IN (?, ?, ?))',
                [Appointment::STATUS_BOOKED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW],
            )
            ->select('consultations.id', 'consultations.customer_id', 'consultations.follow_up_days')
            ->selectRaw('appointments.appointment_date AS seen_on')
            ->selectRaw("{$due} AS due_on")
            ->when($reasons, fn (Builder $q) => $q->addSelect('consultations.diagnoses', 'consultations.chief_complaint'))
            ->with('customer:id,name,code,phone')
            ->orderByRaw("{$due} ASC")
            ->orderBy('consultations.id')
            ->limit(self::ROWS)
            ->get();

        return [
            'rows' => $rows->map(function (Consultation $row) use ($today, $reasons) {
                $dueOn = Carbon::parse($row->due_on);

                return [
                    'id' => $row->id,
                    'customer_id' => $row->customer_id,
                    'name' => $row->customer?->name,
                    'code' => $row->customer?->code,
                    'phone' => $row->customer?->phone,
                    'seen_on' => Carbon::parse($row->seen_on)->toDateString(),
                    'due_on' => $dueOn->toDateString(),
                    // Negative once the day has passed.
                    'due_in' => (int) $today->diffInDays($dueOn, false),
                    'days' => (int) $row->follow_up_days,
                    'reason' => $reasons
                        ? (($row->diagnoses ?? [])[0] ?? ($row->chief_complaint ?: null))
                        : null,
                ];
            })->values()->all(),
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
     * `$branch` narrows it to what happened at one branch, for the dashboard
     * of somebody standing in it; head office reads the whole network.
     *
     * @return list<array<string, mixed>>
     */
    public function branchActivity(?int $branch = null): array
    {
        $entries = ActivityLog::query()
            ->where('actor_type', 'tenant')
            ->when($branch !== null, fn (Builder $q) => $q->where(fn (Builder $here) => $here
                ->where('location_id', $branch)
                // A bill raised by the visit's own workflow is logged with no
                // acting branch; it belongs to the branch the bill is for.
                ->orWhere(fn (Builder $bill) => $bill
                    ->whereNull('location_id')
                    ->where('entity_type', 'Invoice')
                    ->whereIn('entity_id', Invoice::query()->where('location_id', $branch)->select('id')))))
            ->whereIn('entity_type', self::NEWSWORTHY)
            ->where(fn (Builder $q) => $q
                ->where('event', '!=', 'updated')
                ->orWhereIn('entity_type', self::NEWSWORTHY_EDITS))
            ->latest('id')
            ->limit(self::ROWS)
            ->get();

        /*
         * A bill or a sale is logged the moment it is created, before it has
         * a number — the number, the branch and the amount are read off the
         * record itself, so the entry says which bill and for how much.
         */
        $ids = fn (string $type) => $entries->where('entity_type', $type)->pluck('entity_id')->filter()->all();

        $records = [
            'Invoice' => Invoice::query()
                ->whereIn('id', $ids('Invoice'))
                ->get(['id', 'invoice_number AS number', 'location_id', 'total_amount'])
                ->keyBy('id'),
            'PharmacySale' => PharmacySale::query()
                ->whereIn('id', $ids('PharmacySale'))
                ->get(['id', 'sale_number AS number', 'location_id', 'total_amount'])
                ->keyBy('id'),
        ];

        $record = fn (ActivityLog $row) => ($records[$row->entity_type] ?? collect())->get($row->entity_id);

        $branches = Location::withTrashed()
            ->whereIn('id', $entries
                ->map(fn (ActivityLog $row) => $row->location_id ?? $record($row)?->location_id)
                ->filter()
                ->unique())
            ->pluck('name', 'id');

        return $entries->map(function (ActivityLog $row) use ($branches, $record) {
            $bill = $record($row);
            $at = $row->location_id ?? $bill?->location_id;

            return [
                'id' => $row->id,
                'event' => $row->event,
                'entity_type' => $row->entity_type,
                // The sentence alone; the branch goes on the line under it,
                // beside what the record is called.
                'text' => $this->sentence($row->event, $row->entity_type),
                // What the record is called, kept out of the sentence: a
                // name in the middle of one ("New posting Neha Sharma at
                // Gorakhpur added") stops it reading as a sentence.
                'detail' => $row->entity_label ?: $bill?->number,
                'branch' => $at ? ($branches[$at] ?? null) : null,
                'amount' => $bill ? round((float) $bill->total_amount, 2) : null,
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
