<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\PharmacySaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a bill, as the bill said it.
 *
 * @mixin PharmacySaleItem
 */
class PharmacySaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'medicine_id' => $this->medicine_id,
            'medicine_batch_id' => $this->medicine_batch_id,
            'prescription_item_id' => $this->prescription_item_id,

            // The copies, not the catalogue's current answer.
            'item_name' => $this->item_name_snapshot,
            'hsn_code' => $this->hsn_code_snapshot,
            'batch_number' => $this->batch_number_snapshot,
            'expiry_date' => $this->expiry_date_snapshot?->toDateString(),

            'quantity' => $this->quantity,

            'mrp' => (float) $this->mrp,
            'unit_price' => (float) $this->unit_price,

            'discount_percent' => (float) $this->discount_percent,
            'discount_amount' => (float) $this->discount_amount,

            'tax_rate' => (float) $this->tax_rate,
            'taxable_amount' => (float) $this->taxable_amount,
            'tax_amount' => (float) $this->tax_amount,

            'line_total' => (float) $this->line_total,
        ];
    }
}
