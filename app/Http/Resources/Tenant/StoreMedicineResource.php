<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\StoreMedicine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreMedicine
 */
class StoreMedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pharmacy_store_id' => $this->pharmacy_store_id,

            'medicine_id' => $this->medicine_id,
            'medicine' => $this->whenLoaded('medicine', fn () => [
                'display_name' => $this->medicine->displayName(),
                'generic_name' => $this->medicine->generic_name,
                'base_unit' => $this->medicine->base_unit,
                'is_active' => (bool) $this->medicine->is_active,
                // A configuration row can outlive a medicine that was removed.
                'is_removed' => $this->medicine->deleted_at !== null,
            ]),

            'reorder_level' => $this->reorder_level,
            'minimum_stock_level' => $this->minimum_stock_level,
            'maximum_stock_level' => $this->maximum_stock_level,
            'preferred_supplier_id' => $this->preferred_supplier_id,
            'is_active' => (bool) $this->is_active,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
