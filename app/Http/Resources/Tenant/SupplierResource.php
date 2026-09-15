<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'gstin' => $this->gstin,
            'drug_license_no' => $this->drug_license_no,
            'drug_license_expiry_date' => $this->drug_license_expiry_date?->toDateString(),
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,

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
