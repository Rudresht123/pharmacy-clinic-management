<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_type' => $this->movement_type,
            'quantity' => $this->quantity,
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,
            'unit_cost' => $this->unit_cost,

            'medicine_id' => $this->medicine_id,
            'medicine_name' => $this->whenLoaded('medicine', fn () => $this->medicine->displayName()),
            'medicine_batch_id' => $this->medicine_batch_id,
            'batch_number' => $this->whenLoaded('batch', fn () => $this->batch?->batch_number),

            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reverses_movement_id' => $this->reverses_movement_id,

            'reason' => $this->reason,
            'notes' => $this->notes,
            'performed_by_name' => $this->performed_by_name,
            'movement_date' => $this->movement_date?->toIso8601String(),
        ];
    }
}
