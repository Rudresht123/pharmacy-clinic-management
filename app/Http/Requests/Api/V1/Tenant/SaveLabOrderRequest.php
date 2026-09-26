<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\LabOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a doctor may order.
 *
 * Permissive about the test and strict about the shape, the same way
 * SaveConsultationRequest is: a test is whatever the lab calls it, but a line
 * has to name one or the order is a list of blanks somebody has to ring up
 * and ask about.
 *
 * `appointment_id` is required on create and ignored on update: an order
 * cannot be moved to another visit, and the service reads the visit off the
 * order rather than off the payload, so a client that sends one changes
 * nothing.
 */
class SaveLabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route holds the capability; the Policy checks the visit is this
        // doctor's, which is the part a request cannot see.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->routeIs('lab-orders.store');

        return [
            'appointment_id' => [
                Rule::requiredIf($creating),
                'integer',
                Rule::exists(Appointment::class, 'id')->whereNull('deleted_at'),
            ],

            'clinical_notes' => ['nullable', 'string', 'max:2000'],

            'items' => [Rule::requiredIf($creating), 'array', 'min:1', 'max:30'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.test_name' => ['required', 'string', 'max:191'],
            'items.*.test_code' => ['nullable', 'string', 'max:40'],
            'items.*.specimen' => ['nullable', Rule::in(LabOrderItem::SPECIMENS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'A lab order needs at least one test.',
            'items.*.test_name.required' => 'Every line needs a test name.',
        ];
    }
}
