<?php

namespace App\Http\Resources\Tenant\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the patient's own appointments.
 *
 * Not AppointmentResource: that one answers the desk, with `next_states` for
 * moving somebody through the queue and the waiting-room clock. A patient needs
 * who, where, when, and what state it is in.
 */
class PortalAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'appointment_date' => $this->appointment_date?->toDateString(),

            // Postgres returns "10:30:00"; the app shows "10:30".
            'slot_at' => $this->slot_at ? substr((string) $this->slot_at, 0, 5) : null,

            // Issued on arrival, so null until they have checked in.
            'token_no' => $this->token_no,

            'doctor' => $this->whenLoaded('doctor', fn () => [
                'id' => $this->doctor?->id,
                'name' => $this->doctor?->name,
                'specialisation' => $this->doctor?->specialisation,
                'photo_url' => $this->doctor?->relationLoaded('photograph')
                    ? $this->doctor->photograph?->url
                    : null,
            ]),

            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
            ]),

            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
