<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Role;
use App\Services\Permissions\Permission;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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

            'description' => ['nullable', 'string', 'max:255'],

            // A closed list: this value ends up in a `class` attribute, so it
            // has to be one the software chose rather than one somebody sent.
            'icon' => ['nullable', 'string', Rule::in(Role::ICONS)],

            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['string', 'max:100'],
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

                foreach ((array) $this->input('capabilities', []) as $index => $capability) {
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another role is already called this.',
            'capabilities.present' => 'Send the permissions this role holds, even if it holds none.',
        ];
    }
}
