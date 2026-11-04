<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorQueueSetting;
use App\Services\Opd\AvailabilityService;
use App\Services\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Every doctor's queue, read whole: the board, the televisions, the channels.
 *
 * A doctor's CARD is the one shape all of them share. It holds tokens and counts
 * and nothing about any patient, because it is what goes to a television in a
 * public room. The desk's and the doctor's own screens add names on top of it
 * through `detail()`, behind a login, and never by way of a card.
 *
 * Read in batches: however many doctors are on a wall, the rows are one query
 * and the settings another, so a card costs arithmetic rather than a round trip.
 */
class QueueBoard
{
    /** How many upcoming tokens a card shows. */
    public const NEXT_SHOWN = 3;

    private const SESSION_CACHE_SECONDS = 300;

    public function __construct(
        private readonly QueueSettings $settings,
        private readonly EstimatedWaitingTimeService $estimates,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * The doctors a branch's queue screens show today.
     *
     * Active, posted here, with the queue on, and either sitting today or with
     * somebody in their queue: a doctor on leave has no business taking up a
     * card, and one covering an extra hour with patients waiting does.
     *
     * @return Collection<int, Doctor>
     */
    public function roster(int $locationId, string $date, ?int $departmentId = null): Collection
    {
        $doctors = $this->postedAt($locationId, $departmentId);

        if ($doctors->isEmpty()) {
            return $doctors;
        }

        $off = DoctorQueueSetting::query()
            ->where('location_id', $locationId)
            ->where('queue_enabled', false)
            ->pluck('doctor_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $busy = Appointment::query()
            ->where('location_id', $locationId)
            ->where('appointment_date', $date)
            ->whereNotNull('queue_status')
            ->inTheBuilding()
            ->distinct()
            ->pluck('doctor_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $doctors
            ->reject(fn (Doctor $doctor) => in_array($doctor->id, $off, true))
            ->filter(fn (Doctor $doctor) => in_array($doctor->id, $busy, true)
                || $this->sessions($doctor, $locationId, $date) !== [])
            ->values();
    }

    /**
     * Active doctors posted to this branch, whether sitting today or not.
     *
     * @return Collection<int, Doctor>
     */
    public function postedAt(int $locationId, ?int $departmentId = null): Collection
    {
        return Doctor::query()
            ->with('department')
            ->active()
            ->whereHas('postings', fn ($query) => $query
                ->where('locations.id', $locationId)
                ->where('doctor_locations.is_active', true))
            ->when($departmentId !== null, fn ($query) => $query->whereIn(
                'department_id',
                Department::query()->where('parent_id', $departmentId)->pluck('id')->push($departmentId),
            ))
            ->orderBy('name')
            ->get();
    }

    /**
     * Cards for these doctors, in the order given.
     *
     * @param  Collection<int, Doctor>  $doctors
     * @return list<array<string, mixed>>
     */
    public function cards(Collection $doctors, int $locationId, string $date, bool $forStaff = false): array
    {
        if ($doctors->isEmpty()) {
            return [];
        }

        $ids = $doctors->pluck('id')->map(fn ($id) => (int) $id)->all();

        // One query for a wall of doctors, rather than one per card.
        (new EloquentCollection($doctors->all()))->loadMissing('photograph');

        $settings = $this->settings->forDoctors($ids, $locationId);

        $rows = Appointment::query()
            ->where('location_id', $locationId)
            ->where('appointment_date', $date)
            ->whereIn('doctor_id', $ids)
            ->inTheBuilding()
            ->whereNotNull('queue_status')
            ->get([
                'id', 'doctor_id', 'customer_id', 'token_no', 'token_prefix', 'queue_status', 'queue_priority',
                'checked_in_at', 'called_at', 'started_at', 'mode', 'appointment_date',
            ]);

        // The only patients a card names, so the only ones worth loading.
        $rows->where('queue_status', Appointment::QUEUE_CALLED)->load('customer.photograph');

        $live = $rows->groupBy(fn (Appointment $row) => (int) $row->doctor_id);

        $tally = $forStaff ? $this->tallies($ids, $locationId, $date) : collect();

        return $doctors
            ->map(function (Doctor $doctor) use ($settings, $live, $locationId, $date, $forStaff, $tally) {
                $card = $this->cardFor(
                    $doctor,
                    $settings->get($doctor->id),
                    $live->get($doctor->id, collect()),
                    $locationId,
                    $date,
                );

                return $forStaff ? $card + $this->staffExtras($doctor, $live->get($doctor->id, collect()), $tally->get($doctor->id, collect())) : $card;
            })
            ->values()
            ->all();
    }

    /**
     * One doctor's card, for the message that tells a television it changed.
     *
     * @return array<string, mixed>
     */
    public function card(int $doctorId, int $locationId, string $date): array
    {
        $doctor = Doctor::query()->with('department')->withTrashed()->findOrFail($doctorId);

        return $this->cards(new Collection([$doctor]), $locationId, $date)[0];
    }

    /**
     * One doctor's whole day, for the screens behind a login.
     *
     * @return array<string, mixed>
     */
    public function detail(Doctor $doctor, int $locationId, string $date): array
    {
        $doctor->loadMissing(['department', 'photograph']);

        $setting = $this->settings->forDoctor($doctor->id, $locationId);

        $rows = Appointment::query()
            ->with(['customer.photograph'])
            ->where('doctor_id', $doctor->id)
            ->where('location_id', $locationId)
            ->where('appointment_date', $date)
            ->get();

        $inClinic = $rows->filter(fn (Appointment $row) => ! $row->isVideo());
        $live = $inClinic->whereNotNull('queue_status');

        $estimate = $this->estimates->estimate($setting, $live, $this->nextSessionStart($doctor, $locationId, $date));

        $waiting = QueueOrder::sort($live->where('queue_status', Appointment::QUEUE_WAITING));

        $row = fn (Appointment $visit) => $this->row($visit, $estimate['positions'][$visit->id] ?? null, $setting);

        return [
            'date' => $date,
            'card' => $this->cardFor($doctor, $setting, $live, $locationId, $date)
                + $this->staffExtras($doctor, $live, $rows),

            'settings' => [
                'room_no' => $setting->room_no,
                'token_prefix' => $setting->token_prefix,
                'average_consultation_minutes' => $setting->average_consultation_minutes,
                'auto_call_enabled' => $setting->auto_call_enabled,
                'allow_priority' => $setting->allow_priority,
                'queue_enabled' => $setting->queue_enabled,
                'paused' => $setting->isPaused(QueueClock::now()),
                'pause_reason' => $setting->isPaused(QueueClock::now()) ? $setting->pause_reason : null,
                'pause_until' => $setting->isPaused(QueueClock::now()) ? $setting->pause_until?->toIso8601String() : null,
            ],

            'with_doctor' => $live->where('queue_status', Appointment::QUEUE_WITH_DOCTOR)->values()->map($row)->all(),
            'called' => $live->where('queue_status', Appointment::QUEUE_CALLED)->sortByDesc('called_at')->values()->map($row)->all(),
            'waiting' => $waiting->map($row)->all(),
            'skipped' => $live->where('queue_status', Appointment::QUEUE_SKIPPED)->sortBy('skipped_at')->values()->map($row)->all(),

            // Booked, and nobody has checked them in. Where the desk finds who to check in.
            'expected' => $inClinic->where('status', Appointment::STATUS_BOOKED)->sortBy('slot_at')->values()->map($row)->all(),

            // Finished with this room, wherever the visit has gone since.
            'done' => $rows->where('consultation_status', Appointment::CONSULT_COMPLETED)
                ->sortByDesc('completed_at')->take(50)->values()->map($row)->all(),

            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $live
     * @return array<string, mixed>
     */
    private function cardFor(Doctor $doctor, DoctorQueueSetting $setting, Collection $live, int $locationId, string $date): array
    {
        $serving = $live->where('queue_status', Appointment::QUEUE_WITH_DOCTOR)->sortByDesc('started_at')->first();
        $called = $live->where('queue_status', Appointment::QUEUE_CALLED)->sortByDesc('called_at')->first();

        $waiting = QueueOrder::sort($live->where('queue_status', Appointment::QUEUE_WAITING));

        $availability = $this->availabilityOf($doctor, $locationId, $date);

        $estimate = $this->estimates->estimate($setting, $live, $availability['starts_at_time'] ?? null);

        return [
            'doctor_id' => (int) $doctor->id,
            'doctor_name' => $doctor->name,

            // The doctor's own picture, from the public disk. Never a patient's.
            'photo_url' => $doctor->photograph?->url,
            'department' => $doctor->department?->name ?? $doctor->specialisation,
            'room' => $setting->room_no,
            'prefix' => $setting->token_prefix,

            // Who the room is asking for: somebody just called, else whoever is in.
            'now_calling' => ($called ?? $serving)?->tokenLabel(),
            'now_calling_state' => $called ? 'called' : ($serving ? 'serving' : null),
            'serving' => $serving?->tokenLabel(),

            /*
             * The patient being called right now, so they know it is them: the
             * clinic's own choice for its waiting room. Nobody else is named: not
             * whoever is next, not whoever is already with the doctor, and never
             * a phone number.
             */
            'patient' => $called ? [
                'name' => $called->customer?->name,
                'age' => $called->customer?->date_of_birth?->age,
                'photo_url' => $called->customer?->photograph?->url,
            ] : null,

            'next' => $waiting->take(self::NEXT_SHOWN)->map(fn (Appointment $row) => $row->tokenLabel())->values()->all(),
            'waiting' => $waiting->count(),

            'paused' => $estimate['paused'],
            'availability' => $availability['state'],
            'session_starts_at' => $availability['starts_at'],

            'estimated_wait_minutes' => $estimate['for_next_arrival'],
            'average_minutes' => $estimate['average_minutes'],
        ];
    }

    /**
     * What only somebody signed in sees about a doctor's queue. Counts, still:
     * names come from `detail()`.
     *
     * @param  Collection<int, Appointment>  $live
     * @param  Collection<int, Appointment>  $day
     * @return array<string, mixed>
     */
    private function staffExtras(Doctor $doctor, Collection $live, Collection $day): array
    {
        $waiting = $live->where('queue_status', Appointment::QUEUE_WAITING);

        return [
            'specialisation' => $doctor->specialisation,
            'skipped' => $live->where('queue_status', Appointment::QUEUE_SKIPPED)->count(),
            'called' => $live->where('queue_status', Appointment::QUEUE_CALLED)->count(),
            'emergency' => $waiting->where('queue_priority', Appointment::PRIORITY_EMERGENCY)->count(),
            'urgent' => $waiting->where('queue_priority', Appointment::PRIORITY_URGENT)->count(),
            'longest_wait_minutes' => (int) $waiting
                ->map(fn (Appointment $row) => $row->checked_in_at?->diffInMinutes(now()) ?? 0)
                ->max(),
            'seen' => $day->where('consultation_status', Appointment::CONSULT_COMPLETED)->count(),
            'expected' => $day->where('status', Appointment::STATUS_BOOKED)->count(),
        ];
    }

    /**
     * The day's rows, for the counts the desk reads beside each doctor.
     *
     * @param  list<int>  $doctorIds
     * @return Collection<int, Collection<int, Appointment>>
     */
    private function tallies(array $doctorIds, int $locationId, string $date): Collection
    {
        return Appointment::query()
            ->where('location_id', $locationId)
            ->where('appointment_date', $date)
            ->whereIn('doctor_id', $doctorIds)
            ->get(['id', 'doctor_id', 'status', 'consultation_status'])
            ->groupBy(fn (Appointment $row) => (int) $row->doctor_id);
    }

    /**
     * One patient's row, for the desk and the doctor. Never goes to a television.
     *
     * @param  array{ahead: int, minutes: int}|null  $position
     * @return array<string, mixed>
     */
    private function row(Appointment $visit, ?array $position, DoctorQueueSetting $setting): array
    {
        $priority = $visit->queue_priority ?: Appointment::PRIORITY_NORMAL;

        return [
            'id' => $visit->id,
            'token' => $visit->tokenLabel(),
            'customer_id' => (int) $visit->customer_id,
            'customer_name' => $visit->customer?->name,
            'customer_code' => $visit->customer?->code,
            'customer_photo_url' => $visit->customer?->photograph?->url,
            'age' => $visit->customer?->date_of_birth?->age,
            'gender' => $visit->customer?->gender,

            'type' => $visit->type,
            'slot_at' => $visit->slot_at ? substr((string) $visit->slot_at, 0, 5) : null,

            'status' => $visit->status,
            'queue_status' => $visit->queue_status,
            'consultation_status' => $visit->consultation_status,
            'next_action' => $visit->next_action,

            'priority' => $priority,
            'priority_reason' => $visit->priority_reason,

            'waiting_minutes' => $visit->checked_in_at && ! $visit->started_at
                ? (int) $visit->checked_in_at->diffInMinutes(now())
                : null,
            'patients_ahead' => $position['ahead'] ?? null,
            'estimated_wait_minutes' => $position['minutes'] ?? null,

            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'called_at' => $visit->called_at?->toIso8601String(),
            'skipped_at' => $visit->skipped_at?->toIso8601String(),
            'started_at' => $visit->started_at?->toIso8601String(),
            'completed_at' => $visit->completed_at?->toIso8601String(),

            // Which verbs this row's STATE allows. The capability is the other half.
            'available' => [
                'check_in' => $visit->canMoveTo(Appointment::STATUS_CHECKED_IN),
                'call' => $visit->isWaiting(),
                'recall' => $visit->isCalled() || $visit->isSkipped(),
                'requeue' => $visit->isCalled() || $visit->isSkipped(),
                'skip' => $visit->isWaiting() || $visit->isCalled(),
                'absent' => $visit->isCalled() || $visit->isSkipped(),
                'consult_start' => $visit->isCalled() && $visit->consultation_status === Appointment::CONSULT_NOT_STARTED,
                'consult_complete' => $visit->consultation_status === Appointment::CONSULT_IN_PROGRESS,
                'reassign' => $visit->isWaiting() || $visit->isCalled() || $visit->isSkipped(),
                'priority' => $visit->isWaiting() || $visit->isCalled() || $visit->isSkipped(),
                'no_show' => $visit->canMoveTo(Appointment::STATUS_NO_SHOW),
                'cancel' => $visit->canMoveTo(Appointment::STATUS_CANCELLED),
            ],
        ];
    }

    /**
     * Where the doctor is in their day.
     *
     * @return array{state: string, starts_at: string|null, starts_at_time?: Carbon|null}
     */
    private function availabilityOf(Doctor $doctor, int $locationId, string $date): array
    {
        $sessions = $this->sessions($doctor, $locationId, $date);

        if ($sessions === []) {
            return ['state' => 'off', 'starts_at' => null];
        }

        $now = QueueClock::now();
        $clock = $now->format('H:i');

        foreach ($sessions as $session) {
            if ($clock >= $session['starts_at'] && $clock < $session['ends_at']) {
                return ['state' => 'in_session', 'starts_at' => $session['starts_at']];
            }
        }

        foreach ($sessions as $session) {
            if ($clock < $session['starts_at']) {
                return [
                    'state' => 'later',
                    'starts_at' => $session['starts_at'],
                    'starts_at_time' => $now->copy()->setTimeFromTimeString($session['starts_at']),
                ];
            }
        }

        return ['state' => 'finished', 'starts_at' => null];
    }

    private function nextSessionStart(Doctor $doctor, int $locationId, string $date): ?Carbon
    {
        return $this->availabilityOf($doctor, $locationId, $date)['starts_at_time'] ?? null;
    }

    /**
     * The doctor's sittings at this branch on this date, for a few minutes.
     *
     * The timetable moves far more slowly than the queue, and working it out
     * takes three queries a doctor, every time a wall of televisions asks.
     *
     * @return list<array<string, mixed>>
     */
    private function sessions(Doctor $doctor, int $locationId, string $date): array
    {
        return Cache::remember(
            'queue.sessions.'.CurrentTenant::database().".{$doctor->id}.{$locationId}.{$date}",
            self::SESSION_CACHE_SECONDS,
            fn () => array_map(
                fn (array $session) => [
                    'starts_at' => substr((string) $session['starts_at'], 0, 5),
                    'ends_at' => substr((string) $session['ends_at'], 0, 5),
                ],
                $this->availability->sessionsFor($doctor, Carbon::parse($date), $locationId),
            ),
        );
    }
}
