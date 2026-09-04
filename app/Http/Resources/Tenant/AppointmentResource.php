<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),

            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),

            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),

            // Provenance: which sitting produced this. Null once that sitting
            // is deleted, and for an extra session that never had one.
            'doctor_schedule_id' => $this->doctor_schedule_id,

            'appointment_date' => $this->appointment_date?->toDateString(),
            'type' => $this->type,
            'status' => $this->status,

            // Postgres returns "10:30:00"; the screen shows "10:30".
            'slot_at' => $this->slot_at ? substr((string) $this->slot_at, 0, 5) : null,
            'token_no' => $this->token_no,

            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            /*
             * How long they have been waiting, in minutes — the number the
             * desk actually looks at. Computed here rather than in the
             * browser so every screen agrees on when the clock started.
             */
            'waiting_minutes' => $this->checked_in_at && ! $this->started_at
                ? $this->checked_in_at->diffInMinutes(now())
                : null,

            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,

            // What this appointment may become next, so the screen offers
            // exactly those actions and no others.
            'next_states' => Appointment::TRANSITIONS[$this->status] ?? [],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
