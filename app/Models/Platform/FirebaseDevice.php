<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone (or browser) that can receive push notifications for one person.
 *
 * MASTER DATABASE, deliberately, and the reason is `fcm_token` being unique
 * across EVERY organization. A phone signed into Apollo that signs out and
 * into Fortis registers the same token under Fortis — and because the token
 * is unique here, that moves the row rather than leaving Apollo a second
 * one. Apollo can never push to that phone again. A table inside each tenant
 * database could not see the other organization's row to move it, and
 * "the app will unregister first" is not something to rely on: an uninstall
 * or a forced sign-out never says goodbye.
 *
 * `user_id`, `branch_id` and `access_token_id` point into the TENANT database
 * and carry no foreign key — Postgres cannot reference across databases. They
 * are only ever read together with `organization_id`, through DeviceRegistry.
 *
 * Holds no patient data: who is reachable, not what they are told.
 */
class FirebaseDevice extends Model
{
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = [
        'organization_id',
        'user_id',
        'branch_id',
        'device_id',
        'fcm_token',
        'access_token_id',
        'platform',
        'app_version',
        'is_active',
        'last_seen_at',
    ];

    /** A push token is a credential for reaching somebody; it never goes out in a response. */
    protected $hidden = ['fcm_token'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'access_token_id' => 'integer',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
