<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Support\Fields\LocationFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }
}
