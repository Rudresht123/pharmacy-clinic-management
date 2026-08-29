<?php

namespace App\Http\Requests\Api\V1\Platform;

use App\Models\Platform\Organization;
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
                'required', 'string', 'max:191',
                Rule::unique('organizations', 'organization_code'),
            ],

            'organization_type_id' => ['required', 'integer', 'exists:organization_type,id'],

            'subdomain' => [
                'required', 'string', 'max:63', 'alpha_dash',
                Rule::unique('organizations', 'subdomain'),
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
        $data['database_name'] = Organization::generateDatabaseName($name);

        return $data;
    }
}
