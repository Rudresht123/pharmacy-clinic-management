<?php

namespace App\Services\Opd;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One doctor's own day.
 *
 * The OPD board answers "how is the clinic going", which is a manager's
 * question. This answers "who is next, and who is in front of me" — the two a
 * doctor asks between patients, and the reason a doctor signing in should not
 * land on a screen counting every consulting room in the branch.
 *
 * Everything here is scoped to one doctor and one date, and nothing is stored:
 * the queue, the waits and the schedule are all worked out per request from
 * appointments and the rota.
 */
class DoctorDay
{
    /** How many rows of the queue to hand back. A doctor's list is short. */
    private const ROWS = 25;

    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Doctor $doctor, Carbon $date, ?int $locationId = null): array
    {
        $appointments = $this->dayFor($doctor, $date, $locationId);

        return [
            'date' => $date->toDateString(),

            'doctor' => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'specialisation' => $doctor->specialisation,
                'photo_url' => $doctor->photograph?->url,
            ],

            'counts' => $this->counts($appointments),
            'queue' => $this->queue($appointments),
            'current' => $this->current($appointments),
            'schedule' => $this->schedule($doctor, $date, $locationId),
            'upcoming' => $this->upcoming($appointments),
        ];
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function dayFor(Doctor $doctor, Carbon $date, ?int $locationId): Collection
    {
        return Appointment::on('organization')
            ->with(['customer', 'location'])
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->when($locationId, fn ($query, $id) => $query->where('location_id', $id))
            ->get();
    }

    /**
     * The four numbers a doctor actually works from.
     *
     * "Follow-ups due" is not among them, and deliberately: nothing in the
     * schema records that a follow-up was asked for, so a count of them would
     * be a number with no source. "Yet to arrive" is the honest fourth — it is
     * the difference between a quiet morning and one that is about to arrive
     * all at once.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, int>
     */
    private function counts(Collection $appointments): array
    {
        return [
            'total' => $appointments
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED])
                ->count(),

            'seen' => $appointments->where('status', Appointment::STATUS_COMPLETED)->count(),

            'waiting' => $appointments->where('status', Appointment::STATUS_CHECKED_IN)->count(),

            // Booked, and nobody has checked them in yet.
            'expected' => $appointments->where('status', Appointment::STATUS_BOOKED)->count(),
        ];
    }

    /**
     * Their list, in the order they will be seen.
     *
     * Whoever is in the room first, then whoever is waiting by token, then
     * what is still expected. The finished and the gone drop to the bottom:
     * they are the record of the morning rather than the work left in it.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function queue(Collection $appointments): array
    {
        $rank = [
            Appointment::STATUS_IN_CONSULTATION => 0,
            Appointment::STATUS_CHECKED_IN => 1,
            Appointment::STATUS_BOOKED => 2,
            Appointment::STATUS_COMPLETED => 3,
            Appointment::STATUS_NO_SHOW => 4,
            Appointment::STATUS_CANCELLED => 5,
        ];

        return $appointments
            ->sortBy([
                fn (Appointment $a, Appointment $b) => ($rank[$a->status] ?? 9) <=> ($rank[$b->status] ?? 9),
                fn (Appointment $a, Appointment $b) => ($a->token_no ?? 999) <=> ($b->token_no ?? 999),
            ])
            ->take(self::ROWS)
            ->map(fn (Appointment $row) => $this->row($row))
            ->values()
            ->all();
    }

    /**
     * Whoever is in the room, if anybody is.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, mixed>|null
     */
    private function current(Collection $appointments): ?array
    {
        $row = $appointments->firstWhere('status', Appointment::STATUS_IN_CONSULTATION);

        if (! $row) {
            return null;
        }

        return [
            ...$this->row($row),
            'customer_id' => $row->customer_id,
            'phone' => $row->customer?->phone,
            'location_name' => $row->location?->name,

            // How long they have been in, which is the number that tells a
            // doctor they are running late without anybody saying so.
            'in_room_minutes' => $row->started_at
                ? (int) $row->started_at->diffInMinutes(now())
                : null,
        ];
    }

    /** One row of the list, shaped the same wherever it appears. */
    private function row(Appointment $row): array
    {
        return [
            'id' => $row->id,
            'token_no' => $row->token_no,
            'customer_name' => $row->customer?->name,
            'customer_code' => $row->customer?->code,

            // Age is a fact about today, so it is worked out per request
            // rather than stored — a cached one is wrong on a birthday.
            'age' => $row->customer?->date_of_birth?->age,
            'gender' => $row->customer?->gender,

            'status' => $row->status,
            'type' => $row->type,
            'slot_at' => $row->slot_at ? substr((string) $row->slot_at, 0, 5) : null,

            'waiting_minutes' => $row->status === Appointment::STATUS_CHECKED_IN
                ? $this->waitedMinutes($row)
                : null,

            'next_states' => Appointment::TRANSITIONS[$row->status] ?? [],
        ];
    }

    /**
     * How long somebody has been sitting there.
     *
     * Carbon 3 returns a float from `diffInMinutes` where Carbon 2 returned an
     * int, and a wait of 100.86940301666667 minutes reached a screen once
     * already. Cast, not rounded on the way out.
     */
    private function waitedMinutes(Appointment $row): int
    {
        return (int) ($row->checked_in_at?->diffInMinutes(now()) ?? 0);
    }

    /**
     * Today's sittings, and which one is running.
     *
     * Derived like everything else — a sitting cancelled by an exception is
     * simply not here, so the strip cannot promise a clinic that is not
     * happening.
     *
     * @return list<array<string, mixed>>
     */
    private function schedule(Doctor $doctor, Carbon $date, ?int $locationId): array
    {
        $now = now();

        return array_map(function (array $session) use ($date, $now) {
            $starts = $date->copy()->setTimeFromTimeString($session['starts_at']);
            $ends = $date->copy()->setTimeFromTimeString($session['ends_at']);

            return [
                'starts_at' => $session['starts_at'],
                'ends_at' => $session['ends_at'],
                'name' => $session['name'],
                'location_name' => $session['location_name'],
                'changed' => $session['changed'],

                'state' => $now->between($starts, $ends)
                    ? 'now'
                    : ($now->lt($starts) ? 'later' : 'done'),
            ];
        }, $this->availability->sessionsFor($doctor, $date, $locationId));
    }

    /**
     * What is booked and still to come, by the clock.
     *
     * Only the ones with a time on them: a walk-in has a token rather than an
     * appointment, and listing it here would put a person who is already in
     * the building under a heading about later.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function upcoming(Collection $appointments): array
    {
        return $appointments
            ->where('status', Appointment::STATUS_BOOKED)
            ->filter(fn (Appointment $row) => $row->slot_at !== null)
            ->sortBy('slot_at')
            ->take(6)
            ->map(fn (Appointment $row) => [
                'id' => $row->id,
                'slot_at' => substr((string) $row->slot_at, 0, 5),
                'customer_name' => $row->customer?->name,
                'type' => $row->type,
            ])
            ->values()
            ->all();
    }
}
