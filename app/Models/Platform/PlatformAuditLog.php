<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per platform action. Append-only — enforced by a database
 * trigger, not by this class (see the migration) — so there is no
 * `updated_at` for Eloquent to try to maintain.
 */
class PlatformAuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'platform_user_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }
}
