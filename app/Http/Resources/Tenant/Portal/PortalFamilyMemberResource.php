<?php

namespace App\Http\Resources\Tenant\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somebody in the patient's family. `id` is their patient record's, the one
 * a booking for them names as `member_id`.
 */
class PortalFamilyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $member = $this->member;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'code' => $member->code,
            'relation' => $this->relation,
            'gender' => $member->gender,
            'date_of_birth' => $member->date_of_birth?->toDateString(),
            'age' => $member->age(),
            'photo_url' => $member->relationLoaded('photograph') ? $member->photograph?->url : null,
        ];
    }
}
