<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\PharmacyStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PharmacyStore
 */
class PharmacyStoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),

            'name' => $this->name,
            'code' => $this->code,
            'store_type' => $this->store_type,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,

            'pharmacist_user_id' => $this->pharmacist_user_id,
            'pharmacist_name' => $this->whenLoaded('pharmacist', fn () => $this->pharmacist?->name),

            'address' => $this->address,
            'phone' => $this->phone,
            'drug_license_no' => $this->drug_license_no,
            'drug_license_expiry_date' => $this->drug_license_expiry_date?->toDateString(),

            // What the store trades under: its own licence, or its branch's.
            'licence' => $this->whenLoaded('location', fn () => $this->licence()),

            'medicines_count' => $this->whenCounted('storeMedicines'),

            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'deletion_reason' => $this->when($this->deleted_at !== null, $this->deletion_reason),
            'deleted_by_name' => $this->when(
                $this->deleted_at !== null,
                fn () => $this->relationLoaded('remover') ? $this->remover?->name : null,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
