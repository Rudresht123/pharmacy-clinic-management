<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\DoctorQueueSetting;
use App\Services\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Roughly how long somebody will wait, one doctor at a time.
 *
 *   wait = patients ahead × that doctor's average consultation
 *        + what is left of the patient in the room
 *        + any time before the doctor can start at all
 *
 * The average is their own: the last consultations they actually finished,
 * once there are enough to mean anything, and the configured figure until then.
 *
 * Worked out when asked rather than stored. It changes with every minute the
 * patient in the room stays, so a stored copy would be wrong almost at once,
 * and recalculating on every change is simply what reading it fresh means.
 *
 * Every screen says "about": this is an estimate, never an appointment time.
 */
class EstimatedWaitingTimeService
{
    /** Below this many finished consultations, their own average is noise. */
    private const MIN_SAMPLE = 3;

    private const SAMPLE = 30;

    /** A consultation left open over lunch says nothing about how long they take. */
    private const LONGEST_BELIEVED_MINUTES = 120;

    private const CACHE_SECONDS = 300;

    /**
     * The arithmetic itself, with nothing to look up.
     */
    public function minutes(int $ahead, int $averageMinutes, int $remainingMinutes, int $delayMinutes = 0): int
    {
        return ($ahead * $averageMinutes) + max(0, $remainingMinutes) + max(0, $delayMinutes);
    }

    /**
     * This doctor's average consultation here, in whole minutes.
     *
     * Remembered for a few minutes: it moves slowly and a wall of televisions
     * asks constantly. Dropped the moment one of their consultations finishes.
     */
    public function averageMinutes(int $doctorId, int $locationId, int $configured): int
    {
        $measured = Cache::remember(
            $this->cacheKey($doctorId, $locationId),
            self::CACHE_SECONDS,
            fn () => $this->measure($doctorId, $locationId),
        );

        return $measured ?? max(1, $configured);
    }

    public function forget(int $doctorId, int $locationId): void
    {
        Cache::forget($this->cacheKey($doctorId, $locationId));
    }

    /**
     * What one doctor's queue looks like from where each person stands.
     *
     * @param  Collection<int, Appointment>  $live  everybody in their queue today
     * @return array{
     *     average_minutes: int,
     *     remaining_minutes: int,
     *     delay_minutes: int,
     *     paused: bool,
     *     for_next_arrival: int,
     *     positions: array<int, array{ahead: int, minutes: int}>,
     * }
     */
    public function estimate(
        DoctorQueueSetting $setting,
        Collection $live,
        ?Carbon $sessionStartsAt = null,
        ?Carbon $now = null,
    ): array {
        $now ??= QueueClock::now();

        $average = $this->averageMinutes($setting->doctor_id, $setting->location_id, $setting->average_consultation_minutes);

        $inRoom = $live->firstWhere('queue_status', Appointment::QUEUE_WITH_DOCTOR);

        $remaining = $inRoom?->started_at
            ? max(0, $average - (int) $inRoom->started_at->diffInMinutes($now))
            : 0;

        // Called and on their way: a whole consultation between them and the door.
        $called = $live->where('queue_status', Appointment::QUEUE_CALLED)->count();

        $waiting = QueueOrder::sort($live->where('queue_status', Appointment::QUEUE_WAITING));

        $delay = $this->delay($setting, $sessionStartsAt, $now);

        $positions = [];

        foreach ($waiting->values() as $index => $row) {
            $ahead = $called + $index;

            $positions[$row->id] = [
                'ahead' => $ahead,
                'minutes' => $this->minutes($ahead, $average, $remaining, $delay),
            ];
        }

        return [
            'average_minutes' => $average,
            'remaining_minutes' => $remaining,
            'delay_minutes' => $delay,
            'paused' => $setting->isPaused($now),
            'for_next_arrival' => $this->minutes($called + $waiting->count(), $average, $remaining, $delay),
            'positions' => $positions,
        ];
    }

    /**
     * Time the doctor cannot start in, whoever is waiting.
     *
     * A pause with an end counts up to that end. One with no end cannot be
     * counted, so it adds nothing and the screen says "paused" beside the
     * figure instead of inventing a number.
     */
    private function delay(DoctorQueueSetting $setting, ?Carbon $sessionStartsAt, Carbon $now): int
    {
        $delay = 0;

        if ($setting->isPaused($now) && $setting->pause_until !== null) {
            $delay = (int) ceil($now->diffInMinutes($setting->pause_until));
        }

        if ($sessionStartsAt !== null && $sessionStartsAt->isAfter($now)) {
            $delay = max($delay, (int) ceil($now->diffInMinutes($sessionStartsAt)));
        }

        return $delay;
    }

    private function measure(int $doctorId, int $locationId): ?int
    {
        $lengths = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->where('location_id', $locationId)
            ->where('consultation_status', Appointment::CONSULT_COMPLETED)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->orderByDesc('appointment_date')
            ->orderByDesc('completed_at')
            ->limit(self::SAMPLE)
            ->get(['started_at', 'completed_at'])
            ->map(fn (Appointment $row) => $row->started_at->diffInSeconds($row->completed_at) / 60)
            ->filter(fn ($minutes) => $minutes >= 1 && $minutes <= self::LONGEST_BELIEVED_MINUTES);

        if ($lengths->count() < self::MIN_SAMPLE) {
            return null;
        }

        return max(1, (int) round($lengths->avg()));
    }

    /** The database is part of the key: doctor 7 exists in every clinic. */
    private function cacheKey(int $doctorId, int $locationId): string
    {
        return 'queue.average.'.CurrentTenant::database().".{$doctorId}.{$locationId}";
    }
}
