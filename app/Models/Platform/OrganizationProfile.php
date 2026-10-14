<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Structured profile detail for an organization — address, logo, support
 * contacts, map position — split out to keep the hot `organizations` row
 * narrow. Written by the owner's setup screen and the super admin's
 * organization form; the coordinates by App\Services\Geo\OrganizationLocator.
 */
class OrganizationProfile extends Model
{
    /** A pin somebody placed by hand — never overwritten by a lookup. */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = ['manual', 'address', 'pincode', 'city'];

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
        'latitude',
        'longitude',
        'location_source',
        'located_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'located_at' => 'datetime',
    ];

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
