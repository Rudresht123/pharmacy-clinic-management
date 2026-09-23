<?php

namespace App\Http\Resources\Tenant\Portal;

use App\Models\Tenant\DoctorSchedule;
use App\Support\Opd\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor, as a patient choosing one sees them.
 *
 * Deliberately not DoctorResource: that one is for the clinic's own staff and
 * carries the doctor's phone, email, registration number, notes and login. A
 * patient needs who they are, what they treat, where and when they sit, and
 * what it costs — and nothing a clinic would not print on a noticeboard.
 */
class PortalDoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schedules = $this->relationLoaded('schedules') ? $this->schedules : collect();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'specialisation' => $this->specialisation,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->path()),
            'qualifications' => $this->qualifications ?? [],
            'photo_url' => $this->whenLoaded('photograph', fn () => $this->photograph?->url, null),
            'consultation_fee' => $this->default_consultation_fee,

            // Where they sit, from their active sittings.
            'locations' => $schedules
                ->map(fn (DoctorSchedule $schedule) => $schedule->location
                    ? ['id' => $schedule->location->id, 'name' => $schedule->location->name]
                    : null)
                ->filter()
                ->unique('id')
                ->values(),

            // The days of the week they sit, Monday first — "Mon", "Wed".
            'days' => $schedules
                ->pluck('weekday')
                ->unique()
                ->sort()
                ->map(fn ($weekday) => Weekday::label((int) $weekday))
                ->values(),

            // Set by the controller when it was worked out; see BookableDoctors::nextAvailable.
            'next_available' => $this->resource->getAttribute('next_available'),
        ];
    }
}
