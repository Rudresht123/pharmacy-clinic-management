<?php

namespace App\Http\Requests\Api\V1\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a doctor may write up.
 *
 * Deliberately permissive about the words and strict about the shape: a
 * diagnosis is whatever the doctor calls it, but a prescription line has to be
 * a line — a name and how to take it — or the record is a list of fragments
 * nobody can dispense from.
 */
class SaveConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route holds the capability; the controller checks the visit is
        // this doctor's, which is the part a request cannot see.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chief_complaint' => ['nullable', 'string', 'max:2000'],

            'diagnoses' => ['nullable', 'array', 'max:20'],
            'diagnoses.*' => ['required', 'string', 'max:191'],

            /*
             * Vitals are a fixed set with real ranges.
             *
             * A typo in a blood pressure is a clinical error, not a formatting
             * one, and the bounds are wide enough to admit anything a person
             * can actually present with while catching a slipped decimal.
             */
            'vitals' => ['nullable', 'array'],
            'vitals.bp_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'vitals.bp_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'vitals.pulse' => ['nullable', 'integer', 'min:20', 'max:250'],
            'vitals.temperature' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'vitals.spo2' => ['nullable', 'integer', 'min:50', 'max:100'],
            'vitals.weight' => ['nullable', 'numeric', 'min:0.5', 'max:400'],
            'vitals.height' => ['nullable', 'numeric', 'min:20', 'max:260'],

            'prescription' => ['nullable', 'array', 'max:30'],
            'prescription.*.drug' => ['required', 'string', 'max:191'],
            'prescription.*.dose' => ['nullable', 'string', 'max:60'],
            'prescription.*.frequency' => ['nullable', 'string', 'max:60'],
            'prescription.*.duration' => ['nullable', 'string', 'max:60'],
            'prescription.*.notes' => ['nullable', 'string', 'max:191'],

            'investigations' => ['nullable', 'array', 'max:20'],
            'investigations.*.test' => ['required', 'string', 'max:191'],
            'investigations.*.notes' => ['nullable', 'string', 'max:191'],

            'advice' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'follow_up_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prescription.*.drug.required' => 'Every prescription line needs a medicine.',
            'investigations.*.test.required' => 'Every investigation line needs a test.',
            'vitals.temperature.min' => 'That temperature is too low to be a reading.',
            'vitals.temperature.max' => 'That temperature is too high to be a reading.',
        ];
    }
}
