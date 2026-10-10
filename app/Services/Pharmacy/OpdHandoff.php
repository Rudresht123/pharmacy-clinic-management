<?php

namespace App\Services\Pharmacy;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Collection;

/**
 * The patients the doctor has just finished with, as the pharmacy counter
 * sees them.
 *
 * Only meaningful where a branch runs BOTH the OPD and the pharmacy — the
 * `opd_pharmacy_handoff` feature. A medical shop that was never sold the OPD
 * has no consultations to hand over, and asking this there is the caller's
 * mistake; the controller and the broadcaster both check the feature first.
 *
 * ONE ROW SHAPE, used twice: the list the counter loads, and the payload the
 * realtime event carries. The panel can then insert a pushed row exactly as if
 * it had been fetched, and the two can never describe a patient differently.
 *
 * Today's visits only. Yesterday's patient who never came to the counter is a
 * different conversation — a follow-up, not a hand-off.
 */
class OpdHandoff
{
    /**
     * What still owes the counter work: the same three statuses
     * VisitWorkflow::pending() counts, so "waiting for the pharmacy" means one
     * thing on every screen.
     */
    private const PENDING = [
        Prescription::DRAFT,
        Prescription::ISSUED,
        Prescription::PARTIALLY_DISPENSED,
    ];

    /*
     * Where the counter stands with one patient — the panel's tabs. Finer than
     * `pharmacy_pending`, which says only whether anything is still owed:
     * half a prescription handed over is a different conversation from none
     * of it, and the counter works the two differently.
     */
    public const STATE_PENDING = 'pending';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_COMPLETED = 'completed';

    /** Consulted, but nothing was prescribed for the counter to hand over. */
    public const STATE_NONE = 'none';

    /**
     * Today's finished consultations at a branch.
     *
     * Those the pharmacy still owes something first — they are the reason the
     * panel exists — then everybody else, most recently finished first.
     *
     * @return list<array<string, mixed>>
     */
    public function forBranch(int $locationId): array
    {
        $visits = Appointment::query()
            ->with(['customer', 'doctor'])
            ->where('location_id', $locationId)
            ->where('appointment_date', today()->toDateString())
            ->where('consultation_status', Appointment::CONSULT_COMPLETED)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        // One query for every visit's prescriptions, not one per row.
        $statuses = Prescription::query()
            ->live()
            ->whereIn('appointment_id', $visits->modelKeys() ?: [0])
            ->get(['appointment_id', 'status'])
            ->groupBy('appointment_id')
            ->map(fn (Collection $rows) => $rows->pluck('status'));

        $rows = $visits
            ->map(fn (Appointment $visit) => $this->row($visit, $statuses->get($visit->id, collect())))
            ->all();

        // Stable, so "most recently finished" survives within each half.
        usort($rows, fn (array $a, array $b) => $b['pharmacy_pending'] <=> $a['pharmacy_pending']);

        return $rows;
    }

    /**
     * One patient, as the counter needs them.
     *
     * Enough to call somebody to the counter and open their prescription —
     * nothing clinical. The diagnosis is the doctor's; the counter reads the
     * prescription itself, through its own permission, when it opens it.
     *
     * @param  Collection<int, string>|null  $prescriptionStatuses  pass when already loaded
     * @return array<string, mixed>
     */
    public function row(Appointment $visit, ?Collection $prescriptionStatuses = null): array
    {
        $visit->loadMissing(['customer', 'doctor']);

        $statuses = $prescriptionStatuses ?? Prescription::query()
            ->live()
            ->where('appointment_id', $visit->id)
            ->pluck('status');

        return [
            'appointment_id' => $visit->id,
            'token_no' => $visit->token_no,
            // The code and the number are how a counter tells two Rohit
            // Sharmas apart when it calls one of them up.
            'patient' => [
                'id' => $visit->customer_id,
                'name' => $visit->customer?->name,
                'code' => $visit->customer?->code,
                'phone' => $visit->customer?->phone,
            ],
            'doctor' => [
                'id' => $visit->doctor_id,
                'name' => $visit->doctor?->name,
                'specialisation' => $visit->doctor?->specialisation,
            ],
            'completed_at' => $visit->completed_at?->toIso8601String(),
            'has_prescription' => $statuses->isNotEmpty(),
            'pharmacy_pending' => $statuses->contains(fn (string $status) => in_array($status, self::PENDING, true)),
            'pharmacy_state' => $this->state($statuses),
            'status' => $visit->status,
        ];
    }

    /**
     * One patient's place on the counter's tabs, from their prescriptions.
     *
     * Part-dispensed wins over not-started: a patient with one prescription
     * half handed over and another untouched is mid-way through the counter,
     * not waiting to reach it.
     *
     * @param  Collection<int, string>  $statuses
     */
    private function state(Collection $statuses): string
    {
        return match (true) {
            $statuses->isEmpty() => self::STATE_NONE,
            $statuses->contains(Prescription::PARTIALLY_DISPENSED) => self::STATE_IN_PROGRESS,
            $statuses->contains(fn (string $status) => in_array($status, self::PENDING, true)) => self::STATE_PENDING,
            default => self::STATE_COMPLETED,
        };
    }
}
