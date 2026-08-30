<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aggregate counters only (patients: 4,213) — never a name, phone or
 * diagnosis. One row per organization+metric, so new counters need no
 * schema change. Populated by the tenant outbox relay, never by a
 * cross-database query — scaffolding only for now; nothing writes here yet.
 */
class OrganizationStat extends Model
{
    protected $fillable = [
        'organization_id',
        'metric_key',
        'metric_value',
        'recorded_at',
    ];

    protected $casts = [
        'metric_value' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
