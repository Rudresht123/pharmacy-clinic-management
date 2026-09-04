<?php

namespace App\Services\Tenant;

use Illuminate\Support\Carbon;

/**
 * The dashboard, filled in with sample figures.
 *
 * EVERY NUMBER IN THIS FILE IS INVENTED. It exists so the whole screen can be
 * seen and judged before the modules behind it ship — billing, departments,
 * reports and an HR record of leavers do not exist, and the panels that need
 * them would otherwise be empty boxes nobody could evaluate.
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
     * The sample payload, with anything genuinely known merged over the top.
     *
     * Real data wins wherever it exists: an organization that has entered
     * three branches sees three, not five invented ones. Only the panels with
     * nothing real behind them stay sample.
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
        $demo = $this->payload();

        foreach ($demo as $key => $panel) {
            // `scope` is always real — it says which branches the figures are
            // about, and inventing that would misdescribe the real panels too.
            if ($key === 'scope' || ! in_array($key, $permitted, true)) {
                continue;
            }

            $real[$key] = $this->hasRealContent($real[$key] ?? null) ? $real[$key] : $panel;
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

    /**
     * Whether a real panel has anything in it worth showing.
     *
     * An organization mid-setup has a `patients` panel of zeroes, and a screen
     * of zeroes teaches nothing about the design. A panel with real rows in it
     * always wins.
     */
    private function hasRealContent(mixed $panel): bool
    {
        if ($panel === null || $panel === []) {
            return false;
        }

        if (is_array($panel) && array_is_list($panel)) {
            return $panel !== [];
        }

        foreach (['total', 'rows', 'by_branch', 'upcoming'] as $key) {
            if (isset($panel[$key]) && ! empty($panel[$key])) {
                return true;
            }
        }

        return false;
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
            'activity' => $this->activity(),
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
     * Departments — a module that does not exist at all yet.
     *
     * No table, no model, no routes. Shown so the shape of the screen is
     * complete; delete this method and the panel disappears.
     *
     * @return list<array<string, mixed>>
     */
    private function departments(): array
    {
        return [
            ['id' => 1, 'name' => 'General Medicine', 'head' => 'Dr. Anjali Sharma', 'staff' => 14, 'patients' => 1240],
            ['id' => 2, 'name' => 'Paediatrics', 'head' => 'Dr. Vikram Rao', 'staff' => 9, 'patients' => 860],
            ['id' => 3, 'name' => 'Pharmacy', 'head' => 'Rahul Mehta', 'staff' => 11, 'patients' => 0],
            ['id' => 4, 'name' => 'Diagnostics', 'head' => 'Dr. Sneha Patel', 'staff' => 8, 'patients' => 512],
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
