<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Tenancy\TenantBranchAccess;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Booking somebody in, or taking a walk-in.
 *
 * Open to anyone signed in: whoever is at the desk has to be able to do
 * this, the same reasoning that makes creating a customer non-owner-only.
 */
class StoreAppointmentRequest extends FormRequest
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
        return [
            'customer_id' => [
                'required', 'integer',
                Rule::exists(Customer::class, 'id')->whereNull('deleted_at'),
            ],
            'doctor_id' => [
                'required', 'integer',
                Rule::exists(Doctor::class, 'id')->whereNull('deleted_at'),
            ],
            'location_id' => [
                'required', 'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],

            'appointment_date' => ['required', 'date'],
            'type' => ['required', Rule::in(Appointment::TYPES)],

            /*
             * A booked appointment promises a time; a walk-in is the absence
             * of one, so sending it would be claiming something untrue. The
             * time itself is checked against derived availability in the
             * service — validation here only says which shape is expected.
             */
            'slot_at' => [
                $this->input('type') === Appointment::BOOKED ? 'required' : 'prohibited',
                // The walk-in screen sends an explicit null; without this the
                // format rule refused it and walk-ins could not be taken.
                'nullable',
                'date_format:H:i',
            ],

            /*
             * Video is for a booked time only: somebody who walked in is
             * standing at the desk, which is the one place a video visit
             * is not.
             */
            'mode' => [
                'nullable',
                Rule::in($this->input('type') === Appointment::WALK_IN
                    ? [Appointment::MODE_IN_CLINIC]
                    : Appointment::MODES),
            ],

            'notes' => ['nullable', 'string', 'max:1000'],

            /*
             * Somebody walking in already in trouble. Only a walk-in joins a
             * queue the moment it is recorded, so only a walk-in can have a
             * place in one to be moved up; the reason is not optional.
             */
            'queue_priority' => ['nullable', Rule::in(Appointment::PRIORITIES)],
            'priority_reason' => [
                Rule::requiredIf(fn () => in_array(
                    $this->input('queue_priority'),
                    [Appointment::PRIORITY_URGENT, Appointment::PRIORITY_EMERGENCY],
                    true,
                )),
                'nullable', 'string', 'max:191',
            ],
        ];
    }

    /** A branch id from the client is just a number until somebody checks it. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $locationId = $this->input('location_id');

            if ($locationId && ! app(TenantBranchAccess::class)->currentCanUse((int) $locationId)) {
                $validator->errors()->add(
                    'location_id',
                    'You can only book at the branch you work at.'
                );
            }

            // Refused here rather than when the doctor rings: a video visit
            // the plan cannot call is a booking nobody can keep.
            $organization = $this->attributes->get('tenant.organization');

            if ($this->input('mode') === Appointment::MODE_VIDEO
                && $organization instanceof Organization
                && ! app(EntitlementService::class)->hasFeature($organization, Entitlements::VIDEO_CONSULTATION)) {
                $validator->errors()->add('mode', 'Video consultation is not part of your plan.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slot_at.required' => 'Choose a time, or record this as a walk-in.',
            'slot_at.prohibited' => 'A walk-in has no booked time — that is what makes it one.',
        ];
    }
}
