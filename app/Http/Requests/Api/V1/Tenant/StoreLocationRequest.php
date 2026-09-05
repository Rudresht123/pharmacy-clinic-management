<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Support\Fields\LocationFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Authorization is the `tenant.owner` middleware on the route, not this
 * class — the house convention throughout the codebase.
 */
class StoreLocationRequest extends FormRequest
{
    use MergesFieldSettings;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * An admin block with no email in it is nobody, not an incomplete
     * somebody.
     *
     * The form keeps the three inputs mounted while the section is switched
     * off, so they arrive as empty strings rather than absent. Without this
     * every branch created without an admin would fail validation on three
     * fields the user never opened.
     */
    protected function prepareForValidation(): void
    {
        $admin = $this->input('admin');

        if (! is_array($admin) || trim((string) ($admin['email'] ?? '')) === '') {
            // merge() writes to whichever bag the request actually came in
            // on, which for this API is the JSON one.
            $this->merge(['admin' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:191'],

            /*
             * Rule::unique(Location::class, ...) rather than the string
             * 'locations' every other request in this codebase uses. A string
             * table name is resolved against the *default* connection — the
             * master database, which has no locations table — so it would
             * fail with a 500 rather than a validation error. Passing the
             * model class makes the rule resolve `organization.locations`.
             */
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique(Location::class, 'code')->whereNull('deleted_at'),
            ],

            'type' => ['required', 'string', Rule::in(Location::SELECTABLE_TYPES)],
            'is_active' => ['nullable', 'boolean'],

            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:191'],

            'gstin' => ['nullable', 'string', 'max:20'],

            // An expiry date with no licence number behind it says nothing.
            'drug_license_no' => ['nullable', 'string', 'max:60', 'required_with:drug_license_expiry_date'],

            /*
             * Deliberately not `after:today`. A compliance record has to be
             * able to state that a licence has already expired; the UI
             * surfaces that as a warning rather than refusing the input.
             */
            'drug_license_expiry_date' => ['nullable', 'date'],

            /*
             * Optional: somebody who can run the branch from day one.
             *
             * Adding a branch and then discovering nobody can sign in to it is
             * the commonest way a new site sits unused for a week, so the
             * three steps — role, person, posting — are offered here rather
             * than on three later screens. Left out entirely, nothing is
             * created and the branch behaves exactly as it did before.
             */
            'admin' => ['nullable', 'array'],
            'admin.name' => ['required_with:admin', 'string', 'max:191'],

            'admin.email' => [
                'required_with:admin', 'string', 'email', 'max:191',

                // The model class, not the string 'users' — a string table
                // name resolves against the master connection and 500s.
                Rule::unique(User::class, 'email')->whereNull('deleted_at'),
            ],

            'admin.password' => ['required_with:admin', 'string', Password::min(8)],
        ];

        return $this->withFieldSettings(
            $rules,
            EntityFieldSetting::ENTITY_LOCATION,
            LocationFields::all(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another location is already using this code.',
            'type.in' => 'Choose one of the available location types.',
            'drug_license_no.required_with' => 'Add the licence number this expiry date belongs to.',
            'admin.email.unique' => 'Somebody already signs in with this email address.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'location name',
            'gstin' => 'GSTIN',
            'drug_license_no' => 'drug licence number',
            'drug_license_expiry_date' => 'drug licence expiry date',
            'pincode' => 'PIN code',
            'admin.name' => 'branch admin name',
            'admin.email' => 'branch admin email',
            'admin.password' => 'branch admin password',
        ];
    }
}
