<?php

namespace App\Http\Requests\Api\V1\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The organisation's own details, as its admin fills them in during setup.
 *
 * A subset of what the platform's UpdateOrganizationRequest takes, and on
 * the same rules, plus the address detail the platform's `organization_profiles`
 * row holds (website, city, state, postal code). The code, subdomain, type,
 * email and status are the platform's to change: they identify the
 * organization to everything else, and the email is the owner's sign-in.
 * The route is owner-only.
 */
class UpdateSetupOrganizationRequest extends FormRequest
{
    /** Fields that live on `organizations`. */
    public const ORGANIZATION_FIELDS = [
        'organization_name', 'legal_name', 'contact_person_name', 'phone_number', 'address', 'gstin', 'drug_license_no',
    ];

    /** Fields that live on `organization_profiles`. */
    public const PROFILE_FIELDS = ['website_url', 'city', 'state_province', 'postal_code'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'contact_person_name' => ['required', 'string', 'max:191'],
            'phone_number' => ['required', 'digits_between:10,15'],
            'address' => ['required', 'string', 'max:1000'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'drug_license_no' => ['nullable', 'string', 'max:60'],

            'website_url' => ['nullable', 'url', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state_province' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]{2,9}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.digits_between' => 'The phone number must be between 10 and 15 digits.',
            'postal_code.regex' => 'That does not look like a postal code.',
            'website_url.url' => 'Enter the website as an address, like https://www.example.com.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_name' => 'organisation name',
            'legal_name' => 'legal name',
            'contact_person_name' => 'contact person',
            'phone_number' => 'phone number',
            'gstin' => 'GSTIN',
            'drug_license_no' => 'registration number',
            'website_url' => 'website',
            'state_province' => 'state',
            'postal_code' => 'postal code',
        ];
    }

    protected function prepareForValidation(): void
    {
        $website = trim((string) $this->website_url);

        $this->merge(array_filter([
            // "98100 00001" and "+91-9810000001" are both a phone number typed by a person.
            'phone_number' => $this->phone_number !== null
                ? preg_replace('/\D+/', '', (string) $this->phone_number)
                : null,
            'gstin' => $this->gstin ? strtoupper(trim((string) $this->gstin)) : null,
            // "sunrise.in" is how people write a website; the address needs its scheme.
            'website_url' => $website !== '' && ! preg_match('#^https?://#i', $website)
                ? "https://{$website}"
                : null,
        ], fn ($value) => $value !== null));
    }
}
