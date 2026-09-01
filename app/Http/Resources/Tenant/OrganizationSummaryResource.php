<?php

namespace App\Http\Resources\Tenant;

use App\Models\Platform\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Just enough about the signed-in user's own organization for the tenant UI
 * to greet them by name/logo — never the platform-facing detail
 * (OrganizationResource) an organization's own staff have no business seeing.
 *
 * @mixin Organization
 */
class OrganizationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->organization_name,
            'code' => $this->organization_code,
            'subdomain' => $this->subdomain,
            'logo_url' => getFileUrl($this->profile_image),
            'has_logo' => $this->profile_image !== null,
        ];
    }
}
