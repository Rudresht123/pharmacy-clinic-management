<?php

namespace App\Services\Permissions;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\BranchRoleCapability;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\User;
use App\Services\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;

/**
 * Whether somebody may do something — the whole answer, in one place.
 *
 * Permission is decided at three levels, and this is the only thing that knows
 * all three:
 *
 *   1. Did the super admin sell this module to the organization?
 *      (`organization_modules`, master database — ModuleAccess)
 *   2. Does the branch this is happening at run that module?
 *      (`location_modules`, tenant database)
 *   3. Does this person hold the capability HERE?
 *      Their organization-scoped role, which applies everywhere, plus the
 *      branch-scoped role on their membership at this branch — and nothing
 *      from their membership anywhere else.
 *
 * ASKED IN THAT ORDER, ALWAYS. An unsold module has to fail before roles are
 * consulted, so the answer never depends on how somebody happened to configure
 * a role — otherwise "we were never sold this" and "you personally may not"
 * become the same 403 with two different meanings, and the second one is
 * fixable by an owner while the first is not.
 *
 * The owner bypasses level 3 and only level 3. They are the organization's
 * account holder, so no role can constrain them; a module the organization was
 * never sold is refused to them exactly as to anybody else, and a branch that
 * does not run OPD does not start running it because the owner asked.
 *
 * One deliberate exception: `DESK_CAPABILITIES` below. Checking a patient in
 * and calling them through are the desk's job, not the account holder's, and
 * the owner holds no role to do it under — so for these, and only these, the
 * owner is asked the same question as anybody else (see `heldBy()`), which
 * they answer with an empty set. Everything else about being the owner —
 * seeing the queue, booking, cancelling, running reports, managing staff — is
 * untouched.
 *
 * Branch-level DATA scope is a different question and stays with
 * App\Services\Tenancy\TenantBranchAccess. This says what may be done; that
 * says where. Both are needed on a write and neither substitutes for the other.
 */
class Permission
{
    /**
     * Capabilities the owner does not bypass level 3 for.
     *
     * The OPD front desk, specifically: an owner may watch any branch's queue
     * but not work it, so a real receptionist is never confused for the
     * account holder standing in. Kept to a named list rather than a blanket
     * "owner may not write" rule, because booking, cancelling and every other
     * write in the product stays exactly as bypassable as before — this is
     * about who mans the counter, not about owners and writes in general.
     *
     * @var list<string>
     */
    private const DESK_CAPABILITIES = [
        'appointments.queue',
    ];
    /**
     * Modules already worked out for a branch this request.
     *
     * A queue screen asks about the same branch once per row; without this the
     * override lookup runs as many times as there are patients waiting.
     *
     * @var array<string, list<string>>
     */
    private array $resolved = [];

    /**
     * What each branch has taken off each role, once looked up this request.
     *
     * ONLY the customisations, never the whole answer. Caching what somebody
     * holds would mean caching their role's capabilities too, and those change
     * under a running process often enough to matter — a provisioner assigning
     * a role, a seeder, a test — leaving this service confidently answering
     * with a set nobody holds any more. A branch's customisations change on
     * exactly one screen, which calls forget().
     *
     * @var array<string, list<string>>
     */
    private array $removed = [];

    /**
     * The branch this request is happening in, once resolved.
     *
     * Null means "wherever this person works" — their own branch, or the whole
     * network for the owner and head office. Set by ResolveActingBranch and by
     * nothing else, because it is only safe after membership has been checked.
     */
    private ?int $actingAt = null;

