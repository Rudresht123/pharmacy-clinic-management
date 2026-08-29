<?php

namespace App\Http\Resources\Platform;

use App\Models\Platform\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // §5: the ULID is the only id that ever leaves the server. The
            // auto-increment id stays internal so a URL can never be walked.
            'uuid' => $this->uuid,
            'slug' => $this->slug,

            'organization_name' => $this->organization_name,
            'organization_code' => $this->organization_code,
            'organization_type_id' => $this->organization_type_id,
            'organization_type' => OrganizationTypeResource::make(
                $this->whenLoaded('organizationType')
            ),

            'subdomain' => $this->subdomain,
            'database_name' => $this->database_name,

            'legal_name' => $this->legal_name,
            'gstin' => $this->gstin,
            'drug_license_no' => $this->drug_license_no,

            'contact_person_name' => $this->contact_person_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'address' => $this->address,

            'profile_image_id' => $this->profile_image,
            'profile_image_url' => getFileUrl($this->profile_image),

            'status' => $this->status,
            'is_active' => (bool) $this->is_active,
            'is_setup_completed' => (bool) $this->is_setup_completed,

            'plan_id' => $this->plan_id,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,

            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'country' => $this->country,
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
