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
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, RecordsHistory, SoftDeletes;

    public const OWNER = 'owner';

    public const STAFF = 'staff';

    /**
     * A patient's own login, opened by the one-time-code sign-in.
     *
     * An account KIND, like owner and staff — not a permission. What a patient
     * may do still comes from the role on `role_id`, which the owner can edit.
     * The kind exists so staff screens (the People list, setup's head count)
     * can leave patients out without asking what anybody may do.
     *
     * Deliberately not in ROLES: that list is what the People form accepts,
     * and a patient account is never made by hand.
     */
    public const PATIENT = 'patient';

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
        // Their department or sub-department, when they belong to one.
        'department_id',
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

    /**
     * Capabilities taken off this person individually.
     *
     * Deny rows only — see UserPermissionOverride. Normally empty, which is
     * the point: a role is the unit, and this is the exception that keeps
     * that true rather than a second permission system running alongside.
     */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    /**
     * What this person is denied at one branch.
     *
     * Rows with no branch apply everywhere and are always included; rows
     * naming a branch apply only there.
     *
     * @return list<string>
     */
    public function deniedAt(?int $locationId): array
    {
        return $this->permissionOverrides
            ->filter(fn (UserPermissionOverride $override) => $override->effect === UserPermissionOverride::DENY
                && ($override->location_id === null
                    || ($locationId !== null && (int) $override->location_id === $locationId)))
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Everything denied to this person, at any branch or at all of them.
     *
     * For the questions that are about the PERSON rather than about a place —
     * "does this account administer the whole network" is one, and answering
     * it per branch would leave somebody holding it at the branches where
     * their deny does not reach.
     *
     * @return list<string>
     */
    public function deniedAnywhere(): array
    {
        return $this->permissionOverrides
            ->where('effect', UserPermissionOverride::DENY)
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    /** The branches this person runs — see Location::manager(). */
    public function managedBranches(): HasMany
    {
        return $this->hasMany(Location::class, 'manager_id');
    }

    /**
     * Do they run this branch?
     *
     * A fact about the `locations` row, not a permission — what a manager may
     * actually do still comes from the role on their membership. Used to show
     * the badge and to refuse taking the last manager off a branch by
     * accident, never to decide an action.
     */
    public function managesBranch(?int $locationId): bool
    {
        return $locationId !== null
            && $this->managedBranches->contains(fn (Location $branch) => $branch->getKey() === $locationId);
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

    /** The organization's own people: everybody but patients' portal logins. */
    public function scopeStaffAccounts($query)
    {
        return $query->where('role', '!=', self::PATIENT);
    }

    /**
     * Helper Methods
     */
    public function isOwner(): bool
    {
        return $this->role === self::OWNER;
    }

    public function isPatient(): bool
    {
        return $this->role === self::PATIENT;
    }

    /**
     * The patient record this login belongs to, when it is a patient's own.
     *
     * Both halves are checked: the account kind, and what it points at. A
     * login that is one without the other is a half-made record, and the
     * portal must not guess whose data it should show.
     */
    public function patientRecord(): ?Customer
    {
        if (! $this->isPatient() || $this->userable_type !== Customer::class) {
            return null;
        }

        $record = $this->userable;

        return $record instanceof Customer ? $record : null;
    }
}
