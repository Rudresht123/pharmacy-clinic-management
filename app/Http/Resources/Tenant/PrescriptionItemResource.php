<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PrescriptionItem
 */
class PrescriptionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $number = fn (mixed $value) => $value !== null ? (float) $value : null;

        return [
            'id' => $this->id,
            'medicine_id' => $this->medicine_id,
            'is_unlisted' => $this->isUnlisted(),

            // The snapshots: what was chosen, as it read then.
            'medicine_name' => $this->medicine_name_snapshot,
            'generic_name' => $this->generic_name_snapshot,
            'strength' => $this->strength_snapshot,
            'dosage_form' => $this->dosage_form_snapshot,
            'base_unit' => $this->whenLoaded('medicine', fn () => $this->medicine?->base_unit),

            'dose_amount' => $number($this->dose_amount),
            'dose_unit' => $this->dose_unit,
            'morning' => $number($this->morning),
            'afternoon' => $number($this->afternoon),
            'evening' => $number($this->evening),
            'night' => $number($this->night),
            'frequency' => $this->frequency,
            'food_timing' => $this->food_timing,
            'duration' => $this->duration,
            'duration_unit' => $this->duration_unit,
            'route' => $this->route,

            'prescribed_quantity' => $this->prescribed_quantity,
            'dispensed_quantity' => $this->dispensed_quantity,
            'status' => $this->status,
            'instructions' => $this->instructions,
            'sort_order' => $this->sort_order,

            // The line written out — "1 tablet · 1-0-0-1 · 5 days".
            'line' => $this->asConsultationLine(),
        ];
    }
}
