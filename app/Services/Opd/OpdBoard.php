<?php

namespace App\Services\Opd;

use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One branch's OPD day, read whole.
 *
 * The queue answers "who is next for this doctor". This answers the questions
 * somebody standing in the middle of the department asks instead: how many are
 * waiting, who has been waiting too long, which doctors are free, and whether
 * today is busier than yesterday.
 *
 * Branch-wide on purpose. A department with six doctors cannot be understood
 * one doctor at a time, which is the shape the original queue endpoint forced.
 *
 * One request rather than eight. The dashboard is a single screen read at a
 * glance, and eight endpoints would let its halves disagree — the counts from
 * one moment beside a queue from another is exactly the bug a shared screen
 * makes hardest to notice.
 */
class OpdBoard
{
    /**
     * How long somebody may wait before the desk should be told.
     *
     * Stated here rather than hidden in a colour, so the interface can say
     * "over 20 minutes" out loud and the number has exactly one home.
     */
    public const WAIT_WARN = 10;

    public const WAIT_CRITICAL = 20;

    /** How many rows each panel shows before it starts saying "view all". */
    private const QUEUE_ROWS = 8;

    private const PANEL_ROWS = 5;

    public function __construct(
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * The branches this person may run an OPD day at.
     *
     * Deliberately not the locations endpoint. That one is guarded by
     * `branches.view`, which a receptionist has no reason to hold, and it
     * returns every branch in the organization including the ones this person
     * would be refused at. Offering a choice that always fails is worse than
     * offering none.
     *
     * @return Collection<int, Location>
     */
    public function branchesFor(?User $user): Collection
    {
        $allowed = $this->branches->allowed($user);

        return Location::query()
            ->active()
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->get();
    }

    /**
     * Everything the OPD dashboard shows, for one branch on one day.
     *
     * @return array<string, mixed>
     */
    public function for(int $locationId, Carbon $date): array
    {
        $appointments = $this->dayAt($locationId, $date);

        // Yesterday, for the two tiles that compare. Counted rather than
        // loaded whole: nothing on the screen shows a row from it.
        $before = $this->dayAt($locationId, $date->copy()->subDay());

        return [
            'date' => $date->toDateString(),
            'location_id' => $locationId,
            'branch_name' => Location::whereKey($locationId)->value('name'),

            // What the "Live · last updated" pill reads. From the server, so
            // a client with a wrong clock still reports the truth.
            'updated_at' => now()->toIso8601String(),

            'counts' => $this->counts($appointments, $before),
            'tabs' => $this->tabs($appointments),
            'queue' => $this->queue($appointments),
            'flow' => $this->flow($appointments, $date),
            'departments' => $this->departments($appointments),
            'waiting_longest' => $this->waitingLongest($appointments),

            // "Dr Amit has finished — token 13 is next." The whole of the
            // next-patient notification, and it costs nothing extra.
            'ready' => $this->ready($appointments),

            'doctors' => $this->doctors($appointments),
            'upcoming' => $this->upcoming($appointments),
            'activity' => $this->activity(),

            'thresholds' => [
                'warn' => self::WAIT_WARN,
                'critical' => self::WAIT_CRITICAL,
            ],
        ];
    }

    /**
     * One branch's appointments on one date, with everything the panels read.
     *
     * @return Collection<int, Appointment>
     */
    private function dayAt(int $locationId, Carbon $date): Collection
    {
        return Appointment::query()
            ->with(['customer', 'doctor'])
            ->where('location_id', $locationId)
            ->whereDate('appointment_date', $date->toDateString())
            ->orderByRaw('token_no NULLS LAST')
            ->orderBy('slot_at')
            ->get();
    }

    /* ------------------------------------------------------------- counts */

    /**
     * The five figures, each with the second fact that makes it actionable.
     *
     * "12 waiting" is a number. "12 waiting, 18 minutes on average" is a
     * reason to walk over to the desk.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, Appointment>  $before  the same branch yesterday
     * @return array<string, mixed>
     */
    private function counts(Collection $appointments, Collection $before): array
    {
        /*
         * Read off `queue_status` where the question is about the waiting
         * room, and off `status` where it is about the visit.
         *
         * `waiting` is now strictly "nobody has called them" — the people
         * whose wait is still growing and who somebody has to do something
         * about. Called patients are counted separately: they are on their
         * way in, and lumping them back into "waiting" would hide the exact
         * fact the desk just created.
         */
        $waiting = $appointments->where('queue_status', Appointment::QUEUE_WAITING);
        $called = $appointments->where('queue_status', Appointment::QUEUE_CALLED);
        $withDoctor = $appointments->where('status', Appointment::STATUS_IN_CONSULTATION);
        $expected = $appointments->where('status', Appointment::STATUS_BOOKED);

        /*
         * Two different "done", and the board shows both.
         *
         * `consulted` is the doctors' output — write-ups finished, whatever
         * is happening downstream. `completed` is visits that are actually
         * over. Before the split these were the same number; they are not,
         * and reporting only the second made a busy clinic with a slow
         * pharmacy look like one that had seen nobody.
         */
        $consulted = $appointments->where('consultation_status', Appointment::CONSULT_COMPLETED);
        $completed = $appointments->where('status', Appointment::STATUS_COMPLETED);

        /*
         * Everybody on today's list except the ones called off. A denominator
         * that counted cancellations could never be reached, so "27 of 32"
         * would be reporting a target rather than a total.
         */
        $onTheList = $appointments->where('status', '<>', Appointment::STATUS_CANCELLED);

        $wasOnTheList = $before->where('status', '<>', Appointment::STATUS_CANCELLED);
        $wasCompleted = $before->where('status', Appointment::STATUS_COMPLETED);

        $waits = $waiting->map(fn (Appointment $row) => $this->waitedMinutes($row));

        return [
            'total' => $this->withDelta($onTheList->count(), $wasOnTheList->count()),

            'waiting' => [
                'value' => $waiting->count(),
                'average_wait' => $waits->isEmpty() ? null : (int) round($waits->avg()),
                'longest_wait' => (int) $waits->max(),
            ],

            /*
             * Called and not yet taken in.
             *
             * Worth its own tile because a number that stays up is a
             * specific, fixable problem: somebody was called and has not
             * gone in. Either they did not hear, or the doctor is not ready.
             */
            'called' => [
                'value' => $called->count(),
                'longest_wait' => (int) $called
                    ->map(fn (Appointment $row) => $this->waitedMinutes($row))
                    ->max(),
            ],

            'in_consultation' => [
                'value' => $withDoctor->count(),

                // How many rooms are actually in use, which is not the same
                // number the moment a doctor is between patients.
                'doctors' => $withDoctor->pluck('doctor_id')->unique()->count(),
            ],

            // Write-ups finished today, wherever the patient is now.
            'consulted' => [
                'value' => $consulted->count(),

                /*
                 * Measured over consultations that have actually finished. An
                 * average over the ones still running would fall as each new
                 * patient is called in, which reads as the department speeding
                 * up at the exact moment it is not.
                 */
                'average_minutes' => $this->averageConsultation($consulted),
                'of_total' => $onTheList->count(),
            ],

            /*
             * Seen, and still in the building.
             *
             * The three states the doctor's work hands the patient on to.
             * Each one is a queue somebody else owns, and a board that did
             * not show them would let a patient sit in reception while the
             * pharmacy waited on a prescription nobody had told them about.
             */
            'awaiting' => [
                'pharmacy' => $appointments->where('status', Appointment::STATUS_AWAITING_PHARMACY)->count(),
                'laboratory' => $appointments->where('status', Appointment::STATUS_AWAITING_LAB)->count(),
                'payment' => $appointments->where('status', Appointment::STATUS_AWAITING_PAYMENT)->count(),
            ],

            // Visits that are actually over — everything downstream settled.
            'completed' => [
                ...$this->withDelta($completed->count(), $wasCompleted->count()),
                'average_minutes' => $this->averageConsultation($completed),
                'of_total' => $onTheList->count(),
            ],

            'no_show' => [
                'value' => $appointments->where('status', Appointment::STATUS_NO_SHOW)->count(),
                'of_total' => $onTheList->count(),
            ],

            'expected' => [
                'value' => $expected->count(),

                // Booked for a time that has passed and still not here — the
                // ones somebody should be ringing.
                'overdue' => $expected
                    ->filter(fn (Appointment $row) => $this->isOverdue($row))
                    ->count(),
            ],

            'cancelled' => [
                'value' => $appointments->where('status', Appointment::STATUS_CANCELLED)->count(),
            ],
        ];
    }

    /**
     * A figure beside what it was yesterday.
     *
     * The percentage is omitted rather than shown as a jump from nothing: a
     * clinic that saw nobody yesterday and four people today has not improved
     * by four hundred per cent, it has opened.
     *
     * @return array{value: int, delta: int, delta_pct: int|null}
     */
    private function withDelta(int $value, int $before): array
    {
        return [
            'value' => $value,
            'delta' => $value - $before,
            'delta_pct' => $before > 0 ? (int) round((($value - $before) / $before) * 100) : null,
        ];
    }

    /**
     * How many rows sit behind each filter.
     *
     * Counted from the whole day rather than from whatever is on screen, so
     * the numbers on the tabs do not change when one of them is pressed.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array<string, int>
     */
    private function tabs(Collection $appointments): array
    {
        return [
            /*
             * "All" means everybody still in play, and the three waiting
             * rooms are in play: a patient the pharmacy has not finished
             * with is somebody the department still has, not somebody who
             * has gone home.
             */
            'all' => $appointments->whereIn('status', [
                Appointment::STATUS_BOOKED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_CONSULTATION,
                ...Appointment::AWAITING,
            ])->count(),

            'checked_in' => $appointments->where('status', Appointment::STATUS_CHECKED_IN)->count(),

            // The desk's own two, split out of `checked_in` above — which
            // still counts both, because both are in the department.
            'waiting' => $appointments->where('queue_status', Appointment::QUEUE_WAITING)->count(),
            'called' => $appointments->where('queue_status', Appointment::QUEUE_CALLED)->count(),

            'in_consultation' => $appointments->where('status', Appointment::STATUS_IN_CONSULTATION)->count(),

            // One tab for all three waiting rooms: the desk's question is
            // "who is still here after seeing the doctor", not which of the
            // three queues each of them is in.
            'awaiting' => $appointments->whereIn('status', Appointment::AWAITING)->count(),

            'completed' => $appointments->where('status', Appointment::STATUS_COMPLETED)->count(),
            'booked' => $appointments->where('status', Appointment::STATUS_BOOKED)->count(),
        ];
    }

    /* -------------------------------------------------------------- panels */

    /**
     * The queue itself, trimmed to what a dashboard can show.
     *
     * Capped PER STATUS, not overall.
     *
     * Taking the first eight of the whole day sorted live-first meant that on
     * any busy morning all eight were people still waiting — so the Completed
     * tab said 24 and showed nothing, because not one completed row had been
     * sent. Every tab now has rows behind it, and the tab's own count stays the
     * true total for the day, so the screen says "showing 8 of 24" rather than
     * quietly disagreeing with itself.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function queue(Collection $appointments): array
    {
        $rank = [
            Appointment::STATUS_CHECKED_IN => 0,
            Appointment::STATUS_IN_CONSULTATION => 1,
            Appointment::STATUS_BOOKED => 2,

            /*
             * Above finished work, below anybody still to be seen. These are
             * the desk's second job — telling a patient where to go next —
             * and burying them under the morning's completed visits is how
             * somebody ends up sitting in reception with nobody realising
             * the pharmacy is waiting on them.
             */
            Appointment::STATUS_AWAITING_PHARMACY => 3,
            Appointment::STATUS_AWAITING_LAB => 3,
            Appointment::STATUS_AWAITING_PAYMENT => 3,

            Appointment::STATUS_COMPLETED => 4,
            Appointment::STATUS_NO_SHOW => 5,
            Appointment::STATUS_CANCELLED => 6,
        ];

