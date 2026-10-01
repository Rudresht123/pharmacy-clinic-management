<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toIso8601String(),

            'location_id' => $this->location_id,

            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name)
                ?? $this->walk_in_name,
            'walk_in_phone' => $this->walk_in_phone,
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),
            'is_walk_in' => $this->customer_id === null,

            'appointment_id' => $this->appointment_id,
            'consultation_id' => $this->consultation_id,
            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),

            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'trigger' => $this->trigger,
            'kind' => $this->kind,

            /*
             * Whether the bill is still collecting charges. The screen shows
             * a "Finalize" button rather than "Take payment" while true —
             * a draft is a running tab, and the counter must not ask for
             * money before the last charge has landed.
             */
            'is_draft' => $this->isDraft(),
            'finalized_at' => $this->finalized_at?->toIso8601String(),

            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'round_off' => (float) $this->round_off,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'outstanding' => $this->outstanding(),

            'notes' => $this->notes,
            'terms' => $this->terms,

            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'is_refund' => (bool) $payment->is_refund,
                'refunds_payment_id' => $payment->refunds_payment_id,
            ])),

            'created_by_name' => $this->created_by_name,

            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->when($this->cancelled_at !== null, $this->cancellation_reason),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
