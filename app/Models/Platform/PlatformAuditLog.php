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
        'organization_id',
        'platform_user_id',
        /*
         * Copied at write time, not joined. The foreign key above is nulled
         * when an administrator is deleted, which is right for the key and
         * useless for an audit trail — "somebody suspended this
         * organization" answers nothing.
         */
        'actor_name',
        'action',
        'entity_type',
        'entity_id',
        // What the subject was called then, for the same reason.
        'entity_label',
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
