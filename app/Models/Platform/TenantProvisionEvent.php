<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per provisioning step attempted for an organization — the trail
 * that lets a retry resume instead of restart, and what the administrator
 * watches while the queue works through it (`progress_*`, `detail`).
 */
class TenantProvisionEvent extends Model
{
    protected $fillable = [
        'organization_id',
        'step',
        'status',
        'progress_current',
        'progress_total',
        'detail',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'progress_current' => 'integer',
        'progress_total' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
