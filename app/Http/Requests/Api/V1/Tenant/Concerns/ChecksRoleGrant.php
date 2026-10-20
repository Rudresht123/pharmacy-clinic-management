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
 *
 * Two further rules, both about the role rather than the capabilities in it:
 * a patient's role is never a staff role, whoever is assigning; and only an
 * organization administrator gives or takes away organization-wide access.
 */
trait ChecksRoleGrant
{
    protected function validateRoleGrant(Validator $validator, ?User $target = null): void
    {
        $roleId = $this->input('role_id');
        $actor = Auth::guard('web')->user();

        /*
         * Organization-wide access changes hands only through somebody who
         * works at that level. Asked whenever the slot CHANGES — given,
         * swapped or taken away — and never when an edit leaves it as it
         * was, so a branch manager correcting a colleague's name is not
         * refused over a role they did not touch.
         */
        $changing = $target === null
            ? (bool) $roleId
            : (int) $roleId !== (int) $target->role_id;

        if ($changing && $actor instanceof User && ! app(StaffScope::class)->canGrantOrganizationWide($actor)) {
            $validator->errors()->add(
                'role_id',
                'Only an organization administrator can change organization-wide access. Give them a role at their branch instead.'
            );

            return;
        }

        if (! $roleId) {
            return;
        }

        $role = Role::with('capabilities')->find($roleId);

        if (! $role) {
            // `Rule::exists` has already reported this; adding a second
            // message about the same field only makes it read as two problems.
            return;
        }

        // Before the owner's exemption: this is about who the role is FOR,
        // and an owner putting a member of staff on it is as wrong as anybody.
        if ($role->isForPatients()) {
            $validator->errors()->add(
                'role_id',
                "\"{$role->name}\" is for patients using the app, and cannot be given to staff."
            );

            return;
        }

        $organization = $this->attributes->get('tenant.organization');

        if (! $actor instanceof User || ! $organization || $actor->isOwner()) {
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
