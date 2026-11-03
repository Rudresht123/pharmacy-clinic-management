<?php

namespace App\Services\Queue;

use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentQueueSetting;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorQueueSetting;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A doctor's queue settings at a branch, made when first needed.
 *
 * Reading makes one because a doctor nobody has configured must still get a
 * working queue the first time somebody checks a patient in for them, and that
 * first check-in should not be where a receptionist learns the screen exists.
 */
class QueueSettings
{
    public function forDoctor(int $doctorId, int $locationId): DoctorQueueSetting
    {
        return $this->find($doctorId, $locationId) ?? $this->make($doctorId, $locationId);
    }

    /**
     * The settings for several doctors at one branch, in one query.
     *
     * @param  list<int>  $doctorIds
     * @return Collection<int, DoctorQueueSetting> keyed by doctor id
     */
    public function forDoctors(array $doctorIds, int $locationId): Collection
    {
        $found = DoctorQueueSetting::query()
            ->where('location_id', $locationId)
            ->whereIn('doctor_id', $doctorIds)
            ->get()
            ->keyBy('doctor_id');

        foreach ($doctorIds as $doctorId) {
            if (! $found->has($doctorId)) {
                $found->put($doctorId, $this->make($doctorId, $locationId));
            }
        }

        return $found;
    }

    /**
     * The shared run this doctor's department hands out at this branch, if any.
     *
     * A sub-department falls under its parent's rule unless it has its own.
     */
    public function sharedRunFor(int $doctorId, int $locationId): ?DepartmentQueueSetting
    {
        $department = Doctor::query()->whereKey($doctorId)->value('department_id');

        if ($department === null) {
            return null;
        }

        $rules = DepartmentQueueSetting::query()->where('location_id', $locationId)->get()->keyBy('department_id');

        if ($rules->isEmpty()) {
            return null;
        }

        return $rules->get($department)
            ?? $rules->get(Department::query()->whereKey($department)->value('parent_id'));
    }

    /**
     * Change a doctor's settings at a branch.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(DoctorQueueSetting $setting, array $values): DoctorQueueSetting
    {
        if (array_key_exists('token_prefix', $values)) {
            $values['token_prefix'] = $this->normalisePrefix($values['token_prefix']);

            if ($values['token_prefix'] === null) {
                throw new RuntimeException('Every doctor needs a token prefix. Choose one, or keep the current one.');
            }

            $this->assertPrefixFree($values['token_prefix'], $setting->location_id, $setting->doctor_id);
        }

        $setting->fill($values)->save();

        return $setting;
    }

    /** Whether somebody else at this branch already hands out this letter. */
    public function assertPrefixFree(string $prefix, int $locationId, ?int $doctorId = null, ?int $departmentId = null): void
    {
        $doctors = DoctorQueueSetting::query()
            ->where('location_id', $locationId)
            ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', '<>', $doctorId))
            ->pluck('token_prefix');

        $departments = DepartmentQueueSetting::query()
            ->where('location_id', $locationId)
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', '<>', $departmentId))
            ->pluck('token_prefix');

        $taken = $doctors->merge($departments)->filter()->map(fn ($value) => mb_strtoupper((string) $value));

        if ($taken->contains(mb_strtoupper($prefix))) {
            throw new RuntimeException("Token prefix {$prefix} is already used at this branch. Choose another.");
        }
    }

    private function find(int $doctorId, int $locationId): ?DoctorQueueSetting
    {
        return DoctorQueueSetting::query()
            ->where('location_id', $locationId)
            ->where('doctor_id', $doctorId)
            ->first();
    }

    /**
     * Make the row, giving the doctor the next free letter at this branch.
     *
     * `insertOrIgnore`, not a plain insert: two check-ins for a doctor's very
     * first patient arrive together on a busy morning, and a unique violation
     * would abort the transaction around this. If somebody else's insert won
     * (the doctor's row, or the letter), reading again settles it.
     */
    private function make(int $doctorId, int $locationId): DoctorQueueSetting
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            DoctorQueueSetting::query()->insertOrIgnore([
                'location_id' => $locationId,
                'doctor_id' => $doctorId,
                'token_prefix' => $this->nextFreePrefix($locationId),
                'average_consultation_minutes' => DoctorQueueSetting::DEFAULT_AVERAGE_MINUTES,
                'auto_call_enabled' => false,
                'allow_priority' => true,
                'queue_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $made = $this->find($doctorId, $locationId);

            if ($made !== null) {
                return $made;
            }
        }

        throw new RuntimeException('Could not set up this doctor\'s queue. Please try again.');
    }

    /** A, B … Z, then AA, AB — the same order a spreadsheet's columns use. */
    private function nextFreePrefix(int $locationId): string
    {
        $taken = DoctorQueueSetting::query()->where('location_id', $locationId)->pluck('token_prefix')
            ->merge(DepartmentQueueSetting::query()->where('location_id', $locationId)->pluck('token_prefix'))
            ->filter()
            ->map(fn ($value) => mb_strtoupper((string) $value))
            ->flip();

        for ($prefix = 'A'; ; $prefix++) {
            if (! $taken->has($prefix)) {
                return $prefix;
            }
        }
    }

    private function normalisePrefix(mixed $value): ?string
    {
        $prefix = mb_strtoupper(trim((string) $value));

        return $prefix === '' ? null : $prefix;
    }
}
