<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\PharmacySale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PharmacySale
 */
class PharmacySaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_number' => $this->sale_number,

            'pharmacy_store_id' => $this->pharmacy_store_id,
            'store_name' => $this->whenLoaded('store', fn () => $this->store?->name),

            // However they were recorded — a patient record, or a name at the counter.
            'customer_id' => $this->customer_id,
            'customer_name' => $this->buyerName(),
            'walk_in_phone' => $this->walk_in_phone,
            'is_walk_in' => $this->customer_id === null,

            'prescription_id' => $this->prescription_id,
            'doctor_id' => $this->doctor_id,

            'sale_date' => $this->sale_date?->toIso8601String(),
            'status' => $this->status,

            // How this bill was priced, as the settings were at the time.
            'price_basis' => $this->price_basis,
            'prices_include_tax' => (bool) $this->prices_include_tax,

            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'round_off' => (float) $this->round_off,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            // The value of goods brought back — what the bill is no longer for.
            'returned_amount' => (float) $this->returned_amount,
            'amount_due' => $this->amountDue(),
            'payment_status' => $this->payment_status,

            'notes' => $this->notes,

            'items_count' => $this->whenCounted('items'),
            'items' => PharmacySaleItemResource::collection($this->whenLoaded('items')),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                // Money handed back — on a cancelled bill, or for goods returned.
                'is_refund' => (bool) $payment->is_refund,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])),
            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(fn ($return) => [
                'id' => $return->id,
                'return_number' => $return->return_number,
                'return_date' => $return->return_date?->toIso8601String(),
                'reason' => $return->reason,
                'total_amount' => (float) $return->total_amount,
                'refund_amount' => (float) $return->refund_amount,
                'created_by_name' => $return->created_by_name,
                'items' => $return->relationLoaded('items')
                    ? $return->items->map(fn ($item) => [
                        'pharmacy_sale_item_id' => $item->pharmacy_sale_item_id,
                        'quantity' => (int) $item->quantity,
                        'line_total' => (float) $item->line_total,
                    ])->all()
                    : [],
            ])),

            'created_by_name' => $this->created_by_name,

            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->when($this->cancelled_at !== null, $this->cancellation_reason),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
