<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Medicine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Medicine
 */
class MedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'medicine_code' => $this->medicine_code,

            'generic_name' => $this->generic_name,
            'brand_name' => $this->brand_name,
            'strength' => $this->strength,
            'dosage_form' => $this->dosage_form,
            'route' => $this->route,

            // Ready to show, so every screen names a medicine the same way.
            'display_name' => $this->displayName(),

            'base_unit' => $this->base_unit,
            'pack_size' => $this->pack_size,

            'manufacturer' => $this->manufacturer,
            'category' => $this->category,

            'schedule' => $this->schedule,
            'prescription_required' => (bool) $this->prescription_required,

            'description' => $this->description,
            'is_active' => (bool) $this->is_active,

            'custom_fields' => $this->custom_fields ?? [],

            // Only meaningful on a removed medicine; the restore screen reads them.
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
