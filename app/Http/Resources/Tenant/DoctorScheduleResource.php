<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\DoctorSchedule;
use App\Support\Opd\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoctorSchedule
 */
class DoctorScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),

            'name' => $this->name,

            'weekday' => $this->weekday,
            'weekday_label' => Weekday::label($this->weekday),

            // Postgres hands back "10:00:00"; the screen edits "10:00".
            'starts_at' => substr((string) $this->starts_at, 0, 5),
            'ends_at' => substr((string) $this->ends_at, 0, 5),

            'slot_minutes' => $this->slot_minutes,
            'max_walkins' => $this->max_walkins,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
