<?php

namespace App\Models\Tenant;

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
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Doctor / Patient / Staff
     */
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