<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prescription
 */
class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prescription_number' => $this->prescription_number,
            'status' => $this->status,
            'is_draft' => $this->isDraft(),
            'is_legacy' => (bool) $this->is_legacy,

            'appointment_id' => $this->appointment_id,
            'consultation_id' => $this->consultation_id,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('patient', fn () => $this->patient?->name),
            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),

            'prescription_date' => $this->prescription_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'clinical_notes' => $this->clinical_notes,

            'issued_at' => $this->issued_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by_name' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),

            'items_count' => $this->whenCounted('items'),
            'items' => PrescriptionItemResource::collection($this->whenLoaded('items')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
