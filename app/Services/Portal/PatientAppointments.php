<?php

namespace App\Services\Portal;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Services\Opd\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One patient's appointments — read, and booked by themselves.
 *
 * Every query starts from the patient, so nothing here can be asked about
 * anybody else. Booking itself is BookingService's, exactly as at the desk;
 * this only adds what is different about a patient doing it.
 */
class PatientAppointments
{
    /** Still ahead of the patient: not finished, not called off. */
    public const LIVE = [
        Appointment::STATUS_BOOKED,
        Appointment::STATUS_CHECKED_IN,
        Appointment::STATUS_IN_CONSULTATION,
    ];

    public function __construct(
        private readonly BookingService $booking,
        private readonly BookableDoctors $doctors,
    ) {}

    /** Today onwards, soonest first. */
    public function upcoming(Customer $patient, ?int $limit = null): Collection
    {
        return $this->of($patient)
            ->whereIn('status', self::LIVE)
            ->whereDate('appointment_date', '>=', today())
            ->orderBy('appointment_date')
            ->orderByRaw('slot_at NULLS LAST')
            ->when($limit, fn (Builder $query) => $query->limit($limit))
            ->get();
    }

    /** Everything else — finished, cancelled, missed, or in the past — newest first. */
    public function past(Customer $patient, int $limit = 50): Collection
    {
        return $this->of($patient)
            ->where(fn (Builder $query) => $query
                ->whereNotIn('status', self::LIVE)
                ->orWhereDate('appointment_date', '<', today()))
            ->orderByDesc('appointment_date')
            ->orderByDesc('slot_at')
            ->limit($limit)
            ->get();
    }

    public function upcomingCount(Customer $patient): int
    {
        return $this->of($patient)
            ->whereIn('status', self::LIVE)
            ->whereDate('appointment_date', '>=', today())
            ->count();
    }

    /**
     * Book a time the patient was offered.
     *
     * Checked against the same list the patient picked from — a branch that
     * closed, or a time that passed while they were choosing, is refused here
     * even though BookingService alone would allow it.
     *
     * @throws RuntimeException with a sentence the patient can act on
     */
    public function book(Organization $organization, Customer $patient, array $data): Appointment
    {
        $doctor = Doctor::query()->findOrFail($data['doctor_id']);
        $date = Carbon::parse($data['appointment_date']);
        $locationId = (int) $data['location_id'];

        if (! $this->doctors->offers($organization, $doctor, $date, $locationId, $data['slot_at'])) {
            throw new RuntimeException('That time was just taken or is no longer available. Please pick another.');
        }

        /*
         * One live booking per doctor per day. A patient tapping "Confirm"
         * twice on a slow connection should not hold two of a doctor's
         * morning slots, and nobody needs two.
         */
        $already = $this->of($patient)
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', self::LIVE)
            ->exists();

        if ($already) {
            throw new RuntimeException("You already have an appointment with {$doctor->name} that day.");
        }

        return $this->booking
            ->book([
                'customer_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'location_id' => $locationId,
                'appointment_date' => $date->toDateString(),
                'slot_at' => $data['slot_at'],
                'notes' => $data['notes'] ?? null,
            ])
            ->load(['doctor.photograph', 'location']);
    }

    private function of(Customer $patient): Builder
    {
        return Appointment::query()
            ->with(['doctor.photograph', 'location'])
            ->where('customer_id', $patient->id);
    }
}
