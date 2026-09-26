<?php

namespace App\Services\Permissions;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchRoleCapability;
use App\Models\Tenant\User;
use App\Services\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;

/**
 * Not just what somebody may do — WHICH LEVEL DECIDED IT.
 *
 * Six levels now answer before a button appears, and "why can Amit not edit
 * this patient" has six possible answers that look identical from outside: the
 * module was never sold, the branch switched it off, no role grants it, the
 * branch took it off the role, somebody denied it to him personally, or he is
 * simply not a member here. Told apart, five of those are fixed by a different
 * person on a different screen; told together, they are one shrug.
 *
 * Read-only and deliberately separate from Permission. Permission answers one
 * question as fast as it can because every request asks it; this walks the
 * whole pool and explains itself, which is worth doing slowly and only when
 * somebody is actually looking.
 *
 * The answer must agree with Permission's, always — so the same sources are
 * read here, in the same order, rather than re-derived.
 */
class EffectivePermissions
{
    /** Never granted here, because the organization was never sold the module. */
    public const NOT_SOLD = 'not_sold';

    /** Sold, but the branch does not run it. */
    public const MODULE_OFF = 'module_off';

    /** The account holder, who no role constrains. */
    public const OWNER = 'owner';

    /** Their organization-wide role — head office, applies at every branch. */
    public const ORGANIZATION_ROLE = 'organization_role';

    /** The role on their membership here. */
    public const BRANCH_ROLE = 'branch_role';

    /** Granted by the role, taken off by this branch. */
    public const BRANCH_OVERRIDE = 'branch_override';

    /** Denied to this person specifically. */
    public const USER_DENIED = 'user_denied';

    /** Nothing they hold grants it. */
    public const NO_ROLE = 'no_role';

    public function __construct(
        private readonly Permission $permission,
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * Every capability this organization could grant, and this person's answer
     * for each, with the level that decided it.
     *
     * @return array<string, mixed>
     */
    public function for(Organization $organization, User $user, ?int $locationId = null): array
    {
        $branch = $this->permission->branchFor($user, $locationId);

        $entitled = $this->modules->enabled($organization);
        $running = $this->permission->modulesAt($organization, $branch);

        $membership = $user->membershipAt($branch);
        $branchRole = $membership?->role;

        $organizationCapabilities = $user->permissionRole?->capabilityKeys() ?? [];
        $branchCapabilities = $branchRole?->capabilityKeys() ?? [];

        /*
         * Read raw rather than through the membership, which already subtracts
         * them — the whole job here is to say that a subtraction happened,
         * which is invisible once it has been applied.
         */
        $removed = $branchRole !== null && $branchRole->isCustomisableByBranch()
            ? BranchRoleCapability::removedAt((int) $branch, $branchRole->getKey())
            : [];

        $locked = $branchRole?->lockedCapabilityKeys() ?? [];
        $denied = $user->deniedAt($branch);

        $rows = [];

        foreach (ModuleRegistry::allCapabilities() as $capability) {
            $module = ModuleRegistry::moduleForCapability($capability);

            $rows[] = [
                'capability' => $capability,
                'module' => $module,
                'is_locked' => in_array($capability, $locked, true),
                ...$this->decide(
                    $capability,
                    $module,
                    $entitled,
                    $running,
                    $user,
                    $organizationCapabilities,
                    $branchCapabilities,
                    $removed,
                    $denied,
                ),
            ];
        }

        return [
            'user_id' => $user->getKey(),
            'location_id' => $branch,
            'role' => [
                'organization' => $user->permissionRole?->name,
                'branch' => $branchRole?->name,
            ],
            'modules' => $running,
            'capabilities' => $rows,
        ];
    }

    /**
     * One capability's answer, asked in the order Permission asks it.
     *
     * @param  list<string>  $entitled
     * @param  list<string>  $running
     * @param  list<string>  $organizationCapabilities
     * @param  list<string>  $branchCapabilities
     * @param  list<string>  $removed
     * @param  list<string>  $denied
     * @return array{allowed: bool, source: string}
     */
    private function decide(
        string $capability,
        ?string $module,
        array $entitled,
        array $running,
        User $user,
        array $organizationCapabilities,
        array $branchCapabilities,
        array $removed,
        array $denied,
    ): array {
        // Levels one and two, which no role and no owner can talk round.
        if ($module === null || ! in_array($module, $entitled, true)) {
            return ['allowed' => false, 'source' => self::NOT_SOLD];
        }

        if (! in_array($module, $running, true)) {
            return ['allowed' => false, 'source' => self::MODULE_OFF];
        }

        if ($user->isOwner()) {
            return ['allowed' => true, 'source' => self::OWNER];
        }

        /*
         * A deny is subtracted last and beats everything under it, so it is
         * reported ahead of the reasons it overrules — otherwise a capability
         * denied to one person would read as though their role never had it.
         */
        if (in_array($capability, $denied, true)
            && (in_array($capability, $organizationCapabilities, true)
                || in_array($capability, $branchCapabilities, true))) {
            return ['allowed' => false, 'source' => self::USER_DENIED];
        }

        // The union, and never a chain: an organization role grants on its own.
        if (in_array($capability, $organizationCapabilities, true)) {
            return ['allowed' => ! in_array($capability, $denied, true), 'source' => self::ORGANIZATION_ROLE];
        }

        if (in_array($capability, $branchCapabilities, true)) {
            return in_array($capability, $removed, true)
                ? ['allowed' => false, 'source' => self::BRANCH_OVERRIDE]
                : ['allowed' => true, 'source' => self::BRANCH_ROLE];
        }

        return ['allowed' => false, 'source' => self::NO_ROLE];
    }
}
