<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\EntityFieldSetting;
use App\Support\Fields\DoctorFields;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Tenant\User;
use Illuminate\Validation\Rules\Password;
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
            // A list. Each entry is checked against the organization's own
            // options by withFieldSettings, which knows what they are.
            'qualifications' => ['nullable', 'array', 'max:12'],
            'qualifications.*' => ['string', 'max:100'],

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

            /*
             * An optional login for this doctor.
             *
             * Optional on purpose: a visiting consultant who never touches the
             * system is the reason `doctors` is its own table rather than a
             * role on `users`. Left out entirely, nothing is created and the
             * doctor is exactly as they were.
             */
            'account' => ['nullable', 'array'],

            'account.email' => [
                'required_with:account', 'string', 'email', 'max:191',

                /*
                 * The model class, not the string 'users' — a string table
                 * name resolves against the master connection and 500s. The
                 * doctor's OWN login is excluded, so saving the form without
                 * touching the email is not a clash with itself.
                 */
                Rule::unique(User::class, 'email')
                    ->whereNull('deleted_at')
                    ->ignore($this->currentAccountId()),
            ],

            /*
             * Required only when there is no login yet. On an existing one a
             * blank password means "leave it alone", which is the same
             * convention the People form uses.
             */
            'account.password' => [
                $this->currentAccountId() ? 'nullable' : 'required_with:account',
                'string',
                Password::min(8),
            ],
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

    /**
     * An account block with no email in it is nobody, not half a somebody.
     *
     * The form keeps its inputs mounted while the section is switched off, so
     * they arrive as empty strings rather than absent — and every doctor saved
     * without a login would otherwise fail validation on fields nobody opened.
     */
    protected function prepareForValidation(): void
    {
        $account = $this->input('account');

        if (! is_array($account) || trim((string) ($account['email'] ?? '')) === '') {
            $this->merge(['account' => null]);
        }
    }

    /** This doctor's existing login, so its own email is not a clash. */
    private function currentAccountId(): ?int
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor
            ? $doctor->user()->value('id')
            : null;
    }
}
