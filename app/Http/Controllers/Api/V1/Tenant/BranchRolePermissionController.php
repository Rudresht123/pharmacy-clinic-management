<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\BranchRoleCapability;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How one branch customises one of the organization's roles.
 *
 * The level that did not exist. A role was one row shared by every branch that
 * used it, so a chain wanting Delhi's receptionists to hold less than
 * Lucknow's had to clone the role — and five copies of "Receptionist" drift
 * apart until the fifth quietly has a deletion nobody meant to grant.
 *
 * ONLY SUBTRACTS. The body names what is allowed here, exactly as the branch's
 * module screen does, and what is stored is the opposite: a row per capability
 * this branch has taken away. A capability the organization never granted the
 * role cannot appear in the body at all, so "a branch may never grant what the
 * organization did not" is not a check that could be forgotten — there is no
 * way to write it down.
 */
class BranchRolePermissionController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    /**
     * What this branch may change about this role, and what it has changed.
     *
     * Three lists rather than a matrix: what the organization grants, what this
     * branch has removed, and what it may not remove. The screen already knows
     * how to group capabilities by module — it draws the pool from `grantable`
     * like every other permission screen — so sending a second, differently
     * shaped copy of the same vocabulary would be two sources to keep in step.
     */
    public function show(Request $request, Location $location, Role $role): JsonResponse
    {
        $this->mustBeCustomisableHere($request, $location, $role);

        $role->loadMissing('capabilities');

        return $this->ok([
            'location_id' => $location->id,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'scope' => $role->scope,
                'icon' => $role->icon,
            ],
            'inherited' => $role->capabilityKeys(),
            'removed' => BranchRoleCapability::removedAt($location->id, $role->id),
            'locked' => $role->lockedCapabilityKeys(),
        ]);
    }

    /**
     * Replace this branch's decisions about this role, in one write.
     */
    public function update(Request $request, Location $location, Role $role): JsonResponse
    {
        $this->mustBeCustomisableHere($request, $location, $role);

        $role->loadMissing('capabilities');

        $inherited = $role->capabilityKeys();

        $validated = $request->validate([
            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', 'max:100'],
        ]);

        $allowed = array_values(array_unique($validated['capabilities']));

        /*
         * Refused rather than ignored. A branch asking for something the
         * organization did not grant this role is either a stale screen or an
         * attempt; both deserve an answer, and silently dropping it would let
         * a branch manager believe they had granted something.
         */
        $beyond = array_values(array_diff($allowed, $inherited));

        if ($beyond !== []) {
            throw ValidationException::withMessages([
                'capabilities' => 'Your organisation has not granted this role: '
                    .implode(', ', $beyond).'.',
            ]);
        }

        $removed = array_values(array_diff($inherited, $allowed));

        $locked = array_values(array_intersect($removed, $role->lockedCapabilityKeys()));

        if ($locked !== []) {
            throw ValidationException::withMessages([
                'capabilities' => 'Locked by your organisation and cannot be removed here: '
                    .implode(', ', $locked).'.',
            ]);
        }

        $this->mustNotLockThemselvesOut($request, $location, $role, $removed);

        $before = BranchRoleCapability::removedAt($location->id, $role->id);

        DB::connection('organization')->transaction(function () use ($location, $role, $removed, $request) {
            BranchRoleCapability::query()
                ->where('location_id', $location->id)
                ->where('role_id', $role->id)
                ->whereNotIn('capability', $removed ?: ['-'])
                ->delete();

            foreach ($removed as $capability) {
                BranchRoleCapability::firstOrCreate(
                    [
                        'location_id' => $location->id,
                        'role_id' => $role->id,
                        'capability' => $capability,
                    ],
                    ['created_by' => $request->user()?->getKey()],
                );
            }
        });

        /*
         * Logged against the BRANCH, not the role: the organization's role did
         * not change, and writing it there would read as though it had. "What
         * has Delhi changed" is a question about Delhi.
         */
        $location->writeHistoryFor("role:{$role->slug}:removed", $before, $removed);

        // Otherwise anything read after this — including the caller's own
        // capabilities, which this may have just changed — answers with the
        // state from before the write.
        $this->permission->forget();

        return $this->show($request, $location, $role);
    }

    /**
     * Refuses the one change that cannot be undone by whoever made it.
     *
     * A branch manager customising the role THEY hold can switch off the very
     * capability this screen needs — and then nobody at that branch can switch
     * it back on, because the screen that would do it is now shut to them. The
     * branch has to wait for the owner.
     *
     * Same family as "the only owner cannot be demoted" and "you cannot remove
     * yourself": the software refuses to help somebody lock themselves out,
     * rather than doing it and explaining afterwards. The owner is exempt —
     * they do not reach this screen through `people.roles` and can always
     * undo it.
     *
     * @param  list<string>  $removed
     */
    private function mustNotLockThemselvesOut(
        Request $request,
        Location $location,
        Role $role,
        array $removed,
    ): void {
        $user = $request->user();

        if (! $user instanceof User || $user->isOwner()) {
            return;
        }

        if (! in_array('people.roles', $removed, true)) {
            return;
        }

        // Only if it is their OWN role here. Another role losing it is an
        // ordinary change, even a sensible one.
        $mine = $user->membershipAt($location->id)?->role_id;

        if ($mine !== null && (int) $mine === (int) $role->getKey()) {
            throw ValidationException::withMessages([
                'capabilities' => 'You hold this role here, so switching off "Manage roles" would '
                    .'leave nobody at this branch able to switch it back on. Ask your organisation '
                    .'owner to make this change.',
            ]);
        }
    }

    /**
     * Whether this person may customise this role, here.
     *
     * Three separate refusals, because they are three different problems and
     * only one of them is the caller's to fix.
     */
    private function mustBeCustomisableHere(Request $request, Location $location, Role $role): void
    {
        $user = $request->user();
        $organization = $request->attributes->get('tenant.organization');

        if (! $user instanceof User || ! $organization) {
            abort(403, 'This action is not available to you.');
        }

        /*
         * A role a branch wrote for itself is edited on the role screen, and an
         * organization-scoped role is head office's. Neither is customised
         * here — two ways to express one change is how the two drift apart.
         */
        if (! $role->isCustomisableByBranch()) {
            abort(403, $role->isOrganizationWide()
                ? 'An organisation-wide role is not customised per branch.'
                : 'This role belongs to one branch already. Edit it directly.');
        }

        if ($user->isOwner()) {
            return;
        }

        if (! $this->permission->allows($organization, $user, 'people.roles')) {
            abort(403, 'This action is not available to you.');
        }

        /*
         * Their own branch and no other. 404 rather than 403 for somebody
         * else's, matching how a record out of reach behaves everywhere else:
         * 403 would confirm the branch exists to somebody who may not see it.
         */
        if ($this->permission->branchFor($user) !== $location->id) {
            abort(404, 'Resource not found.');
        }
    }
}
