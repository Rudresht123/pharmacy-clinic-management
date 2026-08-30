<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per provisioning step attempted for an organization — the trail
 * that lets a retry resume instead of restart. Scaffolding only; nothing
 * writes here yet.
 */
class TenantProvisionEvent extends Model
{
    protected $fillable = [
        'organization_id',
        'step',
        'status',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
