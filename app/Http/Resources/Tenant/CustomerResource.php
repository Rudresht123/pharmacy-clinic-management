<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The number a patient quotes and the desk searches on.
            'code' => $this->code,

            'name' => $this->name,

            'registered_location_id' => $this->registered_location_id,
            'registered_location' => $this->whenLoaded(
                'registeredLocation',
                fn () => $this->registeredLocation
                    ? ['id' => $this->registeredLocation->id, 'name' => $this->registeredLocation->name]
                    : null
            ),
            'phone' => $this->phone,
            'email' => $this->email,

            'date_of_birth' => $this->date_of_birth?->toDateString(),
            // Saves every caller working it out from the date.
            'age' => $this->age(),
            'gender' => $this->gender,

            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,

            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,

            'custom_fields' => $this->custom_fields ?? [],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
