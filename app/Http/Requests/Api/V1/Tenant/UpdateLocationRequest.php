<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Support\Fields\LocationFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Differs from StoreLocationRequest only by ignoring the row being edited
 * when checking the code for uniqueness.
 */
class UpdateLocationRequest extends FormRequest
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
        $id = $this->route('location')?->id;

        $rules = [
            'name' => ['required', 'string', 'max:191'],

            // See StoreLocationRequest: the model class, not the table name —
            // a string would resolve against the master database.
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique(Location::class, 'code')->ignore($id)->whereNull('deleted_at'),
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
            'drug_license_no' => ['nullable', 'string', 'max:60', 'required_with:drug_license_expiry_date'],
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
