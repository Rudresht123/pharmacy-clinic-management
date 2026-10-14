<?php

namespace App\Services\Tenant;

use Illuminate\Support\Carbon;

/**
 * The dashboard, filled in with sample figures.
 *
 * EVERY NUMBER IN THIS FILE IS INVENTED. It exists so the whole screen can be
 * seen and judged before the modules behind it ship — billing, reports and an
 * HR record of leavers do not exist, and the panels that need them would
 * otherwise be empty boxes nobody could evaluate. Departments shipped and
 * left this file: `departments()` below now mirrors DashboardSummary's real
 * shape rather than standing in for it.
 *
 * HOW TO TURN IT OFF
 *
 *   config/hms.php → 'dashboard_demo' => false
 *   or set HMS_DASHBOARD_DEMO=false in .env
 *
 * With it off, DashboardController falls straight through to DashboardSummary,
 * which is real, branch-scoped and capability-gated and has been all along.
 * Nothing else has to change — this class is never referenced from anywhere
 * but that one branch in the controller.
 *
 * HOW TO RETIRE IT PIECE BY PIECE
 *
 * The likelier path than one switch. Each panel below is its own method, so a
 * module shipping means deleting that method and letting DashboardSummary's
 * version answer instead. `mergeReal()` already prefers anything real that
 * arrives, so a panel stops being sample the moment the real one exists.
 *
 * Kept out of DashboardSummary deliberately. Sample data threaded through the
 * real service is how a fake number outlives the reason for it — here it is a
 * file somebody can delete.
 */
