<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Platform\Organization;
use App\Support\Platform\OrganizationCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:191'],

            'organization_code' => [
                'required', 'string', 'size:6',
                // Three letters then three digits, like NMG001. Typed by hand
                // from a printed sheet, so the shape is what keeps it
                // readable -- position says whether a character is a letter
                // or a digit, so O and 0 can never be confused.
                'regex:'.OrganizationCode::PATTERN,
                Rule::unique('organizations', 'organization_code')->whereNull('deleted_at'),
            ],

            'organization_type_id' => ['required', 'integer', 'exists:organization_types,id'],

            'subdomain' => [
                'required', 'string', 'max:63', 'alpha_dash',
                Rule::unique('organizations', 'subdomain')->whereNull('deleted_at'),
            ],

            'contact_person_name' => ['nullable', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone_number' => ['nullable', 'digits_between:10,15'],
            'address' => ['nullable', 'string', 'max:1000'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'organization_name.required' => 'Please enter the organization name.',
            'organization_code.required' => 'Please enter the organization code.',
            'organization_code.unique' => 'This organization code is already in use.',
            'organization_code.size' => 'The organization code must be exactly 6 characters.',
            'organization_code.regex' => 'The organization code is three letters then three numbers, like NMG001.',
            'organization_type_id.required' => 'Please select an organization type.',
            'organization_type_id.exists' => 'The selected organization type is invalid.',
            'subdomain.required' => 'Please enter a subdomain.',
            'subdomain.alpha_dash' => 'The subdomain may only contain letters, numbers, dashes and underscores.',
            'subdomain.unique' => 'This subdomain is already assigned to another organization.',
            'email.required' => 'An email address is required to send the setup link.',
            'phone_number.digits_between' => 'The phone number must be between 10 and 15 digits.',
            'profile_image.max' => 'The organization logo may not exceed 2 MB.',
        ];
    }

    public function attributes(): array
    {
        return [
            'organization_name' => 'organization name',
            'organization_code' => 'organization code',
            'organization_type_id' => 'organization type',
            'contact_person_name' => 'contact person name',
            'phone_number' => 'phone number',
            'profile_image' => 'organization logo',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'subdomain' => strtolower(trim((string) $this->subdomain)),

            /*
             * Codes are stored upper case and matched without regard to case.
             *
             * A phone keyboard capitalises the first letter by itself, and a
             * printed sheet shows codes in capitals. Normalising on the way in
             * means the same code typed three ways is one code rather than
             * three failed sign-ins.
             */
            'organization_code' => OrganizationCode::normalise((string) $this->organization_code),
        ]);
    }

    /**
     * Validated input plus the fields the server derives.
     *
     * The slug and the database name are never client-supplied and are only
     * ever assigned at creation: the slug appears in every URL and the
     * database name points at a real PostgreSQL database, so regenerating
     * either on update would strand the tenant.
     */
    public function validatedForCreation(): array
    {
        $data = $this->validated();

        $name = trim((string) $this->organization_name);

        $data['slug'] = Organization::generateSlug($name);
        $data['tenant_key'] = Organization::generateTenantKey($name);
        $data['database_name'] = Organization::generateDatabaseName($data['tenant_key']);

        return $data;
    }
}
