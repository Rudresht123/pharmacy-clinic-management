<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per change inside this organization.
 *
 * Append-only, enforced by a database trigger rather than by this class —
 * a log the application can quietly edit is not evidence of anything. There
 * is deliberately no `updated_at` for Eloquent to try to maintain, and no
 * update or delete method that would succeed if called.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'organization';

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_type',
        'event',
        'entity_type',
        'entity_id',
        'entity_label',
        'before',
        'after',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The person who made the change, if they still exist.
     *
     * `actor_name` is the one to display — this relation is for linking to a
     * live user, and comes back null once that user is removed.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
