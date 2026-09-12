<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Http\Requests\Api\V1\Tenant\Concerns\MergesFieldSettings;
use App\Models\Tenant\Customer;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Support\Fields\CustomerFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding somebody the organization serves.
 *
 * Unlike Locations and People, this is not owner-only: whoever is at the
 * counter has to be able to create a customer.
 */
class StoreCustomerRequest extends FormRequest
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
             * Optional, and normally left out: the repository allocates the
             * next number in sequence. Accepted so an organization migrating
             * from another system can carry its own across, which it has to be
             * able to do or every patient's old file becomes unfindable.
             */
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique(Customer::class, 'code')->whereNull('deleted_at'),
            ],

            // Where they signed up. Provenance only — it never limits
            // who may see or serve this customer.
            'registered_location_id' => [
                'nullable', 'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],

            /*
             * The model class rather than the string 'customers' — a string
             * table name resolves against the default connection, which is
             * the master database and has no such table.
             */
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique(Customer::class, 'phone')->whereNull('deleted_at'),
            ],

            'email' => ['nullable', 'string', 'email', 'max:191'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', Rule::in(Customer::GENDERS)],

            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],

            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];

        return $this->withFieldSettings(
            $rules,
            EntityFieldSetting::ENTITY_CUSTOMER,
            CustomerFields::all(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'Another customer is already using this number.',
            'date_of_birth.before' => 'A date of birth cannot be today or in the future.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'full name',
            'date_of_birth' => 'date of birth',
            'pincode' => 'PIN code',
        ];
    }
}
