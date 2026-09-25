<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\LocationResource;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Roles\RoleTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who runs a branch.
 *
 * Its own controller and its own capability, held apart from editing a branch.
 * Editing changes an address; naming a manager decides who administers the
 * people there — so a manager holding it could appoint themselves somewhere
 * else, or appoint somebody who would appoint them back.
 *
 * The important thing this does is NOT setting `manager_id`. That column is a
 * cache of a fact. What actually changes what somebody may do is the
 * membership row and the role on it, and both halves move together, in one
 * transaction, so a branch can never end up with a manager who holds nothing
 * or a role-holder nobody calls the manager.
 */
class LocationManagerController extends BaseApiController
{
    /** The seeded role a manager is put on, unless the caller names another. */
    private const TEMPLATE = 'branch_manager';

    public function assign(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists(User::class, 'id')->whereNull('deleted_at'),
            ],

            /*
             * Optional. An organization that renamed or rewrote its manager
             * role says so here; everybody else gets the seeded one. Branch
             * scope is checked below rather than in the rule, so the message
             * can say why.
             */
            'role_id' => ['nullable', 'integer', Rule::exists(Role::class, 'id')],
        ]);

        $target = User::with('memberships')->findOrFail($validated['user_id']);

        if ($target->isOwner()) {
            return $this->fail(
                'An owner already runs every branch and is not made a manager of one.',
                422,
            );
        }

        if ($target->isPatient()) {
            return $this->fail('That account is a patient login, not a member of staff.', 422);
        }

        if (! $target->is_active) {
            return $this->fail(
                'That account is deactivated. Reactivate it before making them a manager.',
                422,
            );
        }

        $role = $this->managerRole($validated['role_id'] ?? null, $location);

        if ($role === null) {
            return $this->fail(
                'This organization has no branch manager role. Create one on the Roles screen first.',
                422,
            );
        }

        $outgoing = $location->manager;

        // Already theirs, on the same role: nothing to do, and saying so beats
        // writing an audit entry that records no change.
        if ($outgoing?->getKey() === $target->getKey()
            && (int) ($target->membershipAt($location->getKey())?->role_id) === $role->getKey()) {
            return $this->ok(
                LocationResource::make($location->load('manager')),
                "{$target->name} already runs this branch.",
            );
        }

        DB::connection('organization')->transaction(function () use ($location, $target, $role, $outgoing) {
            /*
             * The outgoing manager keeps their MEMBERSHIP and loses the ROLE.
             *
             * Somebody stepping down from running Gurgaon usually still works
             * at Gurgaon, and deleting the row would also delete the record of
             * their having been there. Nulling the role leaves them a member
             * with nothing assigned — a real state the membership table
             * already models, for a new joiner whose role is undecided.
             */
            if ($outgoing && $outgoing->getKey() !== $target->getKey()) {
                $previous = $outgoing->membershipAt($location->getKey());

                if ($previous && (int) $previous->role_id === $role->getKey()) {
                    $previous->update(['role_id' => null]);
                }
            }

            /*
             * The incoming one gets a membership at this branch if they had
             * none, and the manager role on it either way. `is_primary` is
             * left alone deliberately: where somebody's app opens is their
             * preference, not something an appointment should overwrite.
             */
            BranchMembership::updateOrCreate(
                ['user_id' => $target->getKey(), 'location_id' => $location->getKey()],
                ['role_id' => $role->getKey()],
            );

            /*
             * Last, so a failure above leaves the branch with the manager it
             * had rather than with a name that grants nothing.
             *
             * forceFill rather than update(): `manager_id` is deliberately NOT
             * fillable, so the ordinary branch form cannot set it however the
             * payload is shaped. It is reachable from here and nowhere else.
             */
            $location->forceFill(['manager_id' => $target->getKey()])->save();
        });

        return $this->ok(
            LocationResource::make($location->fresh()->load('manager')),
            $outgoing && $outgoing->getKey() !== $target->getKey()
                ? "{$target->name} now runs this branch, in place of {$outgoing->name}."
                : "{$target->name} now runs this branch.",
        );
    }

    /** Leave the branch unmanaged, which is how every branch starts. */
    public function revoke(Location $location): JsonResponse
    {
        $manager = $location->manager;

        if (! $manager) {
            return $this->fail('Nobody runs this branch.', 422);
        }

        $role = $this->managerRole(null, $location);

        DB::connection('organization')->transaction(function () use ($location, $manager, $role) {
            $membership = $manager->membershipAt($location->getKey());

            // Only the manager role comes off. If they have since been moved
            // to another role here, that is the one they were meant to have.
            if ($membership && $role && (int) $membership->role_id === $role->getKey()) {
                $membership->update(['role_id' => null]);
            }

            $location->forceFill(['manager_id' => null])->save();
        });

        return $this->ok(
            LocationResource::make($location->fresh()->load('manager')),
            "{$manager->name} no longer runs this branch.",
        );
    }

    /**
     * The role a manager is put on here.
     *
     * A named one has to be assignable AT THIS BRANCH — the organization's own
     * roles, plus this branch's, and never another branch's. It also has to be
     * branch-scoped: an organization-scoped role would apply everywhere, so
     * "manager of Gurgaon" would quietly be manager of the network.
     */
    private function managerRole(?int $roleId, Location $location): ?Role
    {
        if ($roleId !== null) {
            return Role::query()
                ->assignableAt($location->getKey())
                ->where('scope', Role::SCOPE_BRANCH)
                ->find($roleId);
        }

        $template = RoleTemplates::find(self::TEMPLATE);

        return Role::where('slug', $template['slug'] ?? 'branch-manager')->first();
    }
}
