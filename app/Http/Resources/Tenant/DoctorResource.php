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
            'qualifications' => $this->qualifications ?? [],

            /*
             * The URL, not the id. Nothing on a screen can do anything with a
             * file id, and sending one would make every avatar a second
             * request to find out where the first one lives.
             */
            /*
             * The branches this doctor covers, as ids the form can tick.
             *
             * Separate from `locations`, which is the same set as names for
             * reading. The screen needs both: one to render the list, one to
             * post back.
             */
            'location_ids' => $this->whenLoaded(
                'postings',
                fn () => $this->postings->pluck('id')->all(),
                [],
            ),

            'photo_url' => $this->whenLoaded(
                'photograph',
                fn () => $this->photograph?->url,
                null,
            ),
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

            /*
             * Their login, when they have one.
             *
             * Only ever the email — a password is write-only, and sending a
             * hash to a screen that has no use for it is how hashes end up in
             * browser caches and bug reports.
             */
            'account' => $this->whenLoaded(
                'user',
                fn () => $this->user ? ['id' => $this->user->id, 'email' => $this->user->email] : null,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
