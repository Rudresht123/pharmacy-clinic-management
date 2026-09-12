<?php

namespace App\Services\Opd;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Support\Opd\Weekday;
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

    /**
     * Customer ids seen before today, for this request.
     *
     * Held on the instance because `row()` is called from three places and
     * threading it through all of them would say less than this does.
     *
     * @var list<int>
     */
    private array $returning = [];

    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Doctor $doctor, Carbon $date, ?int $locationId = null): array
    {
        $appointments = $this->dayFor($doctor, $date, $locationId);

        /*
         * Who has been here before today.
         *
         * "New" and "follow-up" are not stored anywhere — an appointment
         * records a booking, not whether the person is returning — so it is
         * answered by looking. One query for the whole list rather than one
         * per row.
         */
        $this->returning = $this->returningPatients($appointments, $date);

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
            'week' => $this->week($doctor),
            'upcoming' => $this->upcoming($appointments),

            // What the dashboard draws: this day by the hour, and the
            // fortnight leading up to it.
            'by_hour' => $this->byHour($appointments),
            'trend' => $this->trend($doctor, $date, $locationId),
        ];
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function dayFor(Doctor $doctor, Carbon $date, ?int $locationId): Collection
    {
        return Appointment::on('organization')
            ->with(['customer', 'location', 'consultation'])
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

            'new' => $appointments
                ->reject(fn (Appointment $row) => in_array(
                    (int) $row->customer_id,
                    $this->returning,
                    true,
                ))
                ->count(),

            'returning' => $appointments
                ->filter(fn (Appointment $row) => in_array(
                    (int) $row->customer_id,
                    $this->returning,
                    true,
                ))
                ->count(),

            'waiting' => $appointments->where('status', Appointment::STATUS_CHECKED_IN)->count(),

            // Booked, and nobody has checked them in yet.
            'expected' => $appointments->where('status', Appointment::STATUS_BOOKED)->count(),

            // Written up today with a return asked for. Counted off the
            // consultations already loaded, so it costs nothing.
            'follow_ups' => $appointments
                ->filter(fn (Appointment $row) => $row->consultation?->follow_up_days !== null)
                ->count(),

            'no_show' => $appointments->where('status', Appointment::STATUS_NO_SHOW)->count(),
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

            // Where they live, as one line — city and state read together or
            // not at all, and either may be missing.
            'where' => collect([$row->customer?->city, $row->customer?->state])
                ->filter()
                ->implode(', ') ?: null,

            // When the consultation began, so the card can say so rather than
            // only counting minutes since.
            'started_at' => $row->started_at?->format('H:i'),

            /*
             * Allergies, if this clinic records them.
             *
             * A patient's allergy belongs to the patient, not to one visit, so
             * it lives on the customer — as a configurable field, because not
             * every organization using this is a clinic. Absent rather than
             * empty when nobody has set the field up: "no allergies recorded"
             * and "we do not record allergies" are different, and a doctor must
             * not read the second as the first.
             */
            'allergies' => $this->allergiesOf($row),

            // How long they have been in, which is the number that tells a
            // doctor they are running late without anybody saying so.
            'in_room_minutes' => $row->started_at
                ? (int) $row->started_at->diffInMinutes(now())
                : null,

            /*
             * What has been written up so far, if anything.
             *
             * Sent with the day rather than fetched when the panel opens: the
             * doctor is already looking at this patient, and a second request
             * to find out whether their own notes exist is a spinner over the
             * thing they are mid-sentence in.
             */
            /*
             * What this patient has been seen for before.
             *
             * Five, newest first, and not the visit in front of them. A doctor
             * asking "have I seen this before" wants the last few lines, not a
             * file — the full record is a click away on the patient.
             */
            'history' => $this->historyFor($row),

            /*
             * What this doctor has written lately.
             *
             * A complaint and a diagnosis are typed dozens of times a week and
             * spelled differently each time, which makes a record nobody can
             * search later. Their own recent wording is the nearest thing to a
             * catalogue that needs no catalogue — and it is theirs, so it
             * matches how they write.
             */
            'suggestions' => $this->suggestionsFor($row),

            'consultation' => [
                'id' => $row->consultation?->id,
                'chief_complaint' => $row->consultation?->chief_complaint,
                'diagnoses' => $row->consultation?->diagnoses ?? [],
                'vitals' => $row->consultation?->vitals ?? [],
                'prescription' => $row->consultation?->prescription ?? [],
                'investigations' => $row->consultation?->investigations ?? [],
                'advice' => $row->consultation?->advice,
                'notes' => $row->consultation?->notes,
                'follow_up_days' => $row->consultation?->follow_up_days,
            ],
        ];
    }

    /**
     * This doctor's own recent complaints and diagnoses, most used first.
     *
     * @return array{complaints: list<string>, diagnoses: list<string>}
     */
    private function suggestionsFor(Appointment $row): array
    {
        $recent = Consultation::on('organization')
            ->where('doctor_id', $row->doctor_id)
            ->orderByDesc('created_at')
            ->limit(60)
            ->get(['chief_complaint', 'diagnoses']);

        $rank = fn (\Illuminate\Support\Collection $values) => $values
            ->filter()
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(8)
            ->values()
            ->all();

        return [
            'complaints' => $rank($recent->pluck('chief_complaint')),
            'diagnoses' => $rank($recent->pluck('diagnoses')->flatten()),
        ];
    }

    /**
     * Whatever this organization records as an allergy, if anything.
     *
     * Read from the configurable fields rather than a column: a clinic adds
     * "Allergies" in Settings and it appears here, and an organization that is
     * not a clinic never sees a field it has no use for.
     */
    private function allergiesOf(Appointment $row): ?string
    {
        $fields = $row->customer?->custom_fields ?? [];

        foreach (['allergies', 'allergy', 'known_allergies'] as $key) {
            $value = $fields[$key] ?? null;

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * This patient's last few consultations, excluding the one being written.
     *
     * @return list<array<string, mixed>>
     */
    private function historyFor(Appointment $row): array
    {
        if (! $row->customer_id) {
            return [];
        }

        return Consultation::on('organization')
            ->with('doctor')
            ->where('customer_id', $row->customer_id)
            ->where('appointment_id', '!=', $row->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Consultation $past) => [
                'id' => $past->id,
                'on' => $past->created_at?->toDateString(),
                'doctor_name' => $past->doctor?->name,
                'chief_complaint' => $past->chief_complaint,
                'diagnoses' => $past->diagnoses ?? [],
            ])
            ->all();
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

            // What the desk means by "new" or "follow-up": have we seen them
            // before, not how the appointment was made.
            'is_new' => ! in_array((int) $row->customer_id, $this->returning, true),
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
     * Who on this list has been here before today.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<int>
     */
    private function returningPatients(Collection $appointments, Carbon $date): array
    {
        $ids = $appointments->pluck('customer_id')->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Appointment::on('organization')
            ->whereIn('customer_id', $ids)
            ->whereDate('appointment_date', '<', $date->toDateString())
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Their usual week, wherever they sit.
     *
     * The weekly pattern rather than seven derived days: this answers "when am
     * I normally in", which is a question about the rota itself. What actually
     * happens on a date — leave applied, hours moved — is `schedule`, and the
     * two are different questions that look alike.
     *
     * Not scoped to a branch. A doctor covering three sites wants their week,
     * not the third of it that happens to be here.
     *
     * @return list<array<string, mixed>>
     */
    private function week(Doctor $doctor): array
    {
        $sittings = $doctor->schedules()
            ->with('location')
            ->where('is_active', true)
            ->orderBy('weekday')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('weekday');

        return collect(Weekday::all())
            ->map(fn (int $weekday) => [
                'weekday' => $weekday,
                'label' => Weekday::label($weekday),
                'sittings' => $sittings->get($weekday, collect())
                    ->map(fn (DoctorSchedule $row) => [
                        'starts_at' => substr((string) $row->starts_at, 0, 5),
                        'ends_at' => substr((string) $row->ends_at, 0, 5),
                        'name' => $row->name,
                        'location_name' => $row->location?->name,
                        'slot_minutes' => $row->slot_minutes,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /** How many days the trend covers, ending on the day asked about. */
    private const TREND_DAYS = 14;

    /**
     * How the day's bookings fall across the hours.
     *
     * By booked time only. A walk-in has no booked time, and the moment they
     * arrived is stored as a timestamp in the application's timezone rather
     * than as the clinic's wall-clock time a slot is written in, so placing
     * them in an hour here would put them in the wrong one. They are counted
     * separately instead, and the chart says so.
     *
     * Cancelled bookings are not load.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array{hours: list<array{hour: int, total: int}>, walk_ins: int}
     */
    private function byHour(Collection $appointments): array
    {
        $live = $appointments->reject(
            fn (Appointment $row) => $row->status === Appointment::STATUS_CANCELLED
        );

        $booked = $live->filter(fn (Appointment $row) => $row->slot_at !== null);

        return [
            'hours' => $booked
                ->countBy(fn (Appointment $row) => (int) substr((string) $row->slot_at, 0, 2))
                ->sortKeys()
                ->map(fn (int $total, int $hour) => ['hour' => $hour, 'total' => $total])
                ->values()
                ->all(),

            'walk_ins' => $live->count() - $booked->count(),
        ];
    }

    /**
     * The last fortnight, a day at a time, ending on the day asked about.
     *
     * One query, grouped in memory: a doctor's list is short. A day with
     * nothing booked is sent as zero rather than left out, so the days are
     * evenly spaced and a day off reads as a day off, not as a gap somebody
     * has to notice.
     *
     * @return list<array{date: string, total: int, seen: int}>
     */
    private function trend(Doctor $doctor, Carbon $date, ?int $locationId): array
    {
        $from = $date->copy()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $byDate = Appointment::on('organization')
            ->where('doctor_id', $doctor->id)
            ->whereDate('appointment_date', '>=', $from->toDateString())
            ->whereDate('appointment_date', '<=', $date->toDateString())
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->when($locationId, fn ($query, $id) => $query->where('location_id', $id))
            ->get(['appointment_date', 'status'])
            ->groupBy(fn (Appointment $row) => substr((string) $row->appointment_date, 0, 10));

        return collect(range(0, self::TREND_DAYS - 1))
            ->map(function (int $offset) use ($from, $byDate) {
                $day = $from->copy()->addDays($offset)->toDateString();
                $rows = $byDate->get($day, collect());

                return [
                    'date' => $day,
                    'total' => $rows->count(),
                    'seen' => $rows->where('status', Appointment::STATUS_COMPLETED)->count(),
                ];
            })
            ->all();
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
