<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveRoleRequest;
use App\Http\Resources\Tenant\RoleResource;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The roles an owner hands to their staff — level three of the permission flow.
 *
 * Owner-only on the route, and deliberately not reachable through a capability.
 * A role able to edit roles could grant itself every other one, which would
 * leave the other two levels deciding nothing.
 */
class RoleController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    /**
     * The pool this organization may hand out, grouped by module.
     *
     * The role screen renders from this rather than from a list in the client,
     * so a capability belonging to a module the organization was never sold
     * cannot be ticked — it is not offered at all, which is the honest way to
     * present something that does not exist here.
     */
    public function grantable(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        return $this->ok([
            'modules' => $organization ? $this->permission->grantable($organization) : [],
        ]);
    }

    public function index(): JsonResponse
    {
        return $this->ok(
            RoleResource::collection(
                Role::query()
                    ->with('capabilities')
                    ->withCount(['users', 'memberships'])
                    ->orderBy('name')
                    ->get()
            )
        );
    }

    public function show(Role $role): JsonResponse
    {
        return $this->ok(
            RoleResource::make($role->load('capabilities')->loadCount(['users', 'memberships']))
        );
    }

    public function store(SaveRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = DB::connection('organization')->transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? Role::DEFAULT_ICON,
            ]);

            $role->syncCapabilities($data['capabilities']);

            return $role;
        });

        return $this->created(
            RoleResource::make($role->loadCount(['users', 'memberships'])),
            'Role created successfully.'
        );
    }

    public function update(SaveRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        DB::connection('organization')->transaction(function () use ($data, $role) {
            /*
             * The slug is not renamed with the name. It is what the software
             * refers to the seeded role by — Role::SEEDED_STAFF — and renaming
             * "Staff" to "Front desk" should change what people read, not what
             * the code can still find.
             */
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? $role->icon ?? Role::DEFAULT_ICON,
            ]);

            $role->syncCapabilities($data['capabilities']);
        });

        return $this->ok(
            RoleResource::make($role->load('capabilities')->loadCount(['users', 'memberships'])),
            'Role updated successfully.'
        );
    }

    /**
     * Who actually holds this role.
     *
     * "4 members" is the number somebody reads before deciding to narrow a
     * role, and the four names are what they need before deciding whether
     * they dare. Kept to this endpoint rather than folded into `show`, which
     * the editor calls on every selection.
     */
    public function members(Role $role): JsonResponse
    {
        /*
         * A role is held two ways and both have to be listed: on
         * `users.role_id` for head office, where it applies across the network,
         * and on a membership for branch staff, where it applies at that branch
         * alone. Somebody holding the same role at two branches is two lines,
         * because "who holds this, and where" is one question.
         */
        $organizationWide = $role->users()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'location' => null,
            ]);

        $atBranches = $role->memberships()
            ->with(['user', 'location'])
            ->get()
            ->filter(fn (BranchMembership $membership) => $membership->user !== null)
            ->map(fn (BranchMembership $membership) => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'is_active' => $membership->user->is_active,
                'location' => $membership->location?->name,
            ]);

        return $this->ok(
            $organizationWide
                ->concat($atBranches)
                ->sortBy(['name', 'location'])
                ->values()
                ->all()
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        /*
         * Refused with a count rather than left to the foreign key. Deleting a
         * role out from under the people holding it would strip them of every
         * permission at once, and they would find out by being unable to work.
         */
        $holders = $role->holderCount();

        if ($holders > 0) {
            abort(422, $holders === 1
                ? 'One person holds this role. Move them to another role first.'
                : "{$holders} people hold this role. Move them to another role first.");
        }

        $role->delete();

        return $this->noContent('Role deleted successfully.');
    }

    /**
     * A stable identifier derived from the name, made unique by suffix.
     *
     * The name is already unique, so this only collides when two names slugify
     * the same way — "Front Desk" and "front-desk".
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
