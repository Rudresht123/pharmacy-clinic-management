<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockAdjustment
 */
class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'pharmacy_store_id' => $this->pharmacy_store_id,
            'medicine_id' => $this->medicine_id,
            'medicine_name' => $this->whenLoaded('medicine', fn () => $this->medicine->displayName()),
            'medicine_batch_id' => $this->medicine_batch_id,
            'batch_number' => $this->whenLoaded('batch', fn () => $this->batch?->batch_number),
            'direction' => $this->direction,
            'quantity' => $this->quantity,
            'reason_code' => $this->reason_code,
            'reason' => $this->reason,
            'created_by_name' => $this->created_by_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
