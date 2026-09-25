<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\User;
use App\Models\Tenant\UserPermissionOverride;
use App\Services\Permissions\Permission;
use App\Services\Permissions\StaffScope;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * One person's permissions, and the small differences from their role.
 *
 * The role is still the unit. This screen exists so "the receptionist role,
 * but this one may not issue refunds" does not mean cloning the role — three
 * receptionists with three small differences used to mean three roles, which
 * then drifted apart until nobody could say what a receptionist was.
 *
 * ONLY DENIES ARE WRITABLE. A deny cannot escalate, so it needs no new guard
 * beyond "may this person administer that person at all". An `allow` would be
 * a second way to hand out a capability and would have to be checked
 * everywhere a role already is.
 *
 * WHERE a deny applies follows the actor's own authority: somebody who
 * administers one branch writes a deny at that branch, and somebody who
 * administers the network writes one that applies everywhere. Neither has to
 * choose, because neither has a meaningful second option.
 */
class UserPermissionController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
        private readonly StaffScope $scope,
    ) {}

    /**
     * What this person holds, where it comes from, and what was taken off.
     *
     * Three lists rather than one, because the screen has to show the role's
     * defaults as the thing being edited AGAINST — a flat list of effective
     * capabilities cannot say which ticks are the role and which are this
     * person.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->mustReach($user);

        $organization = $request->attributes->get('tenant.organization');
        $branch = $this->branchFor($request);

        $user->load(['permissionRole.capabilities', 'memberships.role.capabilities', 'permissionOverrides']);

        /*
         * What the ROLES grant, before anything is taken off. Read from the
         * roles rather than from Permission, which already subtracts denies —
         * the screen needs the before as well as the after.
         */
        $fromRoles = array_values(array_unique([
            ...($user->permissionRole?->capabilityKeys() ?? []),
            ...($user->membershipAt($branch)?->capabilityKeys() ?? []),
        ]));

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_owner' => $user->isOwner(),
            ],

            /* Where these denies will be written, so the screen can say so. */
            'scope' => [
                'location_id' => $branch,
                'applies_everywhere' => $branch === null,
            ],

            'organization_role' => $user->permissionRole?->only(['id', 'name']),
            'branch_role' => $user->membershipAt($branch)?->role?->only(['id', 'name']),

            'from_roles' => $fromRoles,
            'denied' => $user->deniedAt($branch),

            /*
             * The effective answer, from the same service every route uses —
             * so what this screen shows and what the API will do cannot
             * disagree. An owner's is the whole pool; theirs is not editable
             * and mustReach has already refused a non-owner trying.
             */
            'effective' => $organization
                ? $this->permission->capabilitiesFor($organization, $user, $branch)
                : [],
        ]);
    }

    /**
     * Replace what is taken off this person, at this actor's scope.
     *
     * The whole set in one write, like a doctor's week and a person's
     * branches: "which capabilities are denied" is a fact about the set, and a
     * per-row endpoint would let half a change land.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->mustReach($user);

        $validated = $request->validate([
            'denied' => ['present', 'array'],
            'denied.*' => ['string', 'max:100'],
        ]);

        /*
         * An owner is not limited by anything, by definition — they bypass
         * roles entirely, so a deny row against them would be a row that
         * decides nothing and reads as though it does.
         */
        if ($user->isOwner()) {
            return $this->fail('An owner is not limited by permissions.', 422);
        }

        $unknown = array_values(array_filter(
            $validated['denied'],
            fn (string $capability) => ! ModuleRegistry::supportsCapability($capability),
        ));

        if ($unknown !== []) {
            return $this->fail(
                '"'.$unknown[0].'" no longer exists. Reload the page to get the current list.',
                422,
            );
        }

        $branch = $this->branchFor($request);
        $actor = Auth::guard('web')->user();
        $wanted = array_values(array_unique($validated['denied']));

        DB::connection('organization')->transaction(function () use ($user, $branch, $wanted, $actor) {
            /*
             * Only rows at THIS scope are replaced.
             *
             * A branch manager sending "deny nothing" is saying nothing about
             * the organization-wide denies head office wrote, and clearing
             * those because they were absent from a payload the manager could
             * not see would be the same mistake the branches endpoint already
             * avoids.
             */
            $user->permissionOverrides()
                ->where('location_id', $branch)
                ->whereNotIn('capability', $wanted ?: [''])
                ->delete();

            foreach ($wanted as $capability) {
                $user->permissionOverrides()->updateOrCreate(
                    ['location_id' => $branch, 'capability' => $capability],
                    ['effect' => UserPermissionOverride::DENY, 'created_by' => $actor?->getKey()],
                );
            }
        });

        return $this->show($request, $user->fresh());
    }

    /**
     * Where this actor's denies apply.
     *
     * Their acting branch, or everywhere for the owner and head office — who
     * work across the network rather than at a counter. Taken from the value
     * ResolveActingBranch already checked against their memberships, so it is
     * never a number the client chose.
     */
    private function branchFor(Request $request): ?int
    {
        $branch = $request->attributes->get('tenant.branch');

        return $branch === null ? null : (int) $branch;
    }

    /**
     * Refuse somebody outside the caller's reach.
     *
     * 404 rather than 403, matching UserController: `permission:people.edit`
     * has already said they may administer staff, so the only thing left is
     * whether THIS person is theirs to administer — and 403 would confirm the
     * id exists to somebody who may not see it.
     */
    private function mustReach(User $user): void
    {
        if (! $this->scope->canManage(Auth::guard('web')->user(), $user)) {
            abort(404, 'Resource not found.');
        }
    }
}
