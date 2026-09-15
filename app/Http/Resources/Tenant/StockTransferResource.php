<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'from_store_id' => $this->from_store_id,
            'from_store_name' => $this->whenLoaded('fromStore', fn () => $this->fromStore?->name),
            'to_store_id' => $this->to_store_id,
            'to_store_name' => $this->whenLoaded('toStore', fn () => $this->toStore?->name),
            'status' => $this->status,
            'notes' => $this->notes,
            'created_by_name' => $this->created_by_name,

            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (StockTransferItem $item) => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'medicine_name' => $item->relationLoaded('medicine') ? $item->medicine?->displayName() : null,
                'source_batch_id' => $item->source_batch_id,
                'destination_batch_id' => $item->destination_batch_id,
                'batch_number' => $item->relationLoaded('sourceBatch') ? $item->sourceBatch?->batch_number : null,
                'quantity' => $item->quantity,
            ])->all()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
