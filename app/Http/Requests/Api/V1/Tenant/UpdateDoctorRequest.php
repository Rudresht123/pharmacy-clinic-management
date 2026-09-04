<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\EntityFieldSetting;
use App\Support\Fields\DoctorFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a doctor.
 *
 * The same rules as creating one, except that the row being edited is
 * excluded from the uniqueness checks — otherwise saving a doctor without
 * touching their code would fail against themselves.
 */
class UpdateDoctorRequest extends FormRequest
{
    use MergesFieldSettings;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('doctor')?->id;

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:191'],

            /*
             * The model class rather than the string 'doctors' — a string
             * table name resolves against the default connection, which is
             * the master database and has no such table. That is a 500, not
             * a validation error.
             *
             * Unique among live rows only, matching the partial index: a
             * removed doctor frees their code and their registration number.
             */
            'code' => [
                'nullable', 'string', 'max:30',
                Rule::unique(Doctor::class, 'code')->ignore($id)->whereNull('deleted_at'),
            ],

            'specialisation' => ['nullable', 'string', 'max:120'],
            'qualification' => ['nullable', 'string', 'max:191'],

            'registration_no' => [
                'nullable', 'string', 'max:60',
                Rule::unique(Doctor::class, 'registration_no')->ignore($id)->whereNull('deleted_at'),
            ],

            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],

            // The fallback, not the price — see DoctorFields.
            'default_consultation_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],

            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],

            'custom_fields' => ['sometimes', 'array'],
        ];

        return $this->withFieldSettings(
            $rules,
            EntityFieldSetting::ENTITY_DOCTOR,
            DoctorFields::all(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another doctor already uses that code.',
            'registration_no.unique' => 'Another doctor is already registered under that number.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'registration_no' => 'registration number',
            'default_consultation_fee' => 'default consultation fee',
        ];
    }
}
