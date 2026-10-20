<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Permissions\StaffScope;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * One request for creating and editing a role — they differ only in whether a
 * row already exists to exclude from the name check.
 *
 * Authorization is the `tenant.owner` middleware on the route, not this class —
 * the house convention throughout the codebase. Role management is deliberately
 * NOT delegatable to a capability: a role that could edit roles could grant
 * itself every other one, so there would be nothing left for the other levels
 * to decide.
 */
class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            /*
             * Rule::unique(Role::class, ...) rather than the string 'roles'.
             * A string table name resolves against the *default* connection —
             * the master database, which has no roles table — and fails with a
             * 500 rather than a validation error.
             */
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique(Role::class, 'name')->ignore($role?->getKey()),
            ],

            /*
             * Where the role can be assigned, and therefore where it means
             * anything. Set once, at creation: changing a live role's scope
             * would silently move every holder's permissions somewhere else,
             * so the update path ignores it (see RoleController::update).
             */
            'scope' => ['nullable', 'string', Rule::in(Role::SCOPES)],

            'description' => ['nullable', 'string', 'max:255'],

            // A closed list: this value ends up in a `class` attribute, so it
            // has to be one the software chose rather than one somebody sent.
            'icon' => ['nullable', 'string', Rule::in(Role::ICONS)],

            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', 'max:100'],

            /*
             * Which of those a branch may not take away for itself.
             *
             * `sometimes` on purpose: a client that says nothing about locks
             * leaves them exactly as they were, while an empty array unlocks
             * everything. Those are different intentions and the difference
             * matters — see Role::syncCapabilities.
             */
            'locked' => ['sometimes', 'array'],
            'locked.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Every capability has to be one this organization actually holds.
     *
     * Checked against the entitlement pool rather than the whole registry: a
     * capability belonging to a module nobody bought must not be storable, or
     * buying that module later would silently hand out permissions somebody
     * chose while it was unavailable and nothing was enforcing the choice.
     *
     * Written as an after-hook rather than Rule::in so the message can name
     * which key was wrong — an array rule reports only the index.
     */
    public function after(Permission $permission): array
    {
        return [
            function (Validator $validator) use ($permission) {
                $organization = $this->attributes->get('tenant.organization');

                if (! $organization) {
                    return;
                }

                $pool = [];

                foreach ($permission->grantable($organization) as $module) {
                    foreach ($module['capabilities'] as $capability) {
                        $pool[] = $capability['key'];
                    }
                }

                $scope = $this->effectiveScope();
                $this->refuseWideningOwnRole($validator, $permission);

                foreach ((array) $this->input('capabilities', []) as $index => $capability) {
                    /*
                     * The rule is ASYMMETRIC, and only one direction is wrong.
                     *
                     * An organization role may hold anything: it applies across
                     * the network, so a branch-scoped capability on it simply
                     * applies everywhere — which is exactly what head-office HR
                     * needs from `people.view`.
                     *
                     * A branch role may NOT hold an organization-scoped one.
                     * `settings.manage` changes what every branch calls a
                     * patient; there is no version of it that applies at one
                     * branch, so offering it there is a choice the software
                     * cannot honour.
                     */
                    if ($scope === Role::SCOPE_BRANCH
                        && ModuleRegistry::capabilityScope($capability) === ModuleRegistry::SCOPE_ORGANIZATION) {
                        $validator->errors()->add(
                            "capabilities.{$index}",
                            "\"{$capability}\" applies across the whole organization and cannot be put on a branch role."
                        );

                        continue;
                    }

                    if (in_array($capability, $pool, true)) {
                        continue;
                    }

                    /*
                     * Two different failures used to share one sentence, and
                     * the wrong one was far more common. A key the registry
                     * has never heard of does not mean the organization was
                     * not sold something — it means the page was loaded
                     * before a release that renamed or retired it, and the
                     * only useful instruction is to reload. Saying "your
                     * organization cannot grant this" there sends somebody to
                     * the super admin to fix a problem that is not theirs.
                     */
                    $validator->errors()->add(
                        "capabilities.{$index}",
                        ModuleRegistry::supportsCapability($capability)
                            ? "Your organization has not been sold the module that grants \"{$capability}\"."
                            : "\"{$capability}\" no longer exists. Reload the page to get the current list."
                    );
                }
            },
        ];
    }

    /**
     * The scope this role will actually have once saved — never simply what
     * the request says.
     *
     * RoleController decides it: an existing role keeps its own (scope is not
     * editable), and anybody but the owner writes branch roles only. Reading
     * `scope` off the request instead let a branch manager send
     * "organization" and slip an organization-only capability past the check
     * below onto what is, and stays, a branch role.
     */
    private function effectiveScope(): string
    {
        $role = $this->route('role');

        if ($role instanceof Role) {
            return $role->scope;
        }

        $actor = Auth::guard('web')->user();

        return $actor instanceof User && $actor->isOwner()
            ? (string) $this->input('scope', Role::SCOPE_BRANCH)
            : Role::SCOPE_BRANCH;
    }

    /**
     * Nobody widens a role they hold themselves beyond what they already have.
     *
     * Handing a role out is checked (StaffScope::canGrant), but somebody who
     * already holds a role their branch wrote never needs to hand it out —
     * editing it was a way to grant themselves anything the branch runs.
     * Only what is being ADDED is compared, so removing permissions, or
     * renaming, stays open; and writing roles for other people is unchanged.
     */
    private function refuseWideningOwnRole(Validator $validator, Permission $permission): void
    {
        $role = $this->route('role');
        $actor = Auth::guard('web')->user();
        $organization = $this->attributes->get('tenant.organization');

        if (! $role instanceof Role || ! $actor instanceof User || ! $organization || $actor->isOwner()) {
            return;
        }

        $holdsIt = (int) $actor->role_id === (int) $role->getKey()
            || $actor->memberships->contains(fn ($membership) => (int) $membership->role_id === (int) $role->getKey());

        if (! $holdsIt) {
            return;
        }

        $added = array_values(array_diff((array) $this->input('capabilities', []), $role->capabilityKeys()));

        if (! app(StaffScope::class)->canGrant($actor, $added, $permission->capabilitiesFor($organization, $actor))) {
            $validator->errors()->add(
                'capabilities',
                'You hold this role yourself, so you cannot add permissions to it that you do not already have. Ask an owner to change it.'
            );
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another role is already called this.',
            'scope.in' => 'A role applies either across the organization or at one branch.',
            'capabilities.present' => 'Send the permissions this role holds, even if it holds none.',
        ];
    }
}
