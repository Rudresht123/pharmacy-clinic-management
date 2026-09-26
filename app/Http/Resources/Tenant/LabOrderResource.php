<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LabOrder;
use App\Models\Tenant\LabOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabOrder
 */
class LabOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,

            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),

            'customer_id' => $this->customer_id,
            'patient_name' => $this->whenLoaded('patient', fn () => $this->patient?->name),
            'patient_code' => $this->whenLoaded('patient', fn () => $this->patient?->code),

            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),

            'appointment_id' => $this->appointment_id,
            'token_no' => $this->whenLoaded('appointment', fn () => $this->appointment?->token_no),

            'order_date' => $this->order_date?->toDateString(),
            'status' => $this->status,
            'clinical_notes' => $this->clinical_notes,

            'started_at' => $this->started_at?->toIso8601String(),
            'started_by_name' => $this->whenLoaded('starter', fn () => $this->starter?->name),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by_name' => $this->whenLoaded('completer', fn () => $this->completer?->name),

            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            /*
             * What the technician's queue shows without opening the order —
             * "CBC, LFT" — so a worklist is readable at a glance rather than
             * a column of order numbers.
             */
            'tests_summary' => $this->whenLoaded('items', fn () => $this->testSummary()),

            'items' => LabOrderItemResource::collection($this->whenLoaded('items')),

            /*
             * How much is left, for the progress a worklist shows. Counted
             * from the loaded lines rather than queried, because every caller
             * that wants it has already loaded them.
             */
            'pending_count' => $this->whenLoaded(
                'items',
                fn () => $this->items->where('status', LabOrderItem::PENDING)->count(),
            ),

            // What this order's state allows, the same contract the
            // appointment resource offers: possible here, permitted there.
            'available' => [
                'process' => $this->canMoveTo(LabOrder::IN_PROGRESS),
                'record' => in_array($this->status, [LabOrder::PENDING, LabOrder::IN_PROGRESS], true),
                'complete' => $this->canMoveTo(LabOrder::COMPLETED),
                'cancel' => $this->canMoveTo(LabOrder::CANCELLED),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
