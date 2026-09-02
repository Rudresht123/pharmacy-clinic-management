<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    public const OWNER = 'owner';
    public const STAFF = 'staff';

    public const ROLES = [self::OWNER, self::STAFF];

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    /**
     * Mass Assignable
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'userable_type',
        'userable_id',
        'is_active',
        'role',
        'location_id',
        'custom_fields',
    ];

    /**
     * Hidden Attributes
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casts
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Without this it comes back from Postgres as a plain string, and
            // UserResource's ->toIso8601String() fatals on the next request —
            // login itself survived only because the value was still the
            // Carbon instance it had just been assigned.
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /**
     * Doctor / Patient / Staff
     */
    /**
     * The branch this person works at.
     *
     * Null for the owner, who works across the whole network. What a
     * customer's `registered_location_id` is filled from.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function userable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Helper Methods
     */
    public function isOwner(): bool
    {
        return $this->role === self::OWNER;
    }
}