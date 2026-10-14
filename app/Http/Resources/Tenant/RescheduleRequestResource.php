<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\AppointmentRescheduleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reschedule request as the desk sees it: who, from when, to when, why —
 * and, once answered, what was decided and by whom.
 *
 * @mixin AppointmentRescheduleRequest
 */
class RescheduleRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'appointment_id' => $this->appointment_id,

            'patient' => [
                'id' => $this->customer_id,
                'name' => $this->customer?->name,
                'code' => $this->customer?->code,
                'phone' => $this->customer?->phone,
            ],
            'doctor' => [
                'id' => $this->doctor_id,
                'name' => $this->doctor?->name,
            ],
            'location' => ['id' => $this->location_id, 'name' => $this->location?->name],

            'from' => [
                'date' => $this->from_date?->toDateString(),
                'slot_at' => AppointmentRescheduleRequest::hhmm($this->from_slot_at),
            ],
            'requested' => [
                'date' => $this->requested_date?->toDateString(),
                'slot_at' => AppointmentRescheduleRequest::hhmm($this->requested_slot_at),
                'location_id' => $this->requested_location_id,
                'location_name' => $this->requestedLocation?->name,
            ],
            'approved' => $this->approved_date ? [
                'date' => $this->approved_date->toDateString(),
                'slot_at' => AppointmentRescheduleRequest::hhmm($this->approved_slot_at),
            ] : null,

            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
