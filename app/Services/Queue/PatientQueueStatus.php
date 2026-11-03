<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where a patient stands in their doctor's queue today, for the patient app.
 *
 * Scoped to the patient and their family before anything else is read, and it
 * says nothing about anybody else in the line beyond how many are ahead and the
 * token being served: the same tokens the waiting room's television shows.
 *
 * `queue_status` here is said from the patient's side: `expected` until they
 * arrive, `done` once the doctor has finished, whatever the visit is doing next.
 */
class PatientQueueStatus
{
    public function __construct(
        private readonly QueueSettings $settings,
        private readonly EstimatedWaitingTimeService $estimates,
    ) {}

    /**
     * Today's visits for this patient and whoever they look after.
     *
     * @return list<array<string, mixed>>
     */
    public function today(Customer $patient): array
    {
        return $this->visits($patient)
            ->where('appointment_date', QueueClock::today())
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn (Appointment $visit) => $this->status($visit, $patient))
            ->all();
    }

    /**
     * One visit, if it is theirs to see. A stranger's id is simply not found.
     *
     * @return array<string, mixed>|null
     */
    public function one(Customer $patient, int $appointmentId): ?array
    {
        $visit = $this->visits($patient)->whereKey($appointmentId)->first();

        return $visit ? $this->status($visit, $patient) : null;
    }

    /** @return Builder<Appointment> */
    private function visits(Customer $patient)
    {
        return Appointment::query()
            ->with(['doctor.department', 'location', 'customer.familyLink'])
            ->whereIn('customer_id', $patient->familyIds());
    }

    /** @return array<string, mixed> */
    private function status(Appointment $visit, Customer $patient): array
    {
        $live = $this->liveQueue($visit);

        $setting = $this->settings->forDoctor((int) $visit->doctor_id, (int) $visit->location_id);
        $estimate = $this->estimates->estimate($setting, $live);
        $position = $estimate['positions'][$visit->id] ?? null;

        $serving = $live->where('queue_status', Appointment::QUEUE_WITH_DOCTOR)->sortByDesc('started_at')->first();
        $own = (int) $visit->customer_id === (int) $patient->id;

        return [
            'appointment_id' => $visit->id,
            'patient' => [
                'name' => $visit->customer?->name,
                'is_self' => $own,
                'relation' => $own ? null : $visit->customer?->familyLink?->relation,
            ],
            'doctor' => ['id' => (int) $visit->doctor_id, 'name' => $visit->doctor?->name],
            'branch' => ['id' => (int) $visit->location_id, 'name' => $visit->location?->name],
            'department' => $visit->doctor?->department?->name ?? $visit->doctor?->specialisation,
            'room' => $setting->room_no,

            'token' => $visit->tokenLabel(),
            'now_serving' => $serving?->tokenLabel(),
            'queue_status' => $this->saidToThePatient($visit),
            'patients_ahead' => $position['ahead'] ?? null,
            'estimated_wait_minutes' => $position['minutes'] ?? null,

            // Always an estimate. The app says "about", and never a clock time.
            'estimate_is_approximate' => true,
            'queue_paused' => $estimate['paused'],

            'slot_at' => $visit->slot_at ? substr((string) $visit->slot_at, 0, 5) : null,
            'called_at' => $visit->called_at?->toIso8601String(),
        ];
    }

    /** @return Collection<int, Appointment> */
    private function liveQueue(Appointment $visit): Collection
    {
        if ($visit->isVideo()) {
            return new Collection;
        }

        return Appointment::query()
            ->where('doctor_id', $visit->doctor_id)
            ->where('location_id', $visit->location_id)
            ->where('appointment_date', $visit->appointment_date->toDateString())
            ->inTheBuilding()
            ->whereNotNull('queue_status')
            ->get();
    }

    private function saidToThePatient(Appointment $visit): string
    {
        if ($visit->queue_status !== null) {
            return $visit->queue_status;
        }

        return $visit->status === Appointment::STATUS_BOOKED ? 'expected' : 'done';
    }
}
