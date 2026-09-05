<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of capabilities the owner hands to staff.
 *
 * DEFINED once, organization-wide. ASSIGNED either across the network or at a
 * particular branch, which is what `scope` decides. The two are different
 * questions and conflating them is what produces five copies of "Receptionist"
 * that drift apart until the fifth branch quietly has a deletion permission
 * nobody meant to grant.
 *
 * Nothing here decides whether a capability is usable on its own: Permission
 * asks the organization's entitlements and the branch's modules first, so a
 * role that holds `appointments.book` grants nothing at a branch where
 * appointments are switched off, and nothing at all in an organization that was
 * never sold the module. A role is the last of the answers, never the only one.
 *
 * Not soft-deleted, unlike most tenant records. A role is configuration rather
 * than a record whose past matters, and a soft-deleted one would go on granting
 * its capabilities to everybody still pointing at it. The foreign keys on
 * `users.role_id` and `branch_users.role_id` restrict instead, so a role
 * somebody holds cannot be deleted at all — which is the only deletion rule
 * there is. There is no protected class of role.
 */
class Role extends Model
{
    use RecordsHistory;

    /**
     * The slug of the role seeded when roles were introduced.
     *
     * An ordinary role with no special standing — it is named here only so
     * tests and seeds can find it without repeating a string literal.
     */
    public const SEEDED_STAFF = 'staff';

    public const DEFAULT_ICON = 'ti ti-shield-lock';

    /**
     * Where a role can be assigned, and therefore where it means anything.
     *
     * An organization-scoped role sits on `users.role_id` and applies across
     * the network — what head office wears. A branch-scoped role sits on a
     * membership and applies at that branch alone, so the same person can be a
     * receptionist at one and a manager at another.
     *
     * Chosen when a role is created and not edited afterwards: changing a live
     * role's scope silently moves every holder's permissions somewhere else.
     */
    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPES = [self::SCOPE_ORGANIZATION, self::SCOPE_BRANCH];

    /**
     * The marks a role may wear.
     *
     * A closed list, not free text: this value reaches a `class` attribute in
     * the client, so it has to be one the software chose. Also keeps the rail
     * looking like one product rather than whatever each owner typed.
     */
    public const ICONS = [
        self::DEFAULT_ICON,
        'ti ti-users',
        'ti ti-headset',
        'ti ti-stethoscope',
        'ti ti-nurse',
        'ti ti-pill',
        'ti ti-briefcase',
        'ti ti-receipt',
        'ti ti-cash',
        'ti ti-clipboard-list',
        'ti ti-microscope',
        'ti ti-eye',
    ];

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $fillable = [
        'name',
        'slug',
        'scope',
        'location_id',
        'description',
        'icon',
    ];

    public function capabilities(): HasMany
    {
        return $this->hasMany(RoleCapability::class);
    }

    /** Head-office holders — an organization-scoped role sits here. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Branch holders — one row per person per branch they hold it at. */
    public function memberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    public function isOrganizationScoped(): bool
    {
        return $this->scope === self::SCOPE_ORGANIZATION;
    }

    /** The branch that wrote it, or null for one the organization wrote. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Written by the organization, and therefore offered at every branch. */
    public function isOrganizationWide(): bool
    {
        return $this->location_id === null;
    }

    /**
     * Roles that may be assigned at one branch.
     *
     * The organization's own, plus that branch's. A role another branch wrote
     * is not narrower — it is somebody else's, and offering it here would let
     * one branch's choices leak into another's.
     */
    public function scopeAssignableAt(Builder $query, ?int $locationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($locationId) {
            $scoped->whereNull('location_id');

            if ($locationId !== null) {
                $scoped->orWhere('location_id', $locationId);
            }
        });
    }

    /**
     * How many people hold this, counting both ways it can be held.
     *
     * A role is normally one or the other, so one of these is normally zero —
     * summed rather than chosen so the count stays right if that ever stops
     * being true.
     */
    public function holderCount(): int
    {
        return $this->users()->count() + $this->memberships()->count();
    }

    /**
     * The capability keys this role holds.
     *
     * @return list<string>
     */
    public function capabilityKeys(): array
    {
        return $this->capabilities
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }

    public function grants(string $capability): bool
    {
        return in_array($capability, $this->capabilityKeys(), true);
    }

    /**
     * Replace the whole set in one go.
     *
     * Written as a whole rather than added and removed one at a time, for the
     * same reason a doctor's week is: a role is read and edited as one thing,
     * and a half-applied change is a set of permissions nobody chose.
     *
     * @param  list<string>  $capabilities
     */
    public function syncCapabilities(array $capabilities): void
    {
        $wanted = array_values(array_unique($capabilities));
        $held = $this->capabilityKeys();

        $this->capabilities()->whereIn('capability', array_diff($held, $wanted))->delete();

        foreach (array_diff($wanted, $held) as $capability) {
            $this->capabilities()->create(['capability' => $capability]);
        }

        $this->load('capabilities');
    }

    /** Names the role in the activity log rather than showing an id. */
    public function historyLabel(): string
    {
        return $this->name;
    }
}
