<?php

namespace App\Services\Opd;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking a slot, taking a walk-in, and moving somebody through the queue.
 *
 * The one place that decides those three things. Availability says what
 * *could* be booked; this says what has been, and refuses the rest.
 */
class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * The times still free for a doctor on a date.
     *
     * Availability minus what is already taken — computed, never read from a
     * table, because a slot only exists as an arithmetic consequence of a
     * sitting's window and its length.
     *
     * @return list<array<string, mixed>> sessions, each with `slots` and `taken`
     */
    public function openSlots(Doctor $doctor, Carbon $date, ?int $locationId = null): array
    {
        $taken = Appointment::on('organization')
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->holdingASlot()
            ->pluck('slot_at')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->all();

        return array_map(function (array $session) use ($taken) {
            $slots = $this->availability->slotsFor($session);

            return [
                ...$session,
                'slots' => array_values(array_diff($slots, $taken)),
                'taken' => array_values(array_intersect($slots, $taken)),
            ];
        }, $this->availability->sessionsFor($doctor, $date, $locationId));
    }

    /**
     * How full each doctor's day already is.
     *
     * The desk picks a doctor before it sees a single slot, and "sitting
     * 9–1" says nothing about whether 9–1 is spoken for. A count against the
     * name is the difference between choosing a doctor and choosing one who
     * can actually take the patient.
     *
     * One query for every doctor on the list rather than one each: the caller
     * is rendering a whole branch's day, and the alternative is a query per
     * row of a list that exists to be scanned.
     *
     * @param  list<array<string, mixed>>  $doctors  as `dayAtLocation` returns them
     * @return array<int, array{open: int, total: int}>
     */
    public function loadFor(array $doctors, Carbon $date): array
    {
        $ids = array_column($doctors, 'doctor_id');

        if ($ids === []) {
            return [];
        }

        $taken = Appointment::on('organization')
            ->whereIn('doctor_id', $ids)
            ->whereDate('appointment_date', $date->toDateString())
            ->holdingASlot()
            ->get(['doctor_id', 'slot_at'])
            ->groupBy('doctor_id')
            ->map(fn ($rows) => $rows
                ->map(fn ($row) => substr((string) $row->slot_at, 0, 5))
                ->all())
            ->all();

        $load = [];

        foreach ($doctors as $doctor) {
            $slots = [];

            // Derived from the sessions already on the payload, so this costs
            // arithmetic rather than another read of the timetable.
            foreach ($doctor['sessions'] as $session) {
                $slots = array_merge($slots, $this->availability->slotsFor($session));
            }

            $mine = $taken[$doctor['doctor_id']] ?? [];

            $load[$doctor['doctor_id']] = [
                'total' => count($slots),
                'open' => count(array_diff($slots, $mine)),
            ];
        }

        return $load;
    }

    /**
     * Book a named time.
     *
     * The slot is checked against derived availability rather than against
     * the weekly table: a doctor on leave has no slots that day, and a
     * sitting whose hours moved does not offer the times it used to.
     */
    public function book(array $attributes): Appointment
    {
        $doctor = Doctor::on('organization')->findOrFail($attributes['doctor_id']);
        $date = Carbon::parse($attributes['appointment_date']);
        $slot = substr((string) $attributes['slot_at'], 0, 5);

        $session = $this->sessionOffering($doctor, $date, $attributes['location_id'], $slot);

        if (! $session) {
            throw new RuntimeException(
                'That time is not one this doctor is available at, on this date.'
            );
        }

        return Appointment::on('organization')->create([
            'customer_id' => $attributes['customer_id'],
            'doctor_id' => $doctor->id,
            'location_id' => $attributes['location_id'],

            // Provenance: which sitting produced this time. Null for an
            // extra session, which has no weekly row behind it.
            'doctor_schedule_id' => $session['schedule_id'],

            'appointment_date' => $date->toDateString(),
            'type' => Appointment::BOOKED,
            'status' => Appointment::STATUS_BOOKED,
            'slot_at' => $slot,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    /**
     * Take somebody who has simply turned up.
     *
     * Checked in immediately, because they are standing there — which is why
     * a walk-in gets a token at once and a booking does not.
     */
    public function walkIn(array $attributes): Appointment
    {
        $doctor = Doctor::on('organization')->findOrFail($attributes['doctor_id']);
        $date = Carbon::parse($attributes['appointment_date']);

        $sessions = $this->availability->sessionsFor($doctor, $date, $attributes['location_id']);

        if ($sessions === []) {
            throw new RuntimeException('This doctor is not sitting here on that date.');
        }

        $appointment = Appointment::on('organization')->create([
            'customer_id' => $attributes['customer_id'],
            'doctor_id' => $doctor->id,
            'location_id' => $attributes['location_id'],
            'doctor_schedule_id' => $sessions[0]['schedule_id'],
            'appointment_date' => $date->toDateString(),
            'type' => Appointment::WALK_IN,
            'status' => Appointment::STATUS_BOOKED,
            'notes' => $attributes['notes'] ?? null,
        ]);

        return $this->checkIn($appointment);
    }

    /**
     * Arrive, and take a number.
     *
     * The token is issued here rather than at booking, so a patient who
     * never turns up leaves no gap in the day's numbering.
     *
     * Allocation is `max + 1` inside a transaction, and two receptionists
     * clicking at the same moment will both read the same maximum. The
     * unique index on (doctor, location, date, token) is what stops them
     * both winning; this retries around the collision rather than handing
     * one of them an error they cannot act on.
     */
    public function checkIn(Appointment $appointment): Appointment
    {
        $this->assertCanMoveTo($appointment, Appointment::STATUS_CHECKED_IN);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::connection('organization')->transaction(function () use ($appointment) {
                    $next = Appointment::on('organization')
                        ->where('doctor_id', $appointment->doctor_id)
                        ->where('location_id', $appointment->location_id)
                        ->whereDate('appointment_date', $appointment->appointment_date)
                        ->max('token_no');

                    $appointment->update([
                        'status' => Appointment::STATUS_CHECKED_IN,
                        'token_no' => ((int) $next) + 1,
                        'checked_in_at' => now(),
                    ]);

                    return $appointment;
                });
            } catch (QueryException $exception) {
                // Somebody else took that number between the read and the
                // write. Read again and take the next one.
                if (! str_contains($exception->getMessage(), 'appointments_token_unique')) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Could not issue a token. Please try again.');
    }

    public function start(Appointment $appointment): Appointment
    {
        $this->assertCanMoveTo($appointment, Appointment::STATUS_IN_CONSULTATION);

        $appointment->update([
            'status' => Appointment::STATUS_IN_CONSULTATION,
            'started_at' => now(),
        ]);

        return $appointment;
    }

    public function complete(Appointment $appointment): Appointment
    {
        $this->assertCanMoveTo($appointment, Appointment::STATUS_COMPLETED);

        $appointment->update([
            'status' => Appointment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return $appointment;
    }

    /**
     * Cancel.
     *
     * The token, if one was issued, is deliberately **not** returned to the
     * pool: reusing a number would make two patients share it in the day's
     * log, and the log is the thing anybody would go back to.
     */
    public function cancel(Appointment $appointment, ?string $reason = null): Appointment
    {
        $this->assertCanMoveTo($appointment, Appointment::STATUS_CANCELLED);

        $appointment->update([
            'status' => Appointment::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
        ]);

        return $appointment;
    }

    /**
     * Never arrived.
     *
     * Only from `booked` — somebody who checked in was here, whatever
     * happened next.
     */
    public function markNoShow(Appointment $appointment): Appointment
    {
        $this->assertCanMoveTo($appointment, Appointment::STATUS_NO_SHOW);

        $appointment->update(['status' => Appointment::STATUS_NO_SHOW]);

        return $appointment;
    }

    /**
     * The queue for one branch's day, optionally narrowed to one doctor.
     *
     * Branch-wide by default. A department with six doctors cannot be
     * understood one doctor at a time: the desk is looking for a patient, not
     * for a doctor's list, and a manager wants the room. Passing a doctor
     * narrows it, which is a filter rather than a prerequisite.
     *
     * Ordered by token, which is arrival order. Booked patients are not
     * given priority automatically: their slot is shown so the desk can call
     * somebody out of turn on purpose, which is a decision a person makes,
     * not a rule software should apply behind their back.
     */
    public function queue(?int $doctorId, int $locationId, Carbon $date)
    {
        return Appointment::on('organization')
            ->with(['customer', 'doctor', 'location'])
            ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
            ->where('location_id', $locationId)
            ->whereDate('appointment_date', $date->toDateString())

            /*
             * Still here first, finished after.
             *
             * Arrival order alone put a whole morning of completed
             * consultations above the handful of people actually waiting — by
             * noon the screen opened on twenty-six rows nobody could act on,
             * and the six that mattered were below the fold.
             *
             * This is NOT the auto-promotion the queue deliberately avoids.
             * That is about not floating a booked patient above a walk-in who
             * got here first, and it still holds: inside the live group, order
             * is arrival order and nothing reorders it. What moves is the
             * finished work, which is not in the queue in any sense a person
             * at the desk would recognise.
             */
            ->orderByRaw(
                "CASE status
                    WHEN ? THEN 0
                    WHEN ? THEN 1
                    WHEN ? THEN 2
                    ELSE 3
                 END",
                [
                    Appointment::STATUS_CHECKED_IN,
                    Appointment::STATUS_IN_CONSULTATION,
                    Appointment::STATUS_BOOKED,
                ]
            )
            ->orderByRaw('token_no NULLS LAST')
            ->orderBy('slot_at')
            ->get();
    }

    /**
     * The doctors who have somebody on their list at this branch today.
     *
     * Deliberately computed from the branch's WHOLE day, never from a queue
     * that has already been narrowed. Deriving it from the filtered list would
     * leave exactly one doctor in it the moment somebody filtered — so the
     * filter would collapse to the option already chosen and there would be no
     * way back to the whole branch.
     *
     * @return list<array{id: int, name: string|null}>
     */
    public function doctorsOn(int $locationId, Carbon $date): array
    {
        return Appointment::on('organization')
            ->with('doctor:id,name')
            ->where('location_id', $locationId)
            ->whereDate('appointment_date', $date->toDateString())
            ->get(['id', 'doctor_id'])
            ->map(fn (Appointment $row) => [
                'id' => (int) $row->doctor_id,
                'name' => $row->doctor?->name,
            ])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /** The session whose window contains this time, if any. */
    private function sessionOffering(
        Doctor $doctor,
        Carbon $date,
        int $locationId,
        string $slot,
    ): ?array {
        foreach ($this->openSlots($doctor, $date, $locationId) as $session) {
            if (in_array($slot, $session['slots'], true)) {
                return $session;
            }
        }

        return null;
    }

    private function assertCanMoveTo(Appointment $appointment, string $status): void
    {
        if (! $appointment->canMoveTo($status)) {
            throw new RuntimeException(
                "An appointment that is {$appointment->status} cannot become {$status}."
            );
        }
    }
}
