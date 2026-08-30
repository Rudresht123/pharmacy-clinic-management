<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Structured profile detail for an organization — address, logo, support
 * contacts — split out to keep the hot `organizations` row narrow.
 * Scaffolding only; nothing writes here yet.
 */
class OrganizationProfile extends Model
{
    protected $fillable = [
        'organization_id',
        'description',
        'website_url',
        'logo_file_id',
        'support_email',
        'support_phone',
        'address_line1',
        'address_line2',
        'city',
        'state_province',
        'postal_code',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