class DemoDashboardData
{
    /**
     * The sample payload, for every panel the caller is entitled to.
     *
     * Demo WINS over real data here. An earlier version preferred anything
     * real, which sounded careful and made the screen useless for its one
     * purpose: an organization with two branches and two patients showed two,
     * so the layout could never be judged and a demonstration showed a nearly
     * empty product. Demo mode is a mode, not a fallback.
     *
     * ONLY panels the caller is entitled to. A presentation flag must never
     * become a way around the permission model — without `$permitted` this
     * filled in every panel, including ones the person had no capability for,
     * and demo mode quietly widened access.
     *
     * @param  array<string, mixed>  $real  what DashboardSummary worked out
     * @param  list<string>  $permitted  the panels they may see, empty or not
     * @return array<string, mixed>
     */
    public function mergeInto(array $real, array $permitted): array
    {
        /*
         * A branch dashboard is a different screen, not a narrower version of
         * this one — see DemoBranchDashboardData. Standing in a clinic the
         * questions are who is arriving and what is running low; neither means
         * anything summed across five branches.
         */
        $demo = ($real['context'] ?? 'organization') === 'branch'
            ? app(DemoBranchDashboardData::class)->payload()
            : $this->payload();

        foreach ($demo as $key => $panel) {
            // `scope` is always real — it says which branches the figures are
            // about, and inventing that would misdescribe the real panels too.
            if ($key === 'scope' || ! in_array($key, $permitted, true)) {
                continue;
            }

            $real[$key] = $panel;
        }

        /*
         * Deliberately NOT flagged panel by panel on screen. Demo is a
         * whole-screen mode, announced by the config flag and this file, not
         * by a badge on every figure — a screen covered in "sample" chips is
         * unreadable, and the honesty belongs where somebody maintaining it
         * will look rather than where an audience will.
         */
        $real['demo'] = true;

        return $real;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'headline' => $this->headline(),
            'patients' => $this->patients(),
            'branches' => $this->branches(),
            'appointments' => $this->appointments(),
            'departments' => $this->departments(),
            'insights' => $this->insights(),
            'plan' => $this->plan(),
            'activity' => $this->activity(),

            // The network view's own panels.
            'overview' => $this->overview(),
            'kpis' => $this->kpis(),
            'revenue_overview' => $this->revenueOverview('monthly', null),
            'branch_performance' => $this->branchPerformance(),
            'visit_trends' => $this->visitTrends(null),
            'top_services' => $this->topServices('this_month'),
            'stock_alerts' => $this->stockAlerts(),
            'profit_loss' => $this->profitLoss('this_year'),
            'branch_activity' => $this->branchActivity(),
        ];
    }

    /**
     * One card's sample figures, re-asked with its own filter.
     *
     * Without this, touching a filter on a demo screen would swap invented
     * figures for real ones mid-demonstration. Null for a panel there is no
     * sample of, which lets the real answer through.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function panel(string $panel, array $options): ?array
    {
        return match ($panel) {
            'revenue_overview' => $this->revenueOverview(
                $options['granularity'] ?? 'monthly',
                $options['location_id'] ?? null,
            ),
            'visit_trends' => $this->visitTrends($options['location_id'] ?? null),
            'top_services' => $this->topServices($options['period'] ?? 'this_month'),
            'profit_loss' => $this->profitLoss($options['period'] ?? 'this_year'),
            default => null,
        };
    }

    /* ---------------------------------------------------------------------
     | The network view
     |------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function overview(): array
    {
        return ['since' => '2024-01-15', 'branches' => 4, 'doctors' => 28, 'staff' => 54];
    }

    /**
     * A month that climbs, with a weekly wobble — the shape a sparkline is
     * for. Deterministic, so the screen looks the same on every load.
     *
     * @return list<float>
     */
    private function climb(float $start, float $end, int $points, float $wobble = 0.12): array
    {
        $out = [];

        for ($i = 0; $i < $points; $i++) {
            $base = $start + (($end - $start) * $i / max($points - 1, 1));
            $out[] = round($base * (1 + $wobble * sin($i * 1.7)), 2);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function kpis(): array
    {
        $days = (int) now()->daysInMonth;

        return [
            ['key' => 'patients', 'label' => 'Total Patients', 'value' => 12842, 'change' => 18, 'series' => $this->climb(30, 62, $days)],
            ['key' => 'appointments', 'label' => 'Total Appointments', 'value' => 4521, 'change' => 12, 'series' => $this->climb(110, 180, $days)],
            ['key' => 'revenue', 'label' => 'Total Revenue', 'value' => 874320, 'money' => true, 'change' => 15, 'series' => $this->climb(21000, 36000, $days, 0.2)],
            ['key' => 'purchases', 'label' => 'Total Purchases', 'value' => 412560, 'money' => true, 'change' => 8, 'series' => $this->climb(9000, 17000, $days, 0.25)],
            ['key' => 'profit', 'label' => 'Net Profit', 'value' => 461760, 'money' => true, 'change' => 22, 'hint' => 'Revenue less stock purchases', 'series' => $this->climb(11000, 20000, $days, 0.18)],
        ];
    }

    /** @return array<string, mixed> */
    private function revenueOverview(string $granularity, ?int $branch): array
    {
        $end = now();

        [$count, $label, $title, $step] = match ($granularity) {
            'daily' => [
                (int) $end->daysInMonth,
                fn ($d) => $d->format('j M'),
                fn ($d) => $d->format('j M Y'),
                fn ($d, $i) => $d->copy()->startOfMonth()->addDays($i),
            ],
            'weekly' => [
                12,
                fn ($d) => $d->format('j M'),
                fn ($d) => 'Week of '.$d->format('j M Y'),
                fn ($d, $i) => $d->copy()->startOfWeek()->subWeeks(11 - $i),
            ],
            default => [
                12,
                fn ($d) => $d->format('M'),
                fn ($d) => $d->format('M Y'),
                fn ($d, $i) => $d->copy()->startOfMonth()->subMonthsNoOverflow(11 - $i),
            ],
        };

        // A single branch is a share of the network, not a different shape.
        $scale = ($branch === null ? 1 : 0.38) * match ($granularity) {
            'daily' => 1 / 30,
            'weekly' => 1 / 4.3,
            default => 1,
        };

        // December of the reference: OPD 62,430 · Pharmacy 78,560 · Lab
        // 28,450 · Procedures 31,900, reached by a steady climb.
        $series = [];

        for ($i = 0; $i < $count; $i++) {
            $at = $step($end, $i);
            $growth = (0.14 + 0.86 * ($i / max($count - 1, 1))) * (1 + 0.08 * sin($i * 2.1)) * $scale;

            $point = [
                'key' => $granularity === 'monthly' ? $at->format('Y-m') : $at->toDateString(),
                'label' => $label($at),
                'title' => $title($at),
                'opd' => round(62430 * $growth, 2),
                'pharmacy' => round(78560 * $growth, 2),
                'laboratory' => round(28450 * $growth, 2),
                'procedures' => round(31900 * $growth, 2),
            ];

            $point['total'] = round($point['opd'] + $point['pharmacy'] + $point['laboratory'] + $point['procedures'], 2);
            $series[] = $point;
        }

        return ['granularity' => $granularity, 'location_id' => $branch, 'series' => $series];
    }

    /** @return array<string, mixed> */
    private function branchPerformance(): array
    {
        return [
            'total' => 4,
            'rows' => [
                ['id' => 1, 'name' => 'Main Branch (City)', 'city' => 'Gurugram', 'is_active' => true, 'patients' => 3842, 'appointments' => 1521, 'revenue' => 341220, 'profit' => 182450, 'growth' => 18],
                ['id' => 2, 'name' => 'East Branch', 'city' => 'Noida', 'is_active' => true, 'patients' => 2846, 'appointments' => 1042, 'revenue' => 198560, 'profit' => 104320, 'growth' => 12],
                ['id' => 3, 'name' => 'West Branch', 'city' => 'Delhi', 'is_active' => true, 'patients' => 2412, 'appointments' => 856, 'revenue' => 164320, 'profit' => 86540, 'growth' => 8],
                ['id' => 4, 'name' => 'North Branch', 'city' => 'Faridabad', 'is_active' => true, 'patients' => 1742, 'appointments' => 612, 'revenue' => 128440, 'profit' => 68450, 'growth' => 15],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function visitTrends(?int $branch): array
    {
        $start = now()->startOfMonth();
        $scale = $branch === null ? 1 : 0.4;
        $series = [];

        for ($day = 0; $day < (int) $start->daysInMonth; $day++) {
            $date = $start->copy()->addDays($day);
            $sunday = $date->dayOfWeek === Carbon::SUNDAY;

            $series[] = [
                'key' => $date->toDateString(),
                'label' => $date->format('j M'),
                'title' => $date->format('j M Y'),
                'new' => (int) round(($sunday ? 22 : 48 + (($day * 7) % 30)) * $scale),
                'returning' => (int) round(($sunday ? 30 : 64 + (($day * 11) % 52)) * $scale),
            ];
        }

        return [
            'granularity' => 'day',
            'location_id' => $branch,
            'series' => $series,
            'new' => array_sum(array_column($series, 'new')),
            'returning' => array_sum(array_column($series, 'returning')),
        ];
    }

    /** @return array<string, mixed> */
    private function topServices(string $period): array
    {
        $scale = match ($period) {
            'last_month' => 0.87,
            'this_year' => 8.26,
            default => 1,
        };

        $rows = [
            ['key' => 'consultation', 'label' => 'Consultation', 'amount' => 279780],
            ['key' => 'pharmacy', 'label' => 'Pharmacy Sales', 'amount' => 244810],
            ['key' => 'laboratory', 'label' => 'Laboratory Tests', 'amount' => 157380],
            ['key' => 'procedures', 'label' => 'Procedures', 'amount' => 104920],
            ['key' => 'others', 'label' => 'Others', 'amount' => 87430],
        ];

        $total = array_sum(array_column($rows, 'amount')) * $scale;

        return [
            'period' => $period,
            'total' => round($total, 2),
            'rows' => array_map(fn (array $row) => [
                ...$row,
                'amount' => round($row['amount'] * $scale, 2),
                'share' => round(($row['amount'] * $scale / $total) * 100, 1),
            ], $rows),
        ];
    }

    /** @return array<string, mixed> */
    private function stockAlerts(): array
    {
        return [
            'counts' => ['expired' => 1, 'out' => 0, 'low' => 9],
            'rows' => [
                ['key' => 'demo-1', 'name' => 'Paracetamol 500mg', 'branch' => 'Main Branch (City)', 'quantity' => 12, 'unit' => 'strips', 'status' => 'low', 'expiry_date' => null],
                ['key' => 'demo-2', 'name' => 'Amoxicillin 250mg', 'branch' => 'East Branch', 'quantity' => 5, 'unit' => 'strips', 'status' => 'low', 'expiry_date' => null],
                ['key' => 'demo-3', 'name' => 'Cetirizine 10mg', 'branch' => 'West Branch', 'quantity' => 18, 'unit' => 'strips', 'status' => 'expired', 'expiry_date' => now()->subDays(15)->toDateString()],
                ['key' => 'demo-4', 'name' => 'Vitamin D3', 'branch' => 'North Branch', 'quantity' => 8, 'unit' => 'strips', 'status' => 'low', 'expiry_date' => null],
                ['key' => 'demo-5', 'name' => 'ORS Powder', 'branch' => 'Main Branch (City)', 'quantity' => 3, 'unit' => 'packs', 'status' => 'low', 'expiry_date' => null],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function profitLoss(string $period): array
    {
        $months = $period === 'last_year' ? 12 : (int) now()->month;
        $scale = $period === 'last_year' ? 0.82 : 1;
        $year = $period === 'last_year' ? now()->subYear()->startOfYear() : now()->startOfYear();

        return [
            'period' => $period,
            'months' => array_map(function (int $i) use ($year) {
                $month = $year->copy()->addMonthsNoOverflow($i);

                return ['key' => $month->format('Y-m'), 'label' => $month->format('M'), 'title' => $month->format('M Y')];
            }, range(0, $months - 1)),
            'revenue' => ['value' => round(7218450 * $scale, 2), 'change' => 18, 'series' => $this->climb(380000, 760000, $months, 0.1)],
            'expenses' => ['value' => round(3824680 * $scale, 2), 'change' => 6, 'series' => $this->climb(260000, 360000, $months, 0.14)],
            'profit' => ['value' => round(3393770 * $scale, 2), 'change' => 26, 'series' => $this->climb(120000, 400000, $months, 0.12)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function branchActivity(): array
    {
        return [
            ['id' => 1, 'event' => 'milestone', 'entity_type' => 'Invoice', 'text' => 'Main Branch crossed ₹3L revenue this month', 'detail' => null, 'branch' => 'Main Branch (City)', 'actor_name' => null, 'created_at' => now()->subHours(2)->toIso8601String()],
            ['id' => 2, 'event' => 'created', 'entity_type' => 'Doctor', 'text' => 'New doctor added at East Branch', 'detail' => null, 'branch' => 'East Branch', 'actor_name' => null, 'created_at' => now()->subHours(5)->toIso8601String()],
            ['id' => 3, 'event' => 'created', 'entity_type' => 'StockInward', 'text' => 'North Branch pharmacy stock updated', 'detail' => null, 'branch' => 'North Branch', 'actor_name' => null, 'created_at' => now()->subDay()->toIso8601String()],
            ['id' => 4, 'event' => 'milestone', 'entity_type' => 'Appointment', 'text' => 'West Branch completed 500+ appointments', 'detail' => null, 'branch' => 'West Branch', 'actor_name' => null, 'created_at' => now()->subDay()->subHours(3)->toIso8601String()],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function headline(): array
    {
        return [
            ['key' => 'branches', 'label' => 'Total Branches', 'total' => 5, 'this_month' => 1, 'change' => 25],
            ['key' => 'staff', 'label' => 'Total Staff', 'total' => 48, 'this_month' => 5, 'change' => 12],
            ['key' => 'patients', 'label' => 'Total Patients', 'total' => 3421, 'this_month' => 342, 'change' => 18],
            ['key' => 'appointments', 'label' => 'Total Appointments', 'total' => 1286, 'this_month' => 1286, 'change' => 22],
        ];
    }

    /** @return array<string, mixed> */
    private function patients(): array
    {
        return [
            'total' => 3421,
            'active' => 3180,
            'by_month' => [
                ['month' => 'Apr', 'total' => 214],
                ['month' => 'May', 'total' => 268],
                ['month' => 'Jun', 'total' => 241],
                ['month' => 'Jul', 'total' => 302],
                ['month' => 'Aug', 'total' => 289],
                ['month' => 'Sep', 'total' => 342],
            ],
            'by_branch' => [
                ['location_id' => 1, 'label' => 'Central Clinic', 'total' => 1125, 'muted' => false],
                ['location_id' => 2, 'label' => 'North Branch', 'total' => 820, 'muted' => false],
                ['location_id' => 3, 'label' => 'East Branch', 'total' => 640, 'muted' => false],
                ['location_id' => 4, 'label' => 'West Branch', 'total' => 480, 'muted' => false],
                ['location_id' => 5, 'label' => 'South Branch', 'total' => 356, 'muted' => false],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function branches(): array
    {
        return [
            'total' => 5,
            'active' => 5,
            'rows' => [
                ['id' => 1, 'name' => 'Central Clinic', 'city' => 'Lucknow', 'state' => 'UP', 'staff' => 12, 'patients' => 1125, 'is_active' => true, 'is_primary' => true],
                ['id' => 2, 'name' => 'North Branch', 'city' => 'Kanpur', 'state' => 'UP', 'staff' => 10, 'patients' => 820, 'is_active' => true, 'is_primary' => false],
                ['id' => 3, 'name' => 'East Branch', 'city' => 'Varanasi', 'state' => 'UP', 'staff' => 9, 'patients' => 640, 'is_active' => true, 'is_primary' => false],
                ['id' => 4, 'name' => 'West Branch', 'city' => 'Prayagraj', 'state' => 'UP', 'staff' => 8, 'patients' => 480, 'is_active' => true, 'is_primary' => false],
                ['id' => 5, 'name' => 'South Branch', 'city' => 'Gorakhpur', 'state' => 'UP', 'staff' => 9, 'patients' => 356, 'is_active' => true, 'is_primary' => false],
            ],
            'licences_needing_attention' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function appointments(): array
    {
        $start = now()->startOfMonth();
        $days = (int) now()->daysInMonth;

        // A shape that reads as a month of a working clinic rather than noise:
        // a weekly rhythm with quieter Sundays.
        $current = [];
        $previous = [];

        for ($day = 1; $day <= $days; $day++) {
            $date = (clone $start)->addDays($day - 1);
            $sunday = $date->dayOfWeek === Carbon::SUNDAY;

            $current[] = [
                'label' => $day % 7 === 1 ? $date->format('j M') : '',
                'title' => $date->format('j M Y'),
                'value' => $sunday ? 18 + ($day % 5) : 44 + (($day * 7) % 34),
            ];

            $previous[] = [
                'label' => '',
                'title' => (clone $start)->subMonthNoOverflow()->addDays($day - 1)->format('j M Y'),
                'value' => $sunday ? 12 + ($day % 4) : 32 + (($day * 5) % 26),
            ];
        }

        return [
            'date' => now()->toDateString(),
            'waiting' => 7,
            'in_consultation' => 3,
            'seen' => 41,
            'expected' => 12,
            'longest_wait_minutes' => 34,
            'trend' => ['current' => $current, 'previous' => $previous],
            'upcoming' => [
                ['id' => 1, 'time' => '09:00', 'patient' => 'Sunita Verma', 'branch' => 'Central Clinic', 'type' => 'consultation'],
                ['id' => 2, 'time' => '10:30', 'patient' => 'Rajesh Kumar', 'branch' => 'North Branch', 'type' => 'follow_up'],
                ['id' => 3, 'time' => '11:00', 'patient' => 'Neha Sharma', 'branch' => 'East Branch', 'type' => 'vaccination'],
                ['id' => 4, 'time' => '12:30', 'patient' => 'Amit Singh', 'branch' => 'Central Clinic', 'type' => 'consultation'],
                ['id' => 5, 'time' => '14:00', 'patient' => 'Pooja Yadav', 'branch' => 'West Branch', 'type' => 'checkup'],
            ],
        ];
    }

    /**
     * Departments, in the shape DashboardSummary's real panel now sends —
     * kept in step with it so demo mode fills the same columns rather than
     * ones the real card does not have.
     *
     * @return list<array<string, mixed>>
     */
    private function departments(): array
    {
        return [
            ['id' => 1, 'name' => 'General Medicine', 'code' => 'GM', 'doctors_count' => 6, 'staff_count' => 14, 'children_count' => 0],
            ['id' => 2, 'name' => 'Paediatrics', 'code' => null, 'doctors_count' => 3, 'staff_count' => 9, 'children_count' => 0],
            ['id' => 3, 'name' => 'Cardiology', 'code' => 'CARD', 'doctors_count' => 4, 'staff_count' => 11, 'children_count' => 1],
            ['id' => 4, 'name' => 'Diagnostics', 'code' => null, 'doctors_count' => 2, 'staff_count' => 8, 'children_count' => 0],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function insights(): array
    {
        return [
            ['key' => 'revenue', 'label' => 'Revenue (This Month)', 'value' => '₹12,48,500', 'icon' => 'ti ti-currency-rupee', 'change' => 20],
            ['key' => 'new_patients', 'label' => 'New Patients', 'value' => '342', 'icon' => 'ti ti-user-plus', 'change' => 18],
            ['key' => 'completed', 'label' => 'Completed Appointments', 'value' => '1,124', 'icon' => 'ti ti-circle-check', 'change' => 22],
            ['key' => 'retention', 'label' => 'Staff Retention', 'value' => '96%', 'icon' => 'ti ti-shield-check', 'change' => 4],
        ];
    }

    /**
     * A subscription tier — something the software does not have.
     *
     * Modules are assigned one at a time by a super admin; there are no plans
     * and no self-serve upgrade. Present here because the reference design has
     * it and this is demo mode; the real `plan` panel says the true thing
     * instead, which is how many modules the organization holds.
     *
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        return [
            'name' => 'Professional Plan',
            'blurb' => 'Manage unlimited branches and staff.',
            'modules' => 5,
            'names' => ['branches', 'people', 'customers', 'settings', 'appointments'],
            'valid_until' => now()->addYear()->endOfYear()->toDateString(),
            'trial_ends_at' => null,
            'since' => '2024-01-15',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activity(): array
    {
        return [
            ['id' => 1, 'action' => 'created', 'entity_type' => 'User', 'entity_label' => 'New staff member added', 'detail' => 'Priya Singh joined Central Clinic', 'actor_name' => null, 'created_at' => now()->subHours(2)->toIso8601String()],
            ['id' => 2, 'action' => 'created', 'entity_type' => 'Location', 'entity_label' => 'Branch created', 'detail' => 'South Branch has been created', 'actor_name' => null, 'created_at' => now()->subHours(5)->toIso8601String()],
            ['id' => 3, 'action' => 'updated', 'entity_type' => 'Role', 'entity_label' => 'Role updated', 'detail' => 'Receptionist role updated', 'actor_name' => null, 'created_at' => now()->subDay()->toIso8601String()],
            ['id' => 4, 'action' => 'created', 'entity_type' => 'Customer', 'entity_label' => 'New patient registered', 'detail' => 'Amit Kumar at North Branch', 'actor_name' => null, 'created_at' => now()->subDay()->toIso8601String()],
            ['id' => 5, 'action' => 'updated', 'entity_type' => 'User', 'entity_label' => 'Staff role assigned', 'detail' => 'Rahul assigned as Pharmacist', 'actor_name' => null, 'created_at' => now()->subDays(2)->toIso8601String()],
        ];
    }
}
