<?php

namespace App\Services\Tenant;

/**
 * One branch's day, filled in with sample figures.
 *
 * EVERY NUMBER IN THIS FILE IS INVENTED, on the same terms as
 * DemoDashboardData — see that class for how to turn demo mode off and how to
 * retire it panel by panel.
 *
 * Separate from it because a branch dashboard is a different screen, not a
 * narrower version of the organization one. Standing in a clinic, the
 * questions are who is arriving, what is running low and what has to be done
 * before closing. None of those mean anything summed across five branches, and
 * "how is the network growing" means nothing at the counter.
 *
 * Several panels here belong to modules that do not exist at all — pharmacy
 * stock, billing, tasks. They are the first to delete.
 */
class DemoBranchDashboardData
{
    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'headline' => $this->headline(),
            'visits' => $this->visits(),
            'by_type' => $this->byType(),
            'appointments' => $this->appointments(),
            'recent_patients' => $this->recentPatients(),
            'stock' => $this->stock(),
            'revenue' => $this->revenue(),
            'tasks' => $this->tasks(),
            'activity' => $this->activity(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function headline(): array
    {
        return [
            ['key' => 'patients', 'label' => 'Total Patients', 'total' => 124, 'this_month' => 13, 'change' => 12, 'hint' => 'from yesterday'],
            ['key' => 'appointments', 'label' => 'Today’s Appointments', 'total' => 32, 'this_month' => 2, 'change' => 8, 'hint' => 'from yesterday'],
            ['key' => 'consultations', 'label' => 'OPD Consultations', 'total' => 6, 'this_month' => 1, 'change' => 20, 'hint' => 'from yesterday'],
            ['key' => 'revenue', 'label' => 'Today’s Revenue', 'total' => 48750, 'this_month' => 7350, 'change' => 18, 'money' => true, 'hint' => 'from yesterday'],
        ];
    }

    /**
     * Arrivals by the hour — the shape of a clinic's day.
     *
     * Hourly rather than daily: at a branch the useful question is when the
     * rush is, which a day-by-day chart cannot answer.
     *
     * @return array<string, mixed>
     */
    private function visits(): array
    {
        $counts = [12, 18, 24, 31, 27, 22, 19, 44, 33, 26, 21, 15, 9];

        $points = [];

        foreach (range(8, 20) as $index => $hour) {
            $clock = $hour > 12 ? ($hour - 12).' PM' : $hour.' AM';

            $points[] = [
                // Every other hour, or thirteen ticks collide.
                'label' => $hour % 2 === 0 ? $clock : '',
                'title' => $clock,
                'value' => $counts[$index],
            ];
        }

        return ['points' => $points];
    }

    /** @return list<array<string, mixed>> */
    private function byType(): array
    {
        return [
            ['label' => 'Consultation', 'total' => 14, 'muted' => false],
            ['label' => 'Follow-up', 'total' => 8, 'muted' => false],
            ['label' => 'Vaccination', 'total' => 4, 'muted' => false],
            ['label' => 'Procedure', 'total' => 3, 'muted' => false],
            // Not a category — the ones nobody classified.
            ['label' => 'Other', 'total' => 3, 'muted' => true],
        ];
    }

    /** @return array<string, mixed> */
    private function appointments(): array
    {
        return [
            'date' => now()->toDateString(),
            'waiting' => 3,
            'in_consultation' => 1,
            'seen' => 18,
            'expected' => 10,
            'longest_wait_minutes' => 22,
            'trend' => ['current' => [], 'previous' => []],
            'upcoming' => [
                ['id' => 1, 'time' => '09:00', 'patient' => 'Sunita Verma', 'doctor' => 'Dr. Anil Sharma', 'type' => 'Consultation', 'status' => 'checked_in'],
                ['id' => 2, 'time' => '09:30', 'patient' => 'Rajesh Kumar', 'doctor' => 'Dr. Priya Singh', 'type' => 'Follow-up', 'status' => 'booked'],
                ['id' => 3, 'time' => '10:00', 'patient' => 'Neha Sharma', 'doctor' => 'Dr. Amit Gupta', 'type' => 'Vaccination', 'status' => 'waiting'],
                ['id' => 4, 'time' => '10:30', 'patient' => 'Amit Singh', 'doctor' => 'Dr. Anil Sharma', 'type' => 'Consultation', 'status' => 'booked'],
                ['id' => 5, 'time' => '11:00', 'patient' => 'Pooja Yadav', 'doctor' => 'Dr. Priya Singh', 'type' => 'Procedure', 'status' => 'booked'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentPatients(): array
    {
        return [
            ['id' => 1, 'name' => 'Rakesh Mishra', 'age' => 34, 'type' => 'Consultation', 'joined_at' => now()->toDateString()],
            ['id' => 2, 'name' => 'Priya Singh', 'age' => 28, 'type' => 'Follow-up', 'joined_at' => now()->toDateString()],
            ['id' => 3, 'name' => 'Arun Kumar', 'age' => 45, 'type' => 'Consultation', 'joined_at' => now()->toDateString()],
            ['id' => 4, 'name' => 'Meera Patel', 'age' => 31, 'type' => 'Lab Test', 'joined_at' => now()->toDateString()],
            ['id' => 5, 'name' => 'Sanjay Verma', 'age' => 52, 'type' => 'Consultation', 'joined_at' => now()->toDateString()],
        ];
    }

    /**
     * Pharmacy has no module yet — no table, no model, no routes.
     *
     * @return list<array<string, mixed>>
     */
    private function stock(): array
    {
        return [
            ['name' => 'Paracetamol 500mg', 'stock' => 12, 'status' => 'low'],
            ['name' => 'Amoxicillin 500mg', 'stock' => 5, 'status' => 'low'],
            ['name' => 'Cetirizine 10mg', 'stock' => 0, 'status' => 'out'],
            ['name' => 'ORS Sachet', 'stock' => 8, 'status' => 'low'],
            ['name' => 'Vitamin D3', 'stock' => 15, 'status' => 'low'],
        ];
    }

    /**
     * Billing has no module yet either.
     *
     * @return array<string, mixed>
     */
    private function revenue(): array
    {
        $rows = [
            ['label' => 'Mon, Aug 31', 'amount' => 42320],
            ['label' => 'Tue, Sep 1', 'amount' => 51210],
            ['label' => 'Wed, Sep 2', 'amount' => 48950],
            ['label' => 'Thu, Sep 3', 'amount' => 56780],
            ['label' => 'Fri, Sep 4', 'amount' => 48750, 'today' => true],
        ];

        return [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'amount')),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tasks(): array
    {
        return [
            ['label' => 'Verify pending lab reports', 'badge' => '3 pending', 'tone' => 'rose'],
            ['label' => 'Follow up with today’s no-shows', 'badge' => '5 patients', 'tone' => 'sky'],
            ['label' => 'Check expired medicines', 'badge' => '2 items', 'tone' => 'amber'],
            ['label' => 'Review daily cash collection', 'badge' => null, 'tone' => null],
            ['label' => 'Update inventory', 'badge' => null, 'tone' => null],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activity(): array
    {
        return [
            ['id' => 1, 'action' => 'created', 'entity_type' => 'Customer', 'entity_label' => 'New patient registered', 'detail' => 'Rakesh Mishra', 'actor_name' => null, 'created_at' => now()->subMinutes(10)->toIso8601String()],
            ['id' => 2, 'action' => 'created', 'entity_type' => 'Appointment', 'entity_label' => 'Appointment booked', 'detail' => 'Neha Sharma · Vaccination', 'actor_name' => null, 'created_at' => now()->subMinutes(22)->toIso8601String()],
            ['id' => 3, 'action' => 'created', 'entity_type' => 'Payment', 'entity_label' => 'Payment received', 'detail' => '₹1,200 · Rajesh Kumar', 'actor_name' => null, 'created_at' => now()->subHour()->toIso8601String()],
            ['id' => 4, 'action' => 'updated', 'entity_type' => 'Stock', 'entity_label' => 'Medicine stock updated', 'detail' => 'Paracetamol 500mg', 'actor_name' => null, 'created_at' => now()->subHours(2)->toIso8601String()],
        ];
    }
}
