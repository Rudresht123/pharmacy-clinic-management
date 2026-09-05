<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Modules\ModuleAccess;
use App\Services\Permissions\Permission;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What one person should see on opening the workspace.
 *
 * Assembled per capability rather than gathered and then filtered. A panel the
 * caller may not see is not computed at all, so it cannot arrive in the
 * response and be hidden by the client — the same rule the rest of the app
 * follows, applied to a screen that would otherwise be tempting to build as
 * "fetch everything and let React decide".
 *
 * Branch-scoped throughout. A branch manager's dashboard is about their
 * branch; the owner's is about the network. Neither is a filter the client
 * chose — it comes from where the person actually works.
 *
 * PLACEHOLDERS. A few figures on this screen belong to modules that do not
 * exist yet — revenue needs billing, retention needs an HR record of leavers.
 * They are built here rather than left out, with `placeholder => true` on every
 * one, and the client marks those visibly as sample. A number nobody can trace
 * is worse than a gap, and an unmarked one gets repeated in a meeting; marked,
 * it shows the shape of the screen without pretending to be a measurement.
 * Search this file for `placeholder` to find everything still to be wired.
 */
class DashboardSummary
{
    public function __construct(
        private readonly Permission $permission,
        private readonly TenantBranchAccess $branches,
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * The panels this dashboard is made of.
     *
     * Declared rather than written into `for()` so that shipping a module means
     * adding an entry here, not editing the assembly below. Each says what it
     * needs and how to build itself:
     *
     *   module      the module it belongs to, or null for something every
     *               organization has. Checked against the entitlement AND the
     *               branch, so the dashboard is not the one screen where a
     *               module gate gets skipped.
     *   capability  what the caller must hold to see it at all.
     *   resolve     how to build it, given the branches they can see.
     *
     * Inventory, billing and the rest arrive as further entries when their
     * modules do. Nothing else in this class changes, and a client that has not
     * been taught the new key simply does not render it.
     *
     * @return list<array{key: string, module: string|null, capability: string|null, resolve: callable}>
     */
    private function panels(Organization $organization, User $user): array
    {
        return [
            [
                'key' => 'headline',
                'module' => null,
                'capability' => null,
                'resolve' => fn (?array $allowed) => $this->headline($organization, $user, $allowed),
            ],
            [
                'key' => 'patients',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => $this->patients($allowed),
            ],
            [
                'key' => 'branches',
                'module' => null,
                'capability' => 'branches.view',
                'resolve' => fn (?array $allowed) => $this->branchHealth($allowed),
            ],
            [
                'key' => 'appointments',
                'module' => 'appointments',
                'capability' => 'appointments.view',
                'resolve' => fn (?array $allowed) => $this->appointments($allowed),
            ],
            [
                /*
                 * A module with no table, model or routes yet. Declared so it
                 * is a panel the permission model knows about — without an
                 * entry here it was not "empty", it was forbidden, and demo
                 * data correctly refused to fill it.
                 */
                'key' => 'departments',
                'module' => null,
                'capability' => 'people.view',
                'resolve' => fn (?array $allowed) => [],
            ],
            [
                'key' => 'insights',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => $this->insights($allowed),
            ],
            [
                'key' => 'plan',
                'module' => null,
                'capability' => null,
                'resolve' => fn (?array $allowed) => $this->plan($organization),
            ],
            [
                'key' => 'activity',
                'module' => null,
                'capability' => 'settings.audit',
                'resolve' => fn (?array $allowed) => $this->activity(),
            ],

            /*
             * Branch-only panels. They answer about one place and one day, so
             * they are computed only when somebody is actually standing in a
             * branch — organization-wide they would either be meaningless or a
             * sum nobody asked for.
             */
            [
                'key' => 'recent_patients',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => $this->recentPatients($allowed),
            ],
            [
                'key' => 'branch_info',
                'module' => null,
                'capability' => 'branches.view',
                'resolve' => fn (?array $allowed) => $this->branchInfo(
                    $this->permission->branchFor($user)
                ),
            ],

            /*
             * Panels whose modules do not exist yet — pharmacy stock, billing,
             * a task list, arrivals by hour.
             *
             * Declared so demo data can fill them: a panel that is not declared
             * is not "empty", it is forbidden, and demo correctly refuses to
             * fill a panel the caller was never entitled to.
             *
             * Their capabilities are PLACEHOLDERS. `customers.view` is what
             * everybody working at a counter holds, so it is the closest true
             * thing until each module ships its own — at which point these
             * entries take the real capability and a real resolver.
             */
            [
                'key' => 'visits',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => ['points' => []],
            ],
            [
                'key' => 'by_type',
                'module' => 'appointments',
                'capability' => 'appointments.view',
                'resolve' => fn (?array $allowed) => [],
            ],
            [
                'key' => 'stock',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => [],
            ],
            [
                'key' => 'revenue',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => ['rows' => [], 'total' => 0],
            ],
            [
                'key' => 'tasks',
                'module' => null,
                'capability' => 'customers.view',
                'resolve' => fn (?array $allowed) => [],
            ],
        ];
    }

    /**
     * The people seen here most recently.
     *
     * @return list<array<string, mixed>>
     */
    private function recentPatients(?array $allowed): array
    {
        return $this->scopeToBranches(Customer::query(), 'registered_location_id', $allowed)
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'city' => $customer->city,
                'joined_at' => $customer->created_at?->toDateString(),
            ])
            ->all();
    }

    /** The branch itself — the card a desk checks a phone number on. */
    private function branchInfo(?int $branchId): ?array
    {
        if ($branchId === null) {
            return null;
        }

        $branch = Location::find($branchId);

        if (! $branch) {
            return null;
        }

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'city' => $branch->city,
            'state' => $branch->state,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'address' => $branch->address,
            'pincode' => $branch->pincode,
        ];
    }

    /**
     * The panel keys this person is entitled to, empty or not.
     *
     * `for()` omits a panel with nothing in it and a panel they may not see
     * alike, which makes those two indistinguishable from outside. Demo data
     * has to tell them apart — filling an empty panel is the point of it, and
     * filling a forbidden one would make a presentation flag into a way around
     * the permission model.
     *
     * @return list<string>
     */
    public function permittedKeys(Organization $organization, User $user): array
    {
        $branch = $this->permission->branchFor($user);
        $keys = [];

        foreach ($this->panels($organization, $user) as $panel) {
            $moduleRunning = $panel['module'] === null
                || $this->permission->hasModule($organization, $panel['module'], $branch);

            $permitted = $panel['capability'] === null
                || $this->permission->allows($organization, $user, $panel['capability']);

            if ($moduleRunning && $permitted) {
                $keys[] = $panel['key'];
            }
        }

        return $keys;
    }

    /** @return array<string, mixed> */
    public function for(Organization $organization, User $user): array
    {
        /*
         * Null means every branch — the owner, and head office. A list means
         * exactly those. The distinction matters more than it looks: `[]` is
         * "no branches", which is a real and different answer from "all".
         */
        $allowed = $this->branches->allowed($user);
        $branch = $this->permission->branchFor($user);

        $summary = [
            /*
             * Which dashboard this is. Somebody working AT a branch gets that
             * branch's day — arrivals, the queue, what is running low. Somebody
             * organization-wide gets the network — how many branches, how the
             * book is growing. Two different questions, so two different sets
             * of panels rather than one set with numbers that mean less the
             * further out you stand.
             */
            'context' => $branch === null ? 'organization' : 'branch',

            'scope' => [
                'branches' => $allowed === null ? null : $allowed,
                'label' => $this->scopeLabel($allowed),
                'branch_id' => $branch,
                'branch_name' => $branch ? Location::find($branch)?->name : null,
                'city' => $branch ? Location::find($branch)?->city : null,
            ],
        ];

        foreach ($this->panels($organization, $user) as $panel) {
            $moduleRunning = $panel['module'] === null
                || $this->permission->hasModule($organization, $panel['module'], $branch);

            if (! $moduleRunning) {
                continue;
            }

            $permitted = $panel['capability'] === null
                || $this->permission->allows($organization, $user, $panel['capability']);

            if (! $permitted) {
                continue;
            }

            // Built only once it is known to be visible: a panel the caller
            // may not see costs no queries, and cannot reach the response to
            // be hidden by the client.
            $summary[$panel['key']] = ($panel['resolve'])($allowed);
        }

        return $summary;
    }

    private function scopeLabel(?array $allowed): string
    {
        if ($allowed === null) {
            return 'Across every branch';
        }

        if ($allowed === []) {
            return 'Organization-wide';
        }

        $names = Location::whereIn('id', $allowed)->orderBy('name')->pluck('name');

        return $names->count() === 1
            ? (string) $names->first()
            : $names->count().' branches';
    }

    /* ---------------------------------------------------------------------
     | The four figures across the top
     |------------------------------------------------------------------- */

    /**
     * Each with its month-on-month change.
     *
     * Only the counts the caller may see are included, so somebody holding
     * only patients gets one card rather than four, three of which would read
     * zero — and a zero that really means "you cannot see this" is the worst
     * number a dashboard can print.
     *
     * @return list<array<string, mixed>>
     */
    private function headline(Organization $organization, User $user, ?array $allowed): array
    {
        $can = fn (string $capability) => $this->permission->allows($organization, $user, $capability);

        $cards = [];

        if ($can('branches.view')) {
            $query = fn () => $allowed === null
                ? Location::query()
                : Location::whereIn('id', $allowed ?: [0]);

            $cards[] = $this->card('branches', 'Branches', $query(), 'created_at');
        }

        if ($can('people.view')) {
            $query = fn () => $this->scopeToMembership(
                User::query()->where('id', '!=', $user->getKey()),
                $allowed,
            );

            $cards[] = $this->card('staff', 'Staff', $query(), 'created_at');
        }

        if ($can('customers.view')) {
            $query = fn () => $this->scopeToBranches(
                Customer::query(),
                'registered_location_id',
                $allowed,
            );

            $cards[] = $this->card('patients', 'Patients', $query(), 'created_at');
        }

        if ($this->permission->hasModule($organization, 'appointments', $this->permission->branchFor($user))
            && $can('appointments.view')) {
            $cards[] = $this->appointmentCard($allowed);
        }

        return $cards;
    }

    /**
     * A total, plus how many arrived this month and how that compares.
     *
     * @return array<string, mixed>
     */
    private function card(string $key, string $label, Builder $query, string $column): array
    {
        $thisMonth = (clone $query)
            ->where($column, '>=', now()->startOfMonth())
            ->count();

        $lastMonth = (clone $query)
            ->whereBetween($column, [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ])
            ->count();

        return [
            'key' => $key,
            'label' => $label,
            'total' => (clone $query)->count(),
            'this_month' => $thisMonth,
            'change' => $this->change($thisMonth, $lastMonth),
        ];
    }

    /** @return array<string, mixed> */
    private function appointmentCard(?array $allowed): array
    {
        $count = fn (Carbon $from, Carbon $to) => $this->scopeToBranches(
            Appointment::query(),
            'location_id',
            $allowed,
        )->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])->count();

        $thisMonth = $count(now()->startOfMonth(), now()->endOfMonth());
        $lastMonth = $count(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );

        return [
            'key' => 'appointments',
            'label' => 'Appointments',
            'total' => $thisMonth,
            'this_month' => $thisMonth,
            'change' => $this->change($thisMonth, $lastMonth),
        ];
    }

    /**
     * Null when there is nothing to compare against.
     *
     * A rise from zero has no percentage, and printing one — 100%, or worse
     * ∞ — is a number somebody would repeat.
     */
    private function change(int $now, int $before): ?int
    {
        return $before > 0 ? (int) round((($now - $before) / $before) * 100) : null;
    }

    /* ---------------------------------------------------------------------
     | Patients
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function patients(?array $allowed): array
    {
        $scoped = fn () => $this->scopeToBranches(Customer::query(), 'registered_location_id', $allowed);

        $months = collect(range(5, 0))->map(function (int $back) use ($scoped) {
            $month = now()->startOfMonth()->subMonthsNoOverflow($back);

            return [
                'month' => $month->format('M'),
                'total' => (clone $scoped())
                    ->whereBetween('created_at', [$month, (clone $month)->endOfMonth()])
                    ->count(),
            ];
        });

        return [
            'total' => $scoped()->count(),
            'active' => $scoped()->where('is_active', true)->count(),
            'by_month' => $months->values()->all(),
            'by_branch' => $this->patientsByBranch($allowed),
        ];
    }

    /**
     * Where the book came from — one row per branch, biggest first.
     *
     * "Not recorded" is kept as its own row rather than dropped. Patients on
     * file before the branch was captured, and walk-ins, are a real part of
     * the total, and a chart whose slices do not sum to the headline is worse
     * than one with an honest grey slice in it.
     *
     * @return list<array<string, mixed>>
     */
    private function patientsByBranch(?array $allowed): array
    {
        $rows = $this->scopeToBranches(Customer::query(), 'registered_location_id', $allowed)
            ->selectRaw('registered_location_id, COUNT(*) AS total')
            ->groupBy('registered_location_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Location::whereIn(
            'id',
            $rows->pluck('registered_location_id')->filter()
        )->pluck('name', 'id');

        return $rows
            ->map(fn ($row) => [
                'location_id' => $row->registered_location_id
                    ? (int) $row->registered_location_id
                    : null,
                'label' => $row->registered_location_id
                    ? ($names[$row->registered_location_id] ?? 'Unknown')
                    : 'Not recorded',
                'total' => (int) $row->total,
                'muted' => $row->registered_location_id === null,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Branches
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function branchHealth(?array $allowed): array
    {
        $query = $allowed === null
            ? Location::query()
            : Location::whereIn('id', $allowed ?: [0]);

        $branches = (clone $query)->orderBy('name')->get();

        $staffCounts = $branches->isEmpty()
            ? collect()
            : \App\Models\Tenant\BranchMembership::query()
                ->selectRaw('location_id, COUNT(DISTINCT user_id) AS total')
                ->whereIn('location_id', $branches->pluck('id'))
                ->groupBy('location_id')
                ->pluck('total', 'location_id');

        $patientCounts = $branches->isEmpty()
            ? collect()
            : Customer::query()
                ->selectRaw('registered_location_id, COUNT(*) AS total')
                ->whereIn('registered_location_id', $branches->pluck('id'))
                ->groupBy('registered_location_id')
                ->pluck('total', 'registered_location_id');

        $expiring = $branches
            ->filter(fn (Location $branch) => $branch->drug_license_expiry_date !== null)
            ->filter(fn (Location $branch) => $branch->drug_license_expiry_date
                ->startOfDay()
                ->lte(now()->startOfDay()->addDays(60)))
            ->sortBy('drug_license_expiry_date')
            ->take(5)
            ->map(fn (Location $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'expires_at' => $branch->drug_license_expiry_date->toDateString(),
                'expired' => $branch->hasExpiredLicence(),
            ])
            ->values()
            ->all();

        return [
            'total' => $branches->count(),
            'active' => $branches->where('is_active', true)->count(),

            'rows' => $branches->take(6)->map(fn (Location $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
                'state' => $branch->state,
                'staff' => (int) ($staffCounts[$branch->id] ?? 0),
                'patients' => (int) ($patientCounts[$branch->id] ?? 0),
                'is_active' => $branch->is_active,
            ])->values()->all(),

            'licences_needing_attention' => $expiring,
        ];
    }

    /* ---------------------------------------------------------------------
     | Appointments
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function appointments(?array $allowed): array
    {
        $today = Carbon::today();

        $todayQuery = fn () => $this->scopeToBranches(
            Appointment::query()->whereDate('appointment_date', $today->toDateString()),
            'location_id',
            $allowed,
        );

        $waiting = (clone $todayQuery())->where('status', Appointment::STATUS_CHECKED_IN)->count();

        $longestWait = (clone $todayQuery())
            ->where('status', Appointment::STATUS_CHECKED_IN)
            ->whereNotNull('checked_in_at')
            ->min('checked_in_at');

        return [
            'date' => $today->toDateString(),
            'waiting' => $waiting,
            'in_consultation' => (clone $todayQuery())->where('status', Appointment::STATUS_IN_CONSULTATION)->count(),
            'seen' => (clone $todayQuery())->where('status', Appointment::STATUS_COMPLETED)->count(),
            'expected' => (clone $todayQuery())->where('status', Appointment::STATUS_BOOKED)->count(),

            /*
             * How long the person waiting longest has been waiting, not an
             * average over the day. A desk wants to know whether anybody is
             * being kept, and an average cannot say.
             */
            'longest_wait_minutes' => $longestWait
                ? (int) Carbon::parse($longestWait)->diffInMinutes(now())
                : null,

            'trend' => $this->appointmentTrend($allowed),
            'upcoming' => $this->upcoming($allowed),
        ];
    }

    /**
     * Day by day this month, against the same days last month.
     *
     * Aligned by day number rather than by weekday: "the 14th against the
     * 14th" is what somebody means by comparing months, even though it walks
     * across different weekdays.
     *
     * @return array<string, mixed>
     */
    private function appointmentTrend(?array $allowed): array
    {
        $daily = function (Carbon $start, Carbon $end) use ($allowed) {
            return $this->scopeToBranches(Appointment::query(), 'location_id', $allowed)
                ->selectRaw('appointment_date, COUNT(*) AS total')
                ->whereBetween('appointment_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('appointment_date')
                ->pluck('total', 'appointment_date');
        };

        $thisStart = now()->startOfMonth();
        $lastStart = now()->subMonthNoOverflow()->startOfMonth();

        $thisCounts = $daily($thisStart, now()->endOfMonth());
        $lastCounts = $daily($lastStart, now()->subMonthNoOverflow()->endOfMonth());

        $days = (int) now()->daysInMonth;

        $current = [];
        $previous = [];

        for ($day = 1; $day <= $days; $day++) {
            $date = (clone $thisStart)->addDays($day - 1);
            $label = $date->format('j M');

            $current[] = [
                'label' => $day % 7 === 1 ? $label : '',
                'title' => $date->format('j M Y'),
                'value' => (int) ($thisCounts[$date->toDateString()] ?? 0),
            ];

            $comparable = (clone $lastStart)->addDays($day - 1);

            $previous[] = [
                'label' => '',
                'title' => $comparable->format('j M Y'),
                'value' => $comparable->month === $lastStart->month
                    ? (int) ($lastCounts[$comparable->toDateString()] ?? 0)
                    : 0,
            ];
        }

        return ['current' => $current, 'previous' => $previous];
    }

    /**
     * Who is coming, soonest first.
     *
     * Today's still-expected bookings only. Yesterday's are history and next
     * week's are not what somebody standing at a desk needs.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(?array $allowed): array
    {
        return $this->scopeToBranches(
            Appointment::query()->whereDate('appointment_date', Carbon::today()->toDateString()),
            'location_id',
            $allowed,
        )
            ->where('status', Appointment::STATUS_BOOKED)
            ->with(['customer:id,name', 'location:id,name'])
            ->orderByRaw('slot_at NULLS LAST')
            ->limit(6)
            ->get()
            ->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'time' => $appointment->slot_at,
                'patient' => $appointment->customer?->name ?? 'Unknown',
                'branch' => $appointment->location?->name,
                'type' => $appointment->type,
            ])
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Insights
     |------------------------------------------------------------------- */

    /**
     * Four figures about the month.
     *
     * Two are measured and two are PLACEHOLDERS — revenue needs a billing
     * module and retention needs an HR record of leavers, and neither exists.
     * They carry `placeholder => true` so the client can mark them as sample
     * rather than let somebody read them as a measurement.
     *
     * @return list<array<string, mixed>>
     */
    private function insights(?array $allowed): array
    {
        $newPatients = $this->scopeToBranches(Customer::query(), 'registered_location_id', $allowed)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $completed = $this->scopeToBranches(Appointment::query(), 'location_id', $allowed)
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('appointment_date', [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ])
            ->count();

        return [
            [
                'key' => 'revenue',
                'label' => 'Revenue this month',
                'value' => '₹0',
                'icon' => 'ti ti-currency-rupee',
                // Wire to the billing module's takings when it ships.
                'placeholder' => true,
            ],
            [
                'key' => 'new_patients',
                'label' => 'New patients',
                'value' => (string) $newPatients,
                'icon' => 'ti ti-user-plus',
                'placeholder' => false,
            ],
            [
                'key' => 'completed',
                'label' => 'Completed appointments',
                'value' => (string) $completed,
                'icon' => 'ti ti-circle-check',
                'placeholder' => false,
            ],
            [
                'key' => 'retention',
                'label' => 'Staff retention',
                'value' => '—',
                'icon' => 'ti ti-shield-check',
                // Needs a record of leavers, which nothing keeps yet.
                'placeholder' => true,
            ],
        ];
    }

    /**
     * What this organization has been sold.
     *
     * The reference design had a subscription plan here. There are no plans —
     * a super admin assigns modules one at a time — so this says the true
     * thing in the same place rather than inventing a tier.
     *
     * @return array<string, mixed>
     */
    private function plan(Organization $organization): array
    {
        $enabled = $this->modules->enabled($organization);

        return [
            'modules' => count($enabled),
            'names' => $enabled,
        ];
    }

    /* ---------------------------------------------------------------------
     | Activity
     |------------------------------------------------------------------- */

    /** @return list<array<string, mixed>> */
    private function activity(): array
    {
        return ActivityLog::query()
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (ActivityLog $row) => [
                'id' => $row->id,
                'action' => $row->action,
                'entity_type' => $row->entity_type,
                'entity_label' => $row->entity_label,
                'actor_name' => $row->actor_name,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /* ---------------------------------------------------------------------
     | Scoping
     |------------------------------------------------------------------- */

    /**
     * Narrow a query to the branches somebody may see.
     *
     * Null is every branch. An empty list is NO branch — head office, whose
     * work is organization-wide — and `whereIn` on `[0]` is how that stays a
     * refusal rather than quietly matching everything.
     */
    private function scopeToBranches(Builder $query, string $column, ?array $allowed): Builder
    {
        if ($allowed === null) {
            return $query;
        }

        return $query->whereIn($column, $allowed ?: [0]);
    }

    /** The same, for people — who belong to a branch through a membership. */
    private function scopeToMembership(Builder $query, ?array $allowed): Builder
    {
        if ($allowed === null) {
            return $query;
        }

        return $query->whereHas(
            'memberships',
            fn (Builder $membership) => $membership->whereIn('location_id', $allowed ?: [0])
        );
    }
}
