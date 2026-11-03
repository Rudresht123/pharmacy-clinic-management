<?php

namespace App\Services\Portal;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Services\Opd\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One patient's appointments — read, and booked by themselves — along with
 * those of the family they look after.
 *
 * Every query starts from the patient, so nothing here can be asked about
 * anybody outside that family. Booking itself is BookingService's, exactly as
 * at the desk; this only adds what is different about a patient doing it.
 */
class PatientAppointments
{
    /**
     * Still ahead of the patient: not finished, not called off.
     *
     * The three waiting rooms belong here. A patient who has seen the doctor
     * and is queuing for their medicines has an appointment that is very
     * much still live — it is the one thing they are doing today — and
     * dropping it into "past" the moment the write-up was signed would have
     * the app tell them they were finished while they were standing at the
     * counter.
     */
    public const LIVE = [
        Appointment::STATUS_BOOKED,
        Appointment::STATUS_CHECKED_IN,
        Appointment::STATUS_IN_CONSULTATION,
        ...Appointment::AWAITING,
    ];

    /** What a visit needs loaded for PortalAppointmentResource to describe it. */
    public const RELATIONS = ['customer.familyLink', 'doctor.photograph', 'location', 'liveVideoCall', 'latestRescheduleRequest'];

    public function __construct(
        private readonly BookingService $booking,
        private readonly BookableDoctors $doctors,
    ) {}

    /** Today onwards, soonest first. */
    public function upcoming(Customer $patient, ?int $limit = null): Collection
    {
        return $this->ahead($this->of($patient), $limit);
    }

    /** Everything else — finished, cancelled, missed, or in the past — newest first. */
    public function past(Customer $patient, int $limit = 50): Collection
    {
        return $this->behind($this->of($patient), $limit);
    }

    /** One person's own visits still ahead, for a family member's history. */
    public function upcomingOf(Customer $person): Collection
    {
        return $this->ahead($this->ofOnly($person));
    }

    /** One person's own visits behind them, with what the doctor wrote up. */
    public function pastOf(Customer $person, int $limit = 50): Collection
    {
        return $this->behind($this->ofOnly($person)->with('consultation'), $limit);
    }

    /**
     * How often one person has actually been seen, and when last.
     *
     * @return array{visits: int, last_visit_on: ?string}
     */
    public function seen(Customer $person): array
    {
        $seen = Appointment::query()
            ->where('customer_id', $person->id)
            ->where('consultation_status', Appointment::CONSULT_COMPLETED);

        return [
            'visits' => (clone $seen)->count(),
            'last_visit_on' => (clone $seen)->latest('appointment_date')->first()?->appointment_date?->toDateString(),
        ];
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
     * @param  Customer|null  $member  somebody in their family, already
     *                                 checked to be; null for their own visit
     *
     * @throws RuntimeException with a sentence the patient can act on
     */
    public function book(Organization $organization, Customer $patient, array $data, ?Customer $member = null): Appointment
    {
        $doctor = Doctor::query()->findOrFail($data['doctor_id']);
        $date = Carbon::parse($data['appointment_date']);
        $locationId = (int) $data['location_id'];

        // The same rule the list was filtered by — a doctor with no weekly
        // schedule is not one a patient was ever shown, even if a one-off
        // extra session (added for the desk) would otherwise let this slot
        // through.
        if (! $this->doctors->hasSchedule($doctor)) {
            throw new RuntimeException('That doctor is not taking bookings.');
        }

        if (! $this->doctors->offers($organization, $doctor, $date, $locationId, $data['slot_at'])) {
            throw new RuntimeException('That time was just taken or is no longer available. Please pick another.');
        }

        /*
         * One live booking per doctor per day. A patient tapping "Confirm"
         * twice on a slow connection should not hold two of a doctor's
         * morning slots, and nobody needs two. Per person: a parent and a
         * child may well see the same doctor together.
         */
        $seeing = $member ?? $patient;

        $already = Appointment::query()
            ->where('customer_id', $seeing->id)
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', self::LIVE)
            ->exists();

        if ($already) {
            throw new RuntimeException(
                $member === null
                    ? "You already have an appointment with {$doctor->name} that day."
                    : "{$member->name} already has an appointment with {$doctor->name} that day."
            );
        }

        try {
            $appointment = $this->booking->book([
                'customer_id' => $seeing->id,
                'doctor_id' => $doctor->id,
                'location_id' => $locationId,
                'appointment_date' => $date->toDateString(),
                'slot_at' => $data['slot_at'],
                'mode' => $data['mode'] ?? Appointment::MODE_IN_CLINIC,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two patients tapped the same time together: both passed the
             * check above, and `appointments_slot_unique` let one of them in.
             * The other is told so in words, not handed the SQL error — which
             * is a RuntimeException too, and would otherwise reach the patient
             * verbatim through the controller's catch.
             */
            throw new RuntimeException('That time was just taken or is no longer available. Please pick another.');
        }

        return $appointment->load(self::RELATIONS);
    }

    /**
     * Call off one of the patient's own bookings.
     *
     * Only while it is still just a booking. Once they have checked in they
     * are in the desk's queue with a token, and taking themselves out of it
     * from a phone would leave the desk calling a number nobody answers — so
     * from there it is the desk's decision, and the sentence says so.
     *
     * Somebody else's appointment is not found rather than refused: a patient
     * has no business learning that an id belongs to anybody.
     *
     * @throws ModelNotFoundException when it is not theirs
     * @throws RuntimeException with a sentence the patient can act on
     */
    public function cancel(Customer $patient, int $appointmentId): Appointment
    {
        $appointment = $this->of($patient)->whereKey($appointmentId)->firstOrFail();

        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            throw new RuntimeException(match ($appointment->status) {
                Appointment::STATUS_CANCELLED => 'This appointment is already cancelled.',
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_CONSULTATION,
                // Seen, and still in the building for the pharmacy, the lab
                // or the till. "Already checked in" is the honest answer.
                Appointment::STATUS_AWAITING_PHARMACY,
                Appointment::STATUS_AWAITING_LAB,
                Appointment::STATUS_AWAITING_PAYMENT => 'You have already checked in. Please speak to the front desk.',
                default => 'This appointment can no longer be cancelled.',
            });
        }

        if ($appointment->appointment_date->lt(today())) {
            throw new RuntimeException('This appointment has already passed.');
        }

        return $this->booking->cancel($appointment, 'Cancelled by the patient in the app');
    }

    private function ahead(Builder $visits, ?int $limit = null): Collection
    {
        return $visits
            ->whereIn('status', self::LIVE)
            ->whereDate('appointment_date', '>=', today())
            ->orderBy('appointment_date')
            ->orderByRaw('slot_at NULLS LAST')
            ->when($limit, fn (Builder $query) => $query->limit($limit))
            ->get();
    }

    private function behind(Builder $visits, int $limit): Collection
    {
        return $visits
            ->where(fn (Builder $query) => $query
                ->whereNotIn('status', self::LIVE)
                ->orWhereDate('appointment_date', '<', today()))
            ->orderByDesc('appointment_date')
            ->orderByDesc('slot_at')
            ->limit($limit)
            ->get();
    }

    private function of(Customer $patient): Builder
    {
        return Appointment::query()
            ->with(self::RELATIONS)
            ->whereIn('customer_id', $patient->familyIds());
    }

    /** Nobody but this person: a member's own family is not the viewer's to read. */
    private function ofOnly(Customer $person): Builder
    {
        return Appointment::query()
            ->with(self::RELATIONS)
            ->where('customer_id', $person->id);
    }
}
