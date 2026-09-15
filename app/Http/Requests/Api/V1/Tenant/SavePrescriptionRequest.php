<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\PrescriptionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A draft prescription, as the doctor writes it.
 *
 * Each line is a catalogue medicine (`medicine_id`) or an unlisted one
 * (`medicine_name` alone). A removed medicine is refused here; an inactive
 * one is refused by PrescriptionService for new choices, so a line already
 * written keeps working after its medicine is deactivated.
 *
 * `appointment_id` is taken on create only: a prescription never moves to
 * another visit.
 */
class SavePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // PrescriptionPolicy, from the controller: it needs the visit.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'clinical_notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['sometimes', 'array', 'max:30'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.medicine_id' => [
                'nullable',
                'integer',
                Rule::exists(Medicine::class, 'id')->whereNull('deleted_at'),
            ],
            'items.*.medicine_name' => ['required_without:items.*.medicine_id', 'nullable', 'string', 'max:191'],

            'items.*.dose_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999'],
            'items.*.dose_unit' => ['nullable', 'string', 'max:20'],
            'items.*.morning' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'items.*.afternoon' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'items.*.evening' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'items.*.night' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'items.*.frequency' => ['nullable', Rule::in(PrescriptionItem::FREQUENCIES)],
            'items.*.food_timing' => ['nullable', Rule::in(PrescriptionItem::FOOD_TIMINGS)],
            'items.*.duration' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'items.*.duration_unit' => [
                'required_with:items.*.duration',
                'nullable',
                Rule::in(PrescriptionItem::DURATION_UNITS),
            ],
            'items.*.route' => ['nullable', Rule::in(Medicine::ROUTES)],
            'items.*.prescribed_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->isMethod('post')) {
            $rules['appointment_id'] = [
                'required',
                'integer',
                Rule::exists(Appointment::class, 'id')->whereNull('deleted_at'),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.medicine_name.required_without' => 'Pick a medicine, or type the name of one that is not in the catalogue.',
            'items.*.medicine_id.exists' => 'That medicine has been removed from the catalogue. Pick another.',
            'items.*.duration_unit.required_with' => 'Say whether that is days, weeks or months.',
            'items.*.dose_amount.gt' => 'A dose has to be more than zero.',
        ];
    }
}
