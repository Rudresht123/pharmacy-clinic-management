<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\User;
use App\Services\Permissions\EffectivePermissions;
use App\Services\Permissions\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What one person can actually do at one branch, and why.
 *
 * The debugging screen for a system that now asks six questions before a
 * button appears. Every one of those levels can be the reason somebody cannot
 * do something, they all look identical from outside, and five of the six are
 * fixed by a different person on a different screen — so "you do not have
 * permission" is the least useful true sentence the software can say.
 *
 * Behind `people.view`, which is what somebody administering staff already
 * holds: it reveals nothing about a person that their own role screen does not
 * already say, and withholding it from the people who answer "why can't I…"
 * would leave them reading the database by hand.
 */
class EffectivePermissionController extends BaseApiController
{
    public function __construct(
        private readonly EffectivePermissions $effective,
        private readonly Permission $permission,
    ) {}

    public function show(Request $request, User $user): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $caller = $request->user();

        if (! $organization || ! $caller instanceof User) {
            abort(403, 'This action is not available to you.');
        }

        $user->loadMissing(['permissionRole.capabilities', 'memberships.role.capabilities', 'permissionOverrides']);

        /*
         * Which branch the answer is about. A branch manager may ask only
         * about their own, whatever they send — the same rule their staff
         * list already follows, and the reason this does not take an arbitrary
         * id from the query string for anybody but the owner.
         */
        $asked = $request->filled('location_id') ? (int) $request->input('location_id') : null;

        $branch = $caller->isOwner()
            ? $asked
            : $this->permission->branchFor($caller);

        return $this->ok($this->effective->for($organization, $user, $branch));
    }
}