    public function __construct(
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * The modules usable at a branch.
     *
     * A null branch means organization-wide — what the owner sees, and the
     * right answer when nothing branch-specific is being asked about.
     *
     * @return list<string>
     */
    public function modulesAt(Organization $organization, ?int $locationId): array
    {
        $cacheKey = $organization->getKey().':'.($locationId ?? '-');

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $entitled = $this->modules->enabled($organization);

        if ($locationId === null) {
            return $this->resolved[$cacheKey] = $entitled;
        }

        /*
         * Only the switched-off rows are read. A branch with no rows keeps
         * everything the organization holds, which is what makes this whole
         * level opt-in: an organization that never opens the screen behaves
         * exactly as it did before the table existed.
         */
        $off = LocationModule::query()
            ->where('location_id', $locationId)
            ->where('is_enabled', false)
            ->pluck('module_key')
            ->all();

        return $this->resolved[$cacheKey] = array_values(array_diff($entitled, $off));
    }

    /**
     * Drop what has been worked out so far.
     *
     * Only needed where a request changes the overrides and then reads them
     * back in the same breath — the branch's module screen does exactly that,
     * and would otherwise answer with the state from before its own write.
     */
    public function forget(): void
    {
        $this->resolved = [];
        $this->removed = [];
    }

    /**
     * Whether a module is usable at a branch — level one and two, no role.
     *
     * What gates a whole section of the software rather than one action.
     */
    public function hasModule(Organization $organization, string $module, ?int $locationId = null): bool
    {
        return in_array($module, $this->modulesAt($organization, $locationId), true);
    }

    /**
     * The question everything else is asking.
     *
     * `$locationId` names the branch the action happens at. Leave it out and
     * the person's own branch is used — the owner's being null, meaning
     * organization-wide. Pass it whenever the request says where: booking at a
     * branch that does not run OPD must be refused even for an owner, because
     * the branch is the thing that does not do this, not the person.
     */
    public function allows(
        Organization $organization,
        User $user,
        string $capability,
        ?int $locationId = null,
    ): bool {
        $module = ModuleRegistry::moduleForCapability($capability);

        /*
         * A capability no module grants is refused rather than allowed. It
         * means either a typo in a route or a capability left behind by a
         * retired module, and both should stop working loudly.
         */
        if ($module === null) {
            return false;
        }

        $branch = $this->branchFor($user, $locationId);

        if (! $this->hasModule($organization, $module, $branch)) {
            return false;
        }

        if ($user->isOwner() && ! in_array($capability, self::DESK_CAPABILITIES, true)) {
            return true;
        }

        return in_array($capability, $this->heldBy($user, $branch), true);
    }

    /**
     * Which branch this person is acting in.
     *
     * An explicit branch wins — a request that names where it is happening is
     * answered about there, and the caller has already checked the person is a
     * member. Otherwise it is the branch they work at, or null for the owner
     * and for head office, who work across the network rather than at a
     * counter.
     */
    public function branchFor(User $user, ?int $locationId = null): ?int
    {
        if ($locationId !== null) {
            return $locationId;
        }

        /*
         * Where the branch switcher lands. Set once per request by
         * ResolveActingBranch, and only after it has checked the caller is a
         * member there — so by the time it reaches this line it is a branch
         * they may work at, not a number the client sent.
         */
        if ($this->actingAt !== null) {
            return $this->actingAt;
        }

        if ($user->isOwner()) {
            return null;
        }

        return $user->defaultBranchId();
    }

    /**
     * Fix the branch this request is happening in.
     *
     * Called only by ResolveActingBranch, once, after membership has been
     * verified. Everything downstream — the module gate, the capability gate,
     * the dashboard, `me` — then answers about that branch without any of them
     * having to know the switcher exists.
     */
    public function actAt(?int $locationId): void
    {
        $this->actingAt = $locationId;

        // What was worked out for another branch no longer applies.
        $this->forget();
    }

    /**
     * What this person holds, at one branch.
     *
     * The union of two things and never a chain:
     *
     *   the ORGANIZATION-scoped role on `users.role_id` — head office, applies
     *   everywhere, including at every branch
     *
     *   the BRANCH-scoped role on their membership there — applies at that
     *   branch and nowhere else, which is what lets somebody be a receptionist
     *   at Lucknow and a manager at Delhi without either leaking, and already
     *   MINUS whatever that branch has customised off the role (see
     *   BranchMembership::capabilityKeys). The subtraction belongs there
     *   because it is a property of holding the role at that branch, not a
     *   rule this service applies afterwards
     *
     * A union rather than inheritance on purpose: "why can Rahul do this"
     * has to be answerable by reading two rows, and an inheritance chain is
     * how a permission system stops being auditable.
     *
     * Deliberately NOT filtered by capability scope. Scope is enforced when a
     * role is written, not when it is read — the migration that introduced
     * membership had to copy some branch capabilities onto an organization
     * role to preserve head office's reach, and re-checking here would strip
     * exactly the people that copy was made for.
     *
     * DENIES ARE SUBTRACTED LAST, and this is the only place they are read.
     * A role is still the unit — capabilities are held by reference, so
     * editing a role changes what its holders can do — and an override is the
     * exception that keeps that true instead of forcing a cloned role for
     * every small difference. Only denies exist: an override that GRANTED
     * would be a second way to escalate, and it would have to be checked
     * everywhere a role already is.
     *
     * @return list<string>
     */
    public function heldBy(User $user, ?int $locationId): array
    {
        $held = $user->permissionRole?->capabilityKeys() ?? [];

        $membership = $user->membershipAt($locationId);

        /*
         * Level five: what this branch has taken off the role, subtracted from
         * the membership's half alone. The organization role is not a branch's
         * to clip, and this stays a union rather than becoming a chain.
         */
        $fromBranch = array_diff(
            $membership?->capabilityKeys() ?? [],
            $this->removedAt($membership),
        );

        $granted = array_unique([...$held, ...$fromBranch]);

        return array_values(array_diff($granted, $user->deniedAt($locationId)));
    }

    /**
     * What the branch behind this membership has customised off its role.
     *
     * Cached per request because a queue screen asks about the same membership
     * once per patient waiting, and this is the one part of the answer that
     * costs a query.
     *
     * @return list<string>
     */
    private function removedAt(?BranchMembership $membership): array
    {
        $role = $membership?->role;

        if ($role === null || ! $role->isCustomisableByBranch()) {
            return [];
        }

        /*
         * The tenant database is part of the key, not decoration. Every
         * organization numbers its own rows from one, so role 7 at two
         * organizations is two different roles — and this service outlives a
         * single tenant wherever one process serves more than one, which is
         * every test run and every queued job.
         */
        $cacheKey = $membership->getConnection()->getDatabaseName()
            .':'.$membership->location_id
            .':'.$role->getKey();

        return $this->removed[$cacheKey] ??= BranchRoleCapability::removedAt(
            (int) $membership->location_id,
            (int) $role->getKey(),
        );
    }

    /**
     * Everything this person can actually exercise, for the client.
     *
     * The intersection of all three levels, so the menu and the buttons are
     * built from the same answer the API will give — a screen that is visible
     * and then answers 403 is worse than one that was never offered.
     *
     * @return list<string>
     */
    public function capabilitiesFor(
        Organization $organization,
        User $user,
        ?int $locationId = null,
    ): array {
        $branch = $this->branchFor($user, $locationId);

        $pool = ModuleRegistry::capabilitiesFor($this->modulesAt($organization, $branch));

        if ($user->isOwner()) {
            // Same carve-out as allows(): the desk is not the owner's to work,
            // so the client is never handed a button `allows()` would refuse.
            return array_values(array_diff($pool, self::DESK_CAPABILITIES));
        }

        // Intersected rather than returned outright: a role may still hold a
        // capability whose module was sold last year and taken back since.
        return array_values(array_intersect($pool, $this->heldBy($user, $branch)));
    }

    /**
     * The pool an owner may hand out, shaped for the role screen.
     *
     * Organization-wide on purpose — a role is not tied to a branch, so the
     * choices offered when writing one must not be narrowed by whichever
     * branch the owner happens to work from.
     *
     * @return list<array<string, mixed>>
     */
    public function grantable(Organization $organization): array
    {
        return ModuleRegistry::grantable($this->modules->enabled($organization));
    }
}
