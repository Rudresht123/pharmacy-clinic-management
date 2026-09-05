<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Services\Permissions\Permission;
use App\Services\Permissions\StaffScope;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Where somebody works, and what they hold at each place.
 *
 * The whole set in one write, not one membership at a time. "At most one
 * primary" and "one membership per branch" are rules about the set, and a
 * per-row endpoint could not check either — the same reasoning that makes a
 * doctor's week a single PUT.
 *
 * Authorization is `permission:people.assign_branch` on the route.
 */
class SaveUserBranchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // `present`, not `required`: an empty set is a real instruction —
            // it makes somebody head office rather than branch staff.
            'branches' => ['present', 'array'],

            'branches.*.location_id' => [
                'required', 'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],

            /*
             * Nullable: somebody can belong to a branch while holding nothing
             * there yet — a new joiner whose role has not been decided.
             */
            'branches.*.role_id' => ['nullable', 'integer', Rule::exists(Role::class, 'id')],

            'branches.*.is_primary' => ['nullable', 'boolean'],
        ];
    }

    public function after(Permission $permission, StaffScope $scope): array
    {
        return [
            function (Validator $validator) use ($permission, $scope) {
                $rows = (array) $this->input('branches', []);
                $actor = Auth::guard('web')->user();
                $organization = $this->attributes->get('tenant.organization');

                if (! $actor || ! $organization) {
                    return;
                }

                $this->refuseDuplicates($validator, $rows);
                $this->refuseTwoPrimaries($validator, $rows);

                $branches = app(TenantBranchAccess::class);
                $held = $permission->capabilitiesFor($organization, $actor);

                foreach ($rows as $index => $row) {
                    /*
                     * A branch the assigner cannot act on themselves. Without
                     * this, anybody holding `people.assign_branch` could post
                     * somebody into a branch they have nothing to do with.
                     */
                    if (! $branches->canUse($actor, (int) ($row['location_id'] ?? 0))) {
                        $validator->errors()->add(
                            "branches.{$index}.location_id",
                            'You cannot assign somebody to a branch you do not work at.'
                        );
                    }

                    if (empty($row['role_id'])) {
                        continue;
                    }

                    $role = Role::with('capabilities')->find($row['role_id']);

                    if (! $role) {
                        continue;
                    }

                    /*
                     * A membership carries a BRANCH-scoped role. An
                     * organization role goes on `users.role_id` and applies
                     * everywhere; putting one here would be a limit that reads
                     * as branch-specific and is not.
                     */
                    if ($role->isOrganizationScoped()) {
                        $validator->errors()->add(
                            "branches.{$index}.role_id",
                            "\"{$role->name}\" applies across the whole organization and cannot be given at one branch."
                        );

                        continue;
                    }

                    // The same escalation rule as anywhere else a role is
                    // handed out: nobody grants what they do not hold.
                    if (! $scope->canGrant($actor, $role->capabilityKeys(), $held)) {
                        $validator->errors()->add(
                            "branches.{$index}.role_id",
                            "You cannot give somebody the \"{$role->name}\" role, because it includes permissions you do not hold yourself."
                        );
                    }
                }
            },
        ];
    }

    /** @param  array<int, mixed>  $rows */
    private function refuseDuplicates(Validator $validator, array $rows): void
    {
        $seen = [];

        foreach ($rows as $index => $row) {
            $branch = $row['location_id'] ?? null;

            if ($branch !== null && in_array($branch, $seen, true)) {
                $validator->errors()->add(
                    "branches.{$index}.location_id",
                    'This branch is listed twice. Somebody holds one role at each branch.'
                );
            }

            $seen[] = $branch;
        }
    }

    /** @param  array<int, mixed>  $rows */
    private function refuseTwoPrimaries(Validator $validator, array $rows): void
    {
        $primaries = array_keys(array_filter(
            $rows,
            fn ($row) => filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));

        if (count($primaries) > 1) {
            $validator->errors()->add(
                'branches.'.$primaries[1].'.is_primary',
                'Only one branch can be the one their workspace opens on.'
            );
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branches.present' => 'Send the branches this person works at, even if there are none.',
            'branches.*.location_id.exists' => 'That branch no longer exists.',
            'branches.*.role_id.exists' => 'That role no longer exists.',
        ];
    }
}
