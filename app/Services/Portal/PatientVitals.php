<?php

namespace App\Services\Portal;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;

/**
 * The patient's own vitals, read off the most recent visit that recorded
 * them -- not necessarily the most recent visit.
 *
 * Mirrors CustomerController::summarise(), the desk's own version of this
 * same question: a doctor who took no vitals on Tuesday has not erased
 * Monday's reading, so the newest visit's blanks must not hide an earlier
 * answer. The two screens read it the same way on purpose, so they never
 * disagree about whose vitals are "the latest".
 */
class PatientVitals
{
    /** How far back to look before giving up on finding a recorded reading. */
    private const LOOKBACK = 20;

    public function latest(Customer $patient): ?array
    {
        return $this->readings($patient)[0] ?? null;
    }

    /**
     * Every reading in the visits looked back over, newest first.
     *
     * @return list<array{recorded_on: ?string, values: array<string, mixed>}>
     */
    public function readings(Customer $patient): array
    {
        return Appointment::query()
            ->where('customer_id', $patient->id)
            ->where('consultation_status', Appointment::CONSULT_COMPLETED)
            ->with('consultation')
            ->orderByDesc('appointment_date')
            ->limit(self::LOOKBACK)
            ->get()
            ->filter(fn (Appointment $visit) => ! empty($visit->consultation?->vitals))
            ->map(fn (Appointment $visit) => [
                'recorded_on' => $visit->appointment_date?->toDateString(),
                'values' => $visit->consultation->vitals,
            ])
            ->values()
            ->all();
    }
}
