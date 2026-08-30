<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per migration attempt for a tenant, grouped by `batch_uuid` (all
 * tenants migrated in the same run share a batch). Scaffolding only;
 * nothing writes here yet.
 */
class TenantMigrationRun extends Model
{
    protected $fillable = [
        'organization_id',
        'batch_uuid',
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
