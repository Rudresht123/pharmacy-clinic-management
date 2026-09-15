<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\MedicineBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MedicineBatch
 */
class MedicineBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pharmacy_store_id' => $this->pharmacy_store_id,
            'store_name' => $this->whenLoaded('store', fn () => $this->store?->name),

            'medicine_id' => $this->medicine_id,
            'medicine' => $this->whenLoaded('medicine', fn () => [
                'display_name' => $this->medicine->displayName(),
                'base_unit' => $this->medicine->base_unit,
                'pack_size' => $this->medicine->pack_size,
            ]),

            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),

            'batch_number' => $this->batch_number,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'manufacture_date' => $this->manufacture_date?->toDateString(),
            // Negative once it has passed, on the clinic's calendar.
            'days_to_expiry' => $this->expiry_date
                ? (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false)
                : null,
            'is_past_expiry' => $this->isPastExpiry(),
            'is_usable' => $this->isUsable(),

            'purchase_price' => $this->purchase_price,
            'selling_price' => $this->selling_price,
            'mrp' => $this->mrp,

            'quantity_received' => $this->quantity_received,
            'quantity_available' => $this->quantity_available,
            'damaged_quantity' => $this->damaged_quantity,
            'returned_quantity' => $this->returned_quantity,

            'status' => $this->status,
            'blocked_reason' => $this->blocked_reason,
            'blocked_at' => $this->blocked_at?->toIso8601String(),

            'received_date' => $this->received_date?->toDateString(),

            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'deletion_reason' => $this->when($this->deleted_at !== null, $this->deletion_reason),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
