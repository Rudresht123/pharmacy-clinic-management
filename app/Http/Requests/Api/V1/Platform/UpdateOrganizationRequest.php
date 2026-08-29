<?php

namespace App\Http\Requests\Api\V1\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('organization')?->id;

        return [
            'organization_name' => ['required', 'string', 'max:191'],

            'organization_code' => [
                'required', 'string', 'max:191',
                Rule::unique('organizations', 'organization_code')->ignore($organizationId),
            ],

            'organization_type_id' => ['required', 'integer', 'exists:organization_type,id'],

            'subdomain' => [
                'required', 'string', 'max:63', 'alpha_dash',
                Rule::unique('organizations', 'subdomain')->ignore($organizationId),
            ],

            'contact_person_name' => ['nullable', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone_number' => ['nullable', 'digits_between:10,15'],
            'address' => ['nullable', 'string', 'max:1000'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],

            // §5: nullable at creation, required before the tenant goes live.
            'legal_name' => ['nullable', 'string', 'max:191'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'drug_license_no' => ['nullable', 'string', 'max:60'],

            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],

            // Internal only — never shown to the tenant.
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'organization_code.unique' => 'This organization code is already in use.',
            'organization_type_id.exists' => 'The selected organization type is invalid.',
            'subdomain.unique' => 'This subdomain is already assigned to another organization.',
            'subdomain.alpha_dash' => 'The subdomain may only contain letters, numbers, dashes and underscores.',
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
        $this->merge(array_filter([
            'is_active' => $this->boolean('is_active'),
            'subdomain' => strtolower(trim((string) $this->subdomain)),
            'currency' => $this->currency ? strtoupper(trim((string) $this->currency)) : null,
            'country' => $this->country ? strtoupper(trim((string) $this->country)) : null,
        ], fn ($value) => ! is_null($value)));
    }

    /**
     * `slug`, `uuid` and `database_name` are deliberately absent from the
     * rules above, so they cannot be changed here even if a client sends
     * them. The database already exists under that name and both identifiers
     * appear in URLs that may have been shared — regenerating either would
     * orphan the tenant or break links to it.
     */
    public function validatedForUpdate(): array
    {
        return $this->validated();
    }
}
