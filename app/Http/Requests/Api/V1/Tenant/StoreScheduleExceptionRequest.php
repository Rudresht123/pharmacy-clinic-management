<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\DoctorScheduleException;
use App\Models\Tenant\Location;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Leave, a holiday, moved hours, or an extra clinic on one date.
 *
 * The three types want genuinely different fields, so the rules are built
 * per type rather than making everything nullable and hoping the caller
 * sends a sensible combination.
 */
class StoreScheduleExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $type = $this->input('type');

        return [
            'doctor_id' => [
                'required', 'integer',
                Rule::exists(Doctor::class, 'id')->whereNull('deleted_at'),
            ],

            'date' => ['required', 'date'],
            'type' => ['required', Rule::in(DoctorScheduleException::TYPES)],

            /*
             * Which sitting. Required when changing hours — there is nothing
             * to change otherwise — and refused for an extra session, which
             * by definition is not in the weekly pattern.
             */
            'doctor_schedule_id' => [
                $type === DoctorScheduleException::CHANGED_HOURS ? 'required' : 'nullable',
                $type === DoctorScheduleException::EXTRA_SESSION ? 'prohibited' : 'integer',
                Rule::exists(DoctorSchedule::class, 'id'),
            ],

            'location_id' => [
                $type === DoctorScheduleException::EXTRA_SESSION ? 'required' : 'nullable',
                'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],

            'starts_at' => [
                $type === DoctorScheduleException::UNAVAILABLE ? 'nullable' : 'required',
                'date_format:H:i',
            ],
            'ends_at' => [
                $type === DoctorScheduleException::UNAVAILABLE ? 'nullable' : 'required',
                'date_format:H:i', 'after:starts_at',
            ],

            'slot_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'max_walkins' => ['nullable', 'integer', 'min:0', 'max:999'],

            'reason' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * A branch id from the client is just a number until somebody checks it.
     *
     * Staff work at one branch; nothing else in the request would stop one
     * of them scheduling an extra clinic at a branch they have never been to.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $locationId = $this->input('location_id');

            if ($locationId && ! app(TenantBranchAccess::class)->currentCanUse((int) $locationId)) {
                $validator->errors()->add(
                    'location_id',
                    'You can only make changes for the branch you work at.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doctor_schedule_id.required' => 'Choose which sitting has changed.',
            'doctor_schedule_id.prohibited' => 'An extra session is not part of the weekly pattern, so it belongs to no sitting.',
            'location_id.required' => 'An extra session needs a branch.',
            'ends_at.after' => 'It has to end after it starts.',
        ];
    }
}
