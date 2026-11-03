<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentQueueSetting;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\QueueCounter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Issues the next token in a doctor's run for the day.
 *
 * Each doctor has their own sequence and their own prefix: A001, A002 for one,
 * B001, B002 for the next. Where a department shares a sequence
 * (DepartmentQueueSetting) its doctors take numbers from one counter instead.
 *
 * THE COUNTER ROW'S LOCK IS THE WHOLE GUARANTEE. Creating the row and locking it
 * are two steps because SELECT FOR UPDATE cannot lock a row that does not exist
 * yet: `insertOrIgnore` makes it safely (two first check-ins, one row), then the
 * lock makes everybody take turns. The lock is per doctor, so simultaneous
 * check-ins for different doctors never queue behind each other — they used to,
 * when a branch shared one run and the branch row was the lock.
 *
 * `appointments_token_unique` stays underneath as the backstop.
 *
 * Must run inside the caller's transaction, so a check-in that rolls back hands
 * its number back rather than leaving a gap.
 *
 * A CLINIC WITHOUT THE SMART QUEUE keeps what it always had: one plain run of
 * numbers across the branch, with the branch's row as the lock. Per-doctor tokens
 * are part of what the module sells, and not buying it must change nothing.
 */
class QueueTokenService
{
    public function __construct(
        private readonly QueueSettings $settings,
        private readonly QueueModule $module,
    ) {}

    /**
     * The next token for this doctor at this branch on this date.
     *
     * @return array{prefix: string|null, number: int}
     */
    public function next(int $doctorId, int $locationId, string $date): array
    {
        return (new QueueCounter)->getConnection()->transaction(function () use ($doctorId, $locationId, $date) {
            if (! $this->module->runsAt($locationId)) {
                return $this->nextAcrossTheBranch($locationId, $date);
            }

            $shared = $this->settings->sharedRunFor($doctorId, $locationId);

            $prefix = $shared?->token_prefix ?? $this->settings->forDoctor($doctorId, $locationId)->token_prefix;

            $owner = $shared
                ? ['department_id' => $shared->department_id, 'doctor_id' => null]
                : ['doctor_id' => $doctorId, 'department_id' => null];

            $run = fn (): Builder => QueueCounter::query()
                ->where('location_id', $locationId)
                ->where('queue_date', $date)
                ->where($owner);

            if (! $run()->exists()) {
                QueueCounter::query()->insertOrIgnore($owner + [
                    'location_id' => $locationId,
                    'queue_date' => $date,
                    'prefix' => $prefix,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $counter = $run()->lockForUpdate()->firstOrFail();

            /*
             * Never behind a number already on the books: tokens from before
             * this counter existed on the day it is deployed, a demo seeder, an
             * import. Read under the lock, so it is exact. Soft-deleted rows
             * count too, because a number once handed out is not reissued.
             */
            $highest = (int) Appointment::query()
                ->withTrashed()
                ->where('location_id', $locationId)
                ->where('appointment_date', $date)
                ->whereIn('doctor_id', $this->doctorsInRun($doctorId, $shared))
                ->max('token_no');

            $number = max($counter->last_number, $highest) + 1;

            $counter->forceFill(['last_number' => $number, 'prefix' => $prefix])->save();

            return ['prefix' => $prefix, 'number' => $number];
        });
    }

    /**
     * The branch's one run, for a clinic without the module.
     *
     * @return array{prefix: null, number: int}
     */
    private function nextAcrossTheBranch(int $locationId, string $date): array
    {
        Location::query()->whereKey($locationId)->lockForUpdate()->first();

        $highest = (int) Appointment::query()
            ->withTrashed()
            ->where('location_id', $locationId)
            ->where('appointment_date', $date)
            ->max('token_no');

        return ['prefix' => null, 'number' => $highest + 1];
    }

    /** @return list<int> */
    private function doctorsInRun(int $doctorId, ?DepartmentQueueSetting $shared): array
    {
        if ($shared === null) {
            return [$doctorId];
        }

        $departments = Department::query()
            ->where('parent_id', $shared->department_id)
            ->pluck('id')
            ->push($shared->department_id);

        return Doctor::query()->whereIn('department_id', $departments)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
