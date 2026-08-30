<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current vs target schema version per tenant — answers "who is behind?"
 * without opening every tenant database. Scaffolding only; nothing writes
 * here yet.
 */
class TenantMigrationState extends Model
{
    // Singular by design (one current-state row per organization, not a
    // log) — Eloquent's pluralization would otherwise guess the wrong name.
    protected $table = 'tenant_migration_state';

    protected $fillable = [
        'organization_id',
        'current_version',
        'target_version',
        'status',
        'attempts',
        'last_error',
        'last_run_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'last_run_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
