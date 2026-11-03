<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Department;
use App\Models\Tenant\DepartmentQueueSetting;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorQueueSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\QueueEvent;
use App\Services\Queue\QueueBoard;
use App\Services\Queue\QueueJournal;
use App\Services\Queue\QueueSettings;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * How a branch's queues are set up: rooms, prefixes, and shared token runs.
 *
 * @group Tenant > Appointments & OPD
 */
class QueueSettingController extends BaseApiController
{
    public function __construct(
        private readonly QueueSettings $settings,
        private readonly QueueBoard $board,
        private readonly QueueJournal $journal,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** Every doctor posted to this branch, and any shared department runs. */
    public function index(Request $request): JsonResponse
    {
        $locationId = $this->location($request);

        $doctors = $this->board->postedAt($locationId);

        $settings = $this->settings->forDoctors(
            $doctors->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $locationId,
        );

        return $this->ok([
            'doctors' => $doctors
                ->map(fn (Doctor $doctor) => $this->shape($doctor, $settings->get($doctor->id)))
                ->values()
                ->all(),

            'departments' => Department::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (Department $department) => [
                    'id' => (int) $department->id,
                    'name' => $department->name,
                    'parent_id' => $department->parent_id,
                ])
                ->all(),

            'shared_runs' => DepartmentQueueSetting::query()
                ->where('location_id', $locationId)
                ->get()
                ->map(fn (DepartmentQueueSetting $rule) => [
                    'department_id' => (int) $rule->department_id,
                    'token_prefix' => $rule->token_prefix,
                ])
                ->all(),
        ]);
    }

    public function updateDoctor(Request $request, Doctor $doctor): JsonResponse
    {
        $locationId = $this->location($request);

        $data = $request->validate([
            'room_no' => ['nullable', 'string', 'max:30'],
            'token_prefix' => ['sometimes', 'required', 'string', 'regex:/^[A-Za-z]{1,4}$/'],
            'average_consultation_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'auto_call_enabled' => ['sometimes', 'boolean'],
            'allow_priority' => ['sometimes', 'boolean'],
            'queue_enabled' => ['sometimes', 'boolean'],
        ], [
            'token_prefix.regex' => 'A token prefix is one to four letters, such as A or CAR.',
        ]);

        try {
            $setting = $this->settings->update($this->settings->forDoctor((int) $doctor->id, $locationId), $data);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        // A new room belongs on the television now, not at the next call.
        $this->journal->queue((int) $doctor->id, $locationId, QueueEvent::SETTINGS_CHANGED, [
            'changed' => array_keys($data),
        ], $request->user());

        return $this->ok($this->shape($doctor->loadMissing('department'), $setting), 'Saved');
    }

    /** Give a department one shared run of numbers, or change its letter. */
    public function saveDepartment(Request $request, Department $department): JsonResponse
    {
        $locationId = $this->location($request);

        $data = $request->validate([
            'token_prefix' => ['required', 'string', 'regex:/^[A-Za-z]{1,4}$/'],
        ], [
            'token_prefix.regex' => 'A token prefix is one to four letters, such as A or CAR.',
        ]);

        $prefix = mb_strtoupper($data['token_prefix']);

        try {
            $this->settings->assertPrefixFree($prefix, $locationId, departmentId: (int) $department->id);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        DepartmentQueueSetting::query()->updateOrCreate(
            ['location_id' => $locationId, 'department_id' => $department->id],
            ['token_prefix' => $prefix],
        );

        return $this->ok(['department_id' => (int) $department->id, 'token_prefix' => $prefix], 'Saved');
    }

    /** Back to each doctor having their own run. Tokens already issued keep their numbers. */
    public function removeDepartment(Request $request, Department $department): JsonResponse
    {
        $locationId = $this->location($request);

        DepartmentQueueSetting::query()
            ->where('location_id', $locationId)
            ->where('department_id', $department->id)
            ->first()
            ?->delete();

        return $this->ok(null, 'Removed');
    }

    /** @return array<string, mixed> */
    private function shape(Doctor $doctor, DoctorQueueSetting $setting): array
    {
        return [
            'doctor_id' => (int) $doctor->id,
            'doctor_name' => $doctor->name,
            'department' => $doctor->department?->name ?? $doctor->specialisation,
            'room_no' => $setting->room_no,
            'token_prefix' => $setting->token_prefix,
            'average_consultation_minutes' => $setting->average_consultation_minutes,
            'auto_call_enabled' => $setting->auto_call_enabled,
            'allow_priority' => $setting->allow_priority,
            'queue_enabled' => $setting->queue_enabled,
        ];
    }

    private function location(Request $request): int
    {
        $data = $request->validate(['location_id' => ['required', 'integer', Rule::exists(Location::class, 'id')->whereNull('deleted_at')]]);

        if (! $this->branches->currentCanUse((int) $data['location_id'])) {
            abort(403, 'You can only work with the branch you are at.');
        }

        return (int) $data['location_id'];
    }
}
