<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subdomain or custom domain that resolves to a tenant. Scaffolding only;
 * `organizations.subdomain` remains the single source tenant resolution
 * reads today.
 */
class OrganizationDomain extends Model
{
    protected $fillable = [
        'organization_id',
        'domain',
        'type',
        'is_primary',
        'verified_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