        return $appointments
            ->sortBy([
                fn (Appointment $a, Appointment $b) => ($rank[$a->status] ?? 9) <=> ($rank[$b->status] ?? 9),
                fn (Appointment $a, Appointment $b) => ($a->token_no ?? 999) <=> ($b->token_no ?? 999),
            ])
            ->groupBy('status')
            ->map(fn (Collection $ofOneStatus) => $ofOneStatus->take(self::QUEUE_ROWS))
            ->flatten(1)
            ->sortBy(fn (Appointment $row) => $rank[$row->status] ?? 9)
            ->map(fn (Appointment $row) => [
                'id' => $row->id,
                'token_no' => $row->token_no,
                'customer_name' => $row->customer?->name,
                'customer_code' => $row->customer?->code,

                // Age is a fact about today, so it is worked out per request
                // rather than stored — a cached one is wrong on a birthday.
                'age' => $row->customer?->date_of_birth?->age,
                'gender' => $row->customer?->gender,

                'doctor_name' => $row->doctor?->name,

                'status' => $row->status,
                'queue_status' => $row->queue_status,
                'consultation_status' => $row->consultation_status,
                'next_action' => $row->next_action,

                'type' => $row->type,
                'slot_at' => $row->slot_at ? substr((string) $row->slot_at, 0, 5) : null,
                'waiting_minutes' => $row->status === Appointment::STATUS_CHECKED_IN
                    ? $this->waitedMinutes($row)
                    : null,
                'called_at' => $row->called_at?->toIso8601String(),

                'next_states' => Appointment::TRANSITIONS[$row->status] ?? [],

                // What this row's state allows. The screen renders a button
                // when this says possible AND the viewer holds the capability.
                'available' => [
                    'check_in' => $row->canMoveTo(Appointment::STATUS_CHECKED_IN),
                    'call' => $row->queueCanMoveTo(Appointment::QUEUE_CALLED),
                    'cancel' => $row->canMoveTo(Appointment::STATUS_CANCELLED),
                    'no_show' => $row->canMoveTo(Appointment::STATUS_NO_SHOW),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * How many came through the door each hour, new against returning.
     *
     * Arrivals rather than queue depth. An earlier version plotted how many
     * were *waiting* at each hour, which answers a sharper question — is the
     * queue getting away from us — but it cannot be split into new and
     * follow-up in a way that means anything: whether the people stuck in a
     * queue happen to be first-timers says nothing about either. Flow through
     * the door does split, and it is what "patient flow" reads as.
     *
     * The whole clinic day is drawn, not only the hours with somebody in
     * them. A quiet eleven o'clock between two busy hours is information; an
     * axis that silently closes the gap is a different chart.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function flow(Collection $appointments, Carbon $date): array
    {
        $arrivals = $appointments->filter(fn (Appointment $row) => $row->checked_in_at !== null);

        if ($arrivals->isEmpty()) {
            return [];
        }

        $returning = $this->returningPatients($appointments, $date);

        $from = $arrivals->min('checked_in_at')->copy()->startOfHour();

        /*
         * Up to now on today, and to the last arrival on any other day — a
         * finished Tuesday should not draw a row of empty hours out to
         * whenever somebody happens to be looking at it.
         */
        $to = $date->isToday()
            ? now()->startOfHour()
            : $arrivals->max('checked_in_at')->copy()->startOfHour();

        $buckets = [];
        $at = $from->copy();

        // A clinic day is not twenty hours long; the cap guards against one
        // stray timestamp stretching the axis until every real bar is a line.
        while ($at <= $to && count($buckets) < 14) {
            $hour = $at->copy();
            $end = $hour->copy()->endOfHour();

            $came = $arrivals->filter(
                fn (Appointment $row) => $row->checked_in_at >= $hour
                    && $row->checked_in_at <= $end
            );

            $followUp = $came
                ->filter(fn (Appointment $row) => in_array($row->customer_id, $returning, true))
                ->count();

            $buckets[] = [
                'label' => $this->hourLabel($hour),
                'title' => $this->hourLabel($hour),
                'value' => $came->count(),
                'returning' => $followUp,
                'fresh' => $came->count() - $followUp,
            ];

            $at->addHour();
        }

        return $buckets;
    }

    /** "8 AM", "12 PM" — the axis tick, as a clock is read rather than a log. */
    private function hourLabel(Carbon $hour): string
    {
        return strtoupper($hour->format('g A'));
    }

    /**
     * Which of today's patients had been here before.
     *
     * There is no visit type on an appointment yet, so "new" and "follow-up"
     * are read off the record itself: somebody with an earlier appointment is
     * returning. That is the honest version of the distinction, and it stops
     * being a guess the moment Phase 3 gives visits a type of their own.
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

        return Appointment::query()
            ->whereIn('customer_id', $ids)
            ->whereDate('appointment_date', '<', $date->toDateString())
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Today's list split by what the doctors do.
     *
     * Specialisation rather than a departments table, which does not exist —
     * and while every doctor has one, it answers the same question without a
     * second place for the truth to live. Doctors with none are grouped rather
     * than dropped, or the shares would not add up to the total beside them.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function departments(Collection $appointments): array
    {
        $onTheList = $appointments->where('status', '<>', Appointment::STATUS_CANCELLED);
        $total = $onTheList->count();

        if ($total === 0) {
            return [];
        }

        return $onTheList
            ->groupBy(fn (Appointment $row) => $row->doctor?->specialisation ?: 'Other')
            ->map(fn (Collection $rows, string $label) => [
                'label' => $label,
                'value' => $rows->count(),
                'share' => (int) round(($rows->count() / $total) * 100),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * Who has been waiting longest, worst first.
     *
     * A prompt to act, not a second copy of the queue — a longer list would
     * just be the queue in a different order.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function waitingLongest(Collection $appointments): array
    {
        return $appointments
            ->where('status', Appointment::STATUS_CHECKED_IN)
            ->map(fn (Appointment $row) => [
                'id' => $row->id,
                'token_no' => $row->token_no,
                'customer_name' => $row->customer?->name,
                'doctor_name' => $row->doctor?->name,
                'waiting_minutes' => $this->waitedMinutes($row),
            ])
            ->sortByDesc('waiting_minutes')
            ->take(self::PANEL_ROWS)
            ->values()
            ->all();
    }

    /**
     * Which doctors are free, and who should go in next.
     *
     * THE NEXT-PATIENT NOTIFICATION, and deliberately built as a fact on the
     * board rather than as a message pushed anywhere. The desk already polls
     * this endpoint every few seconds; a doctor finishing changes what it
     * says, and the screen can raise a toast, a badge, a sound or all three
     * off the change. Nothing new has to be running for it to work, which is
     * the difference between a feature that ships and a WebSocket server
     * somebody has to keep alive.
     *
     * A doctor appears here only when BOTH are true: nobody is in their room,
     * and somebody is in the department for them. A doctor with an empty list
     * is not "ready", they are finished, and a notification that fires for
     * them is one the desk learns to ignore.
     *
     * `finished_at` is when their last consultation ended. It is what lets
     * the screen tell "just now" from "this has been true for ten minutes"
     * and notify once rather than on every poll.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function ready(Collection $appointments): array
    {
        return $appointments
            ->groupBy('doctor_id')
            ->reject(fn (Collection $theirs) => $theirs->contains(
                fn (Appointment $row) => $row->status === Appointment::STATUS_IN_CONSULTATION
            ))
            ->map(function (Collection $theirs) {
                /*
                 * Who to call, in the order the desk would pick.
                 *
                 * Already-called first — somebody told them to come, and
                 * they have not gone in — then the longest wait. NOT the
                 * lowest token, which would call somebody who booked for
                 * later ahead of a walk-in who has been sitting there half
                 * an hour.
                 */
                $next = $theirs
                    ->filter(fn (Appointment $row) => $row->queue_status !== null)
                    ->sortBy([
                        fn (Appointment $a, Appointment $b) => ($a->isCalled() ? 0 : 1) <=> ($b->isCalled() ? 0 : 1),
                        fn (Appointment $a, Appointment $b) => $this->waitedMinutes($b) <=> $this->waitedMinutes($a),
                    ])
                    ->first();

                if (! $next) {
                    return null;
                }

                $lastDone = $theirs
                    ->where('consultation_status', Appointment::CONSULT_COMPLETED)
                    ->max('completed_at');

                return [
                    'doctor_id' => (int) $next->doctor_id,
                    'doctor_name' => $next->doctor?->name,

                    'finished_at' => $lastDone?->toIso8601String(),

                    'next' => [
                        'id' => $next->id,
                        'token_no' => $next->token_no,
                        'customer_name' => $next->customer?->name,
                        'queue_status' => $next->queue_status,
                        'waiting_minutes' => $this->waitedMinutes($next),
                    ],

                    'waiting' => $theirs->where('queue_status', Appointment::QUEUE_WAITING)->count(),
                ];
            })
            ->filter()
            ->sortByDesc('finished_at')
            ->values()
            ->all();
    }

