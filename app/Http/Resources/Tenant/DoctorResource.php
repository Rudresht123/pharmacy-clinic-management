<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Doctor
 */
class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,

            'specialisation' => $this->specialisation,
            'qualification' => $this->qualification,
            'registration_no' => $this->registration_no,

            'phone' => $this->phone,
            'email' => $this->email,

            'default_consultation_fee' => $this->default_consultation_fee,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,

            /*
             * Where this doctor sits, derived from their schedules — there
             * is no branch column on a doctor, and this is the only answer.
             * Loaded on the detail endpoint; on a list it would be a query
             * per row.
             */
            'locations' => $this->whenLoaded(
                'schedules',
                fn () => $this->schedules
                    ->pluck('location.name', 'location_id')
                    ->filter()
                    ->unique()
                    ->values()
            ),

            'schedule_count' => $this->whenCounted('schedules'),

            'custom_fields' => $this->custom_fields ?? [],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
