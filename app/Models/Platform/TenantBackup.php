<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The backup register driving the ops dashboard, retention and restore
 * tooling. Scaffolding only; nothing writes here yet.
 */
class TenantBackup extends Model
{
    protected $fillable = [
        'organization_id',
        'status',
        'size_bytes',
        'storage_path',
        'started_at',
        'finished_at',
        'retained_until',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'retained_until' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
