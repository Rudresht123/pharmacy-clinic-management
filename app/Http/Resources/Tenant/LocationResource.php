<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'is_active' => (bool) $this->is_active,

            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'phone' => $this->phone,
            'email' => $this->email,

            'gstin' => $this->gstin,
            'drug_license_no' => $this->drug_license_no,
            'drug_license_expiry_date' => $this->drug_license_expiry_date?->toDateString(),

            // Saves every caller re-deriving it from the date.
            'has_expired_licence' => $this->hasExpiredLicence(),

            'custom_fields' => $this->custom_fields ?? [],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