    /**
     * Every doctor with somebody on their list today, and what they are doing.
     *
     * Built from the day's appointments rather than from the roster: a doctor
     * who is rostered but has nobody booked is not what the board is for, and
     * one covering an extra session is, whatever the weekly pattern says.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function doctors(Collection $appointments): array
    {
        return $appointments
            ->groupBy('doctor_id')
            ->map(function (Collection $theirs, $doctorId) {
                $doctor = $theirs->first()->doctor;

                $withPatient = $theirs->firstWhere('status', Appointment::STATUS_IN_CONSULTATION);
                $waiting = $theirs->where('status', Appointment::STATUS_CHECKED_IN);

                // Their own output, not visits that happen to have closed —
                // a patient still at the pharmacy was seen by this doctor.
                $done = $theirs->where('consultation_status', Appointment::CONSULT_COMPLETED);

                return [
                    'id' => (int) $doctorId,
                    'name' => $doctor?->name,
                    'specialisation' => $doctor?->specialisation,

                    /*
                     * Three states, and no fourth. "Free" here means nobody is
                     * in the room — not that the doctor is idle, which this
                     * has no way of knowing.
                     */
                    'state' => $withPatient
                        ? 'with_patient'
                        : ($waiting->isNotEmpty() ? 'free' : 'clear'),

