<?php

namespace App\Services\Opd;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\DoctorScheduleException;
use App\Support\Opd\Weekday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * When a doctor is actually available on a given date.
 *
 * The one place that turns the weekly pattern into a day. Everything else —
 * the day view, and booking when it arrives — asks here rather than reading
 * `doctor_schedules` and re-deciding, because availability is four rules
 * layered on top of each other and getting one wrong in one place is how a
 * patient is booked with a doctor who is on leave:
 *
 *   1. the sittings for that weekday, active, inside their effective window
 *   2. minus anything cancelled by an exception (or the whole day, if the
 *      exception names no sitting)
 *   3. with changed hours applied to the sitting they name
 *   4. plus any extra session recorded for that date
 *
 * **Nothing is persisted.** Slots are generated when asked and thrown away.
 * Storing them would mean regenerating on every schedule edit and
 * reconciling bookings against rows that had moved underneath them — a
 * schedule is an availability window, not a set of appointments.
 */
class AvailabilityService
{
    /**
     * The sittings a doctor actually has on a date.
     *
     * Returns plain arrays rather than models: two of the four rules produce
     * something that is not a `doctor_schedules` row — a changed sitting is
     * not what the table says, and an extra session has no row there at all.
     * Handing back models would invite a caller to save one.
     *
     * @return list<array<string, mixed>>
     */
    public function sessionsFor(Doctor $doctor, Carbon $date, ?int $locationId = null): array
    {
        $exceptions = $this->exceptionsFor($doctor, $date);

        // Away for the whole day: nothing else needs deciding.
        if ($exceptions->contains(fn (DoctorScheduleException $e) => $e->isWholeDayOff())) {
            return [];
        }

        $sessions = $this->weeklySessions($doctor, $date, $locationId, $exceptions);

        foreach ($this->extraSessions($exceptions, $locationId) as $extra) {
            $sessions[] = $extra;
        }

        usort($sessions, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $sessions;
    }

    /**
     * The times a session divides into.
     *
     * The last slot has to *fit*: a 10:00–13:00 window at 45 minutes gives
     * four slots ending at 13:00, not a fifth that runs past closing.
     *
     * @return list<string> "10:00", "10:15", …
     */
    public function slotsFor(array $session): array
    {
        $step = max(1, (int) $session['slot_minutes']);

        $cursor = Carbon::createFromFormat('H:i', $session['starts_at']);
        $end = Carbon::createFromFormat('H:i', $session['ends_at']);

        $slots = [];

        while ($cursor->copy()->addMinutes($step)->lte($end)) {
            $slots[] = $cursor->format('H:i');
            $cursor->addMinutes($step);
        }

        return $slots;
    }

    /**
     * A whole branch's day: every doctor sitting there, with their slots.
     *
     * @return list<array<string, mixed>>
     */
    public function dayAtLocation(Carbon $date, int $locationId): array
    {
        $doctors = Doctor::on('organization')->where('is_active', true)->orderBy('name')->get();

        $day = [];

        foreach ($doctors as $doctor) {
            $sessions = $this->sessionsFor($doctor, $date, $locationId);

            if ($sessions === []) {
                continue;
            }

            $day[] = [
                'doctor_id' => $doctor->id,
                'doctor_name' => $doctor->name,
                'specialisation' => $doctor->specialisation,
                'sessions' => array_map(
                    fn (array $session) => [...$session, 'slots' => $this->slotsFor($session)],
                    $sessions,
                ),
            ];
        }

        return $day;
    }

    /**
     * @param  Collection<int, DoctorScheduleException>  $exceptions
     * @return list<array<string, mixed>>
     */
    private function weeklySessions(
        Doctor $doctor,
        Carbon $date,
        ?int $locationId,
        Collection $exceptions,
    ): array {
        $cancelled = $exceptions
            ->where('type', DoctorScheduleException::UNAVAILABLE)
            ->pluck('doctor_schedule_id')
            ->filter()
            ->all();

        $changed = $exceptions
            ->where('type', DoctorScheduleException::CHANGED_HOURS)
            ->keyBy('doctor_schedule_id');

        $schedules = DoctorSchedule::on('organization')
            ->with('location')
            ->where('doctor_id', $doctor->id)
            ->where('weekday', Weekday::of($date))
            ->where('is_active', true)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('starts_at')
            ->get();

        $sessions = [];

        foreach ($schedules as $schedule) {
            if (! $schedule->appliesOn($date) || in_array($schedule->id, $cancelled, true)) {
                continue;
            }

            $override = $changed->get($schedule->id);

            $sessions[] = [
                'schedule_id' => $schedule->id,
                'location_id' => $schedule->location_id,
                'location_name' => $schedule->location?->name,
                'name' => $schedule->name,
                'starts_at' => $this->clock($override->starts_at ?? $schedule->starts_at),
                'ends_at' => $this->clock($override->ends_at ?? $schedule->ends_at),
                'slot_minutes' => $override?->slot_minutes ?? $schedule->slot_minutes,
                'max_walkins' => $override?->max_walkins ?? $schedule->max_walkins,

                // So a screen can say *why* today looks different.
                'changed' => $override !== null,
                'reason' => $override?->reason,
            ];
        }

        return $sessions;
    }

    /**
     * @param  Collection<int, DoctorScheduleException>  $exceptions
     * @return list<array<string, mixed>>
     */
    private function extraSessions(Collection $exceptions, ?int $locationId): array
    {
        return $exceptions
            ->where('type', DoctorScheduleException::EXTRA_SESSION)
            ->when(
                $locationId !== null,
                fn (Collection $rows) => $rows->where('location_id', $locationId)
            )
            ->map(fn (DoctorScheduleException $extra) => [
                // No weekly row behind it, so nothing to point an appointment
                // at — Phase 2b records the location and time regardless.
                'schedule_id' => null,
                'location_id' => $extra->location_id,
                'location_name' => $extra->location?->name,
                'name' => $extra->reason ?: 'Extra session',
                'starts_at' => $this->clock($extra->starts_at),
                'ends_at' => $this->clock($extra->ends_at),
                'slot_minutes' => $extra->slot_minutes ?? 15,
                'max_walkins' => $extra->max_walkins,
                'changed' => true,
                'reason' => $extra->reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, DoctorScheduleException>
     */
    private function exceptionsFor(Doctor $doctor, Carbon $date): Collection
    {
        return DoctorScheduleException::on('organization')
            ->with('location')
            ->where('doctor_id', $doctor->id)
            ->whereDate('date', $date->toDateString())
            ->get();
    }

    /** Postgres returns "10:00:00"; every caller wants "10:00". */
    private function clock(?string $value): string
    {
        return $value ? substr($value, 0, 5) : '';
    }
}
