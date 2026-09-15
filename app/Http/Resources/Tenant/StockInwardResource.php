<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\StockInward;
use App\Models\Tenant\StockInwardItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockInward
 */
class StockInwardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inward_number' => $this->inward_number,

            'pharmacy_store_id' => $this->pharmacy_store_id,
            'store_name' => $this->whenLoaded('store', fn () => $this->store?->name),
            'location_id' => $this->location_id,

            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),

            'inward_type' => $this->inward_type,
            'supplier_invoice_no' => $this->supplier_invoice_no,
            'supplier_invoice_date' => $this->supplier_invoice_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,

            'status' => $this->status,
            'created_by_name' => $this->created_by_name,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (StockInwardItem $item) => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'medicine_name' => $item->relationLoaded('medicine') ? $item->medicine?->displayName() : null,
                'medicine_batch_id' => $item->medicine_batch_id,
                'batch_number' => $item->batch_number,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'manufacture_date' => $item->manufacture_date?->toDateString(),
                'pack_size' => $item->pack_size,
                'quantity' => $item->quantity,
                'free_quantity' => $item->free_quantity,
                'purchase_price' => $item->purchase_price,
                'selling_price' => $item->selling_price,
                'mrp' => $item->mrp,
                'line_total' => $item->line_total,
            ])->all()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