                    'with' => $withPatient?->customer?->name,
                    'waiting' => $waiting->count(),
                    'seen' => $done->count(),
                    'average_minutes' => $this->averageConsultation($done),

                    'longest_wait' => (int) $waiting
                        ->map(fn (Appointment $row) => $this->waitedMinutes($row))
                        ->max(),
                ];
            })
            ->sortByDesc('waiting')
            ->values()
            ->all();
    }

    /**
     * Booked, not yet arrived — the next few, by their promised time.
     *
     * Only the ones still to come on today; a past day's un-arrived bookings
     * are all "upcoming" in a way nobody can act on.
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
            ->map(fn (Appointment $row) => [
                'id' => $row->id,
                'slot_at' => substr((string) $row->slot_at, 0, 5),
                'customer_name' => $row->customer?->name,
                'doctor_name' => $row->doctor?->name,
                'specialisation' => $row->doctor?->specialisation,
                'overdue' => $this->isOverdue($row),
            ])
            ->take(self::PANEL_ROWS)
            ->values()
            ->all();
    }

    /**
     * What has just happened, from the audit log the whole tenant writes to.
     *
     * Not filtered to this branch: the log records what was done to a record,
     * and plenty of records worth mentioning here — a patient registered, a
     * doctor's timings changed — belong to no branch at all.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        return ActivityLog::query()
            ->orderByDesc('created_at')
            ->limit(self::PANEL_ROWS)
            ->get()
            ->map(fn (ActivityLog $row) => [
                'id' => $row->id,
                'event' => $row->event,
                'entity_type' => class_basename($row->entity_type),
                'entity_label' => $row->entity_label,
                'actor_name' => $row->actor_name,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /* -------------------------------------------------------------- shared */

    /** Minutes since check-in, for somebody who has not been called in yet. */
    private function waitedMinutes(Appointment $appointment): int
    {
        if (! $appointment->checked_in_at || $appointment->started_at) {
            return 0;
        }

        return (int) $appointment->checked_in_at->diffInMinutes(now());
    }

    /**
     * The mean consultation length, in whole minutes.
     *
     * @param  Collection<int, Appointment>  $completed
     */
    private function averageConsultation(Collection $completed): ?int
    {
        $lengths = $completed
            ->filter(fn (Appointment $row) => $row->started_at && $row->completed_at)
            ->map(fn (Appointment $row) => (int) $row->started_at->diffInMinutes($row->completed_at));

        return $lengths->isEmpty() ? null : (int) round($lengths->avg());
    }

    /**
     * Booked for a time that has passed, and still not here.
     *
     * Only meaningful today: yesterday's un-arrived bookings are all overdue
     * in a way nobody can act on, and tomorrow's are none of them.
     */
    private function isOverdue(Appointment $appointment): bool
    {
        if (! $appointment->slot_at || ! $appointment->appointment_date->isToday()) {
            return false;
        }

        return substr((string) $appointment->slot_at, 0, 5) < now()->format('H:i');
    }
}
