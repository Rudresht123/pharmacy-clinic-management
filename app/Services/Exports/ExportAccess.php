<?php

namespace App\Services\Exports;

use App\Models\Platform\Organization;
use App\Models\Tenant\ExportColumnRule;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Support\Modules\ModuleRegistry;

/**
 * What one person may take out of the Export Center — which tabs, at which
 * branches, and which columns of each export.
 *
 * WHERE follows from where the tab's capability is held, not from where the
 * person sits:
 *
 *   owner                — every branch;
 *   an organization role — every branch, which is how head office gets the
 *                          whole network's records and not none of them (it
 *                          holds no branch of its own, so TenantBranchAccess
 *                          alone would give it nothing);
 *   a branch role        — the branches whose membership grants it, and no
 *                          others: a manager at Lucknow and a receptionist at
 *                          Delhi exports Lucknow's bills only.
 *
 * WHICH COLUMNS is the roles' column rules (ExportColumnRule). A person sees
 * a column if any role that gives them the tab shows it — the roles are a
 * union here as everywhere in Permission, never a chain. The owner sees all.
 */
final class ExportAccess
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    /**
     * The branches this person may export a tab at: null for every branch,
     * an empty list for none — the tab is not theirs.
     *
     * @return list<int>|null
     */
    public function branches(Organization $organization, User $user, string $tab): ?array
    {
        $definition = ExportCatalog::TABS[$tab] ?? null;

        if ($definition === null || ! $this->permission->hasModule($organization, $definition['module'])) {
            return [];
        }

        $capability = $definition['capability'];

        if ($user->isOwner()) {
            return $this->permission->allows($organization, $user, $capability) ? null : [];
        }

        if ($this->heldOrganizationWide($user, $capability)) {
            return null;
        }

        /*
         * An organization-scoped tab — communication — is the whole log or
         * none of it, and a branch role cannot give it: there is no branch's
         * share of a log that has no branches.
         */
        if (ModuleRegistry::capabilityScope($capability) === ModuleRegistry::SCOPE_ORGANIZATION) {
            return [];
        }

        $branches = [];

        foreach ($user->memberships as $membership) {
            $branch = (int) $membership->location_id;

            if ($this->permission->allows($organization, $user, $capability, $branch)) {
                $branches[] = $branch;
            }
        }

        return array_values(array_unique($branches));
    }

    /**
     * Whether an export exists for this organization at all — its tab's
     * module and its own both running. Not a question about the person: an
     * export of a module never sold is not forbidden, it is not there.
     */
    public function offers(Organization $organization, Dataset $dataset): bool
    {
        $modules = array_filter([ExportCatalog::TABS[$dataset->tab()]['module'] ?? null, $dataset->module()]);

        foreach ($modules as $module) {
            if (! $this->permission->hasModule($organization, $module)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The columns of an export this person may take, in the export's order.
     * Empty when every role that gives them the tab hides every column —
     * which takes that one export away from them.
     *
     * @param  list<int>|null  $branches  where they are exporting
     * @return list<Column>
     */
    public function columns(User $user, Dataset $dataset, ?array $branches): array
    {
        $hidden = $this->hidden($user, $dataset, $branches);

        return array_values(array_filter(
            $dataset->columns(),
            fn (Column $column) => ! in_array($column->key, $hidden, true),
        ));
    }

    /**
     * Hidden from this person: what EVERY role giving them the tab hides.
     *
     * @param  list<int>|null  $branches
     * @return list<string>
     */
    private function hidden(User $user, Dataset $dataset, ?array $branches): array
    {
        if ($user->isOwner()) {
            return [];
        }

        $roles = $this->rolesGranting($user, (string) ExportCatalog::capability($dataset->tab()), $branches);

        if ($roles === []) {
            return array_map(fn (Column $column) => $column->key, $dataset->columns());
        }

        $rules = ExportColumnRule::query()
            ->whereIn('role_id', $roles)
            ->where('dataset', $dataset->key())
            ->pluck('hidden_columns', 'role_id');

        $hidden = null;

        foreach ($roles as $role) {
            // A role with no rule for this export hides nothing.
            $theirs = (array) ($rules[$role] ?? []);
            $hidden = $hidden === null ? $theirs : array_values(array_intersect($hidden, $theirs));
        }

        return $hidden ?? [];
    }

    /**
     * The roles that give this person a tab: their organization role, and
     * their branch roles at the branches being exported.
     *
     * @param  list<int>|null  $branches
     * @return list<int>
     */
    private function rolesGranting(User $user, string $capability, ?array $branches): array
    {
        $roles = [];

        if ($user->permissionRole?->grants($capability)) {
            $roles[] = (int) $user->permissionRole->getKey();
        }

        $organizationOnly = ModuleRegistry::capabilityScope($capability) === ModuleRegistry::SCOPE_ORGANIZATION;

        foreach ($user->memberships as $membership) {
            if ($organizationOnly || ($branches !== null && ! in_array((int) $membership->location_id, $branches, true))) {
                continue;
            }

            if ($membership->role?->grants($capability)) {
                $roles[] = (int) $membership->role->getKey();
            }
        }

        return array_values(array_unique($roles));
    }

    private function heldOrganizationWide(User $user, string $capability): bool
    {
        return (bool) $user->permissionRole?->grants($capability)
            && ! in_array($capability, $user->deniedAt(null), true);
    }
}
