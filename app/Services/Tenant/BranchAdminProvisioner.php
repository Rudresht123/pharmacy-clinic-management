<?php

namespace App\Services\Tenant;

use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Hash;

/**
 * Gives a new branch somebody who can run it.
 *
 * Adding a branch and then discovering it has nobody who can sign in is the
 * commonest way a new site sits unused for a week. This does the three steps
 * that were previously three screens — write a role, create the person, put
 * them at the branch — in one write, from the details typed on the branch
 * form.
 *
 * The role is written FOR THAT BRANCH, from the modules that branch runs. So
 * an admin at a counter-only branch gets no clinical permissions, and one at a
 * clinic does, without anybody choosing capability by capability.
 */
class BranchAdminProvisioner
{
    /** What running a branch means, wherever the capability exists. */
    private const WANTED = [
        'branches.view',
        'people.view', 'people.create', 'people.edit', 'people.delete', 'people.roles',
        'customers.view', 'customers.create', 'customers.edit', 'customers.delete',
        'appointments.view', 'appointments.book', 'appointments.queue',
        'appointments.cancel', 'appointments.doctors', 'appointments.schedule',
        'prescriptions.view', 'prescriptions.write',
    ];

    /**
     * @param  array{name: string, email: string, password: string}  $admin
     * @param  list<string>  $modulesHere  what the organization runs at this branch
     */
    public function provision(Location $branch, array $admin, array $modulesHere): User
    {
        $role = $this->roleFor($branch, $modulesHere);

        $user = User::create([
            'name' => $admin['name'],
            'email' => $admin['email'],
            'password' => Hash::make($admin['password']),
            'is_active' => true,
            'role' => User::STAFF,

            /*
             * Nothing in the organization slot. Their authority is this
             * branch's, and putting it on the person would make it the whole
             * network's — which is the distinction the membership exists for.
             */
            'role_id' => null,
        ]);

        BranchMembership::create([
            'user_id' => $user->id,
            'location_id' => $branch->id,
            'role_id' => $role->id,
            'is_primary' => true,
        ]);

        return $user;
    }

    /**
     * This branch's own "Branch Admin" role.
     *
     * Reused if it exists, because adding a second admin to a branch should
     * put them on the same role rather than write a near-duplicate beside it.
     */
    private function roleFor(Location $branch, array $modulesHere): Role
    {
        $role = Role::where('location_id', $branch->id)
            ->where('slug', 'branch-admin-'.$branch->id)
            ->first();

        if (! $role) {
            $role = Role::create([
                'name' => 'Branch Admin',
                'slug' => 'branch-admin-'.$branch->id,
                'scope' => Role::SCOPE_BRANCH,
                'location_id' => $branch->id,
                'icon' => 'ti ti-briefcase',
                'description' => 'Runs '.$branch->name.' — its staff, its patients and its diary.',
            ]);
        }

        /*
         * Only what this branch actually runs. A capability whose module is
         * switched off here would be a permission that means nothing, and
         * SaveRoleRequest would refuse it anyway — better to never offer it.
         */
        $available = ModuleRegistry::capabilitiesFor($modulesHere);

        $role->load('capabilities')->syncCapabilities(
            array_values(array_intersect(self::WANTED, $available))
        );

        return $role;
    }
}
