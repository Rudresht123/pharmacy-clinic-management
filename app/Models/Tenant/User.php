<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, RecordsHistory, SoftDeletes;

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
        'role_id',
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
     * The set of capabilities this person holds.
     *
     * Named `permissionRole` rather than `role` because `role` is already a
     * column on this table, holding owner/staff — the account's kind, which is
     * a different question from what it may do. An Eloquent relation of that
     * name would shadow the attribute and quietly break every `isOwner()` call
     * in the codebase.
     *
     * Null for an owner, who bypasses roles entirely, and null for a member of
     * staff whose role was never set — which grants nothing rather than
     * everything. The migration put every existing staff member on the system
     * `staff` role, so that case only arises going forward, where the form
     * requires one.
     */
    public function permissionRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Every branch this person works at, and what they hold at each.
     *
     * The relationship `users.location_id` could not express. A doctor sitting
     * at three clinics is three rows; a transfer is a row moved rather than a
     * column overwritten, so where somebody used to work is still readable.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'branch_users', 'user_id', 'location_id')
            ->withPivot(['role_id', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * What they hold at one branch — the row every permission check needs.
     *
     * Null when they are not a member there, which is the answer that refuses
     * the request rather than an error to handle.
     */
    public function membershipAt(?int $locationId): ?BranchMembership
    {
        if ($locationId === null) {
            return null;
        }

        return $this->memberships
            ->firstWhere('location_id', $locationId);
    }

    /**
     * The branch the app should open on.
     *
     * Their primary if one is marked, otherwise the first they joined. Null
     * for the owner and for head-office staff, both of whom work across the
     * network rather than at a counter — and null is never an error here.
     */
    public function defaultBranchId(): ?int
    {
        $memberships = $this->memberships;

        return $memberships->firstWhere('is_primary', true)?->location_id
            ?? $memberships->first()?->location_id;
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
