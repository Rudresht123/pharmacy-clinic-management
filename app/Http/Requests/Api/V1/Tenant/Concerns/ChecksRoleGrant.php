<?php

namespace App\Http\Requests\Api\V1\Tenant\Concerns;

use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Permissions\StaffScope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Nobody may put somebody else on a role more powerful than their own.
 *
 * Shared by the two requests that assign a role. Without it, delegating
 * `people.create` or `people.edit` delegated everything else with it: put a
 * junior on the most powerful role in the organization, sign in as them, and
 * whatever limit was carefully chosen is gone. The escalation does not even
 * need a second account — editing your own record does it in one request.
 *
 * The owner is exempt because the owner already holds the whole pool, so the
 * comparison would always pass. Said outright rather than relied upon.
 */
trait ChecksRoleGrant
{
    protected function validateRoleGrant(Validator $validator): void
    {
        $roleId = $this->input('role_id');

        if (! $roleId) {
            return;
        }

        $actor = Auth::guard('web')->user();
        $organization = $this->attributes->get('tenant.organization');

        if (! $actor instanceof User || ! $organization || $actor->isOwner()) {
            return;
        }

        $role = Role::with('capabilities')->find($roleId);

        if (! $role) {
            // `Rule::exists` has already reported this; adding a second
            // message about the same field only makes it read as two problems.
            return;
        }

        $granted = app(StaffScope::class)->canGrant(
            $actor,
            $role->capabilityKeys(),
            app(Permission::class)->capabilitiesFor($organization, $actor),
        );

        if (! $granted) {
            $validator->errors()->add(
                'role_id',
                "You cannot give somebody the \"{$role->name}\" role, because it includes permissions you do not hold yourself."
            );
        }
    }
}
