<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveRoleRequest;
use App\Http\Resources\Tenant\RoleResource;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Services\Permissions\StaffScope;
use App\Services\Tenant\PatientAccountProvisioner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Roles\RoleTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Each branch's own roles — level three of the permission flow.
 *
 * EACH BRANCH SETS ITS OWN. Whoever holds `people.roles` at a branch writes
 * that branch's roles from the modules running there; the owner decides which
 * modules each branch runs (the Modules screen). The one role at the
 * organization's level is the patient app's — the owner's alone, and never
 * shown to a branch.
 *
 * @group Tenant > Users, Roles & Permissions
 */
class RoleController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
        private readonly StaffScope $staff,
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

        /*
         * Narrowed by scope when the screen asks. A branch role is not offered
         * what it may not hold (ModuleRegistry::branchRoleMayHold) — not greyed
         * out, not shown at all.
         */
        $scope = in_array($request->query('scope'), Role::SCOPES, true)
            ? $request->query('scope')
            : null;

        if (! $organization) {
            return $this->ok(['modules' => []]);
        }

        /*
         * The modules running WHERE THIS PERSON IS, not everything the
         * organization holds. That is what bounds a branch writing its own
         * roles: it can only draw on what the owner switched on there, so it
         * can never invent a permission the organization did not give it.
         *
         * The owner is organization-wide, so they see the whole pool.
         */
        $user = $request->user();
        $branch = $user instanceof User ? $this->permission->branchFor($user) : null;

        return $this->ok([
            'modules' => ModuleRegistry::grantable(
                $this->permission->modulesAt($organization, $branch),
                $scope,
            ),
        ]);
    }

    /**
     * Somewhere to start, when writing a role.
     *
     * The same library the seeder uses, so what an owner is offered here and
     * what a new organization is given on day one cannot drift apart. It used
     * to live in the front-end bundle, which meant two lists that agreed only
     * as long as somebody remembered to edit both.
     *
     * Narrowed to what is grantable WHERE THIS PERSON IS, for the same reason
     * `grantable()` is: a branch writing its own roles can only draw on what
     * the owner switched on there.
     */
    public function templates(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        if (! $organization) {
            return $this->ok([]);
        }

        $user = $request->user();
        $branch = $user instanceof User ? $this->permission->branchFor($user) : null;
        $modules = $this->permission->modulesAt($organization, $branch);
        $pool = ModuleRegistry::capabilitiesFor($modules);

        $templates = [];

        foreach (RoleTemplates::all() as $template) {
            // Every role is a branch's own, so a job that only ever meant
            // "across every branch" (Head office) is not a starting point.
            if ($template['scope'] === Role::SCOPE_ORGANIZATION) {
                continue;
            }

            // Narrowed to what a branch role may actually hold.
            $capabilities = array_values(array_filter(
                RoleTemplates::capabilitiesFor($template, $modules),
                fn (string $capability) => ModuleRegistry::capabilityScope($capability) !== ModuleRegistry::SCOPE_ORGANIZATION,
            ));

            // A template that would grant nothing here is not offered at all.
            if ($capabilities === []) {
                continue;
            }

            $templates[] = [
                'key' => $template['key'],
                'name' => $template['name'],
                'summary' => $template['description'],
                'icon' => $template['icon'],
                'scope' => $template['scope'],
                'capabilities' => $capabilities,
            ];
        }

        /*
         * Derived rather than listed — every capability whose key ends in
         * `.view`. Writing them down would be a second place to remember when
         * a module ships, and the one nobody would remember.
         */
        $readOnly = array_values(array_filter(
            $pool,
            fn (string $key) => str_ends_with($key, '.view')
                && ModuleRegistry::capabilityScope($key) !== ModuleRegistry::SCOPE_ORGANIZATION,
        ));

        if ($readOnly !== []) {
            $templates[] = [
                'key' => 'read_only',
                'name' => 'Read-only',
                'summary' => 'Can look at everything and change nothing.',
                'icon' => 'ti ti-eye',
                'scope' => Role::SCOPE_BRANCH,
                'capabilities' => $readOnly,
            ];
        }

        return $this->ok($templates);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
         * This branch's roles. Another branch's are somebody else's, and the
         * patient app's is the organization's.
         *
         * The owner reads every branch's — they add staff at any branch and
         * pick that branch's role — and the patient app's, which is theirs to
         * set. Never the seeded `staff` row, which nobody is given any more.
         */
        $query = Role::query()->with(['capabilities', 'location']);

        if ($user instanceof User && ! $user->isOwner()) {
            $query->assignableAt($this->permission->branchFor($user));
        } else {
            $query->where(fn ($roles) => $roles
                ->whereNotNull('location_id')
                ->orWhere('slug', PatientAccountProvisioner::ROLE));
        }

        $roles = $query->withCount(['users', 'memberships'])
            ->orderBy('location_id')
            ->orderBy('name')
            ->get();

        /*
         * Whether THIS person may hand each one out — the same answer the
         * assignment requests give (StaffScope::canGrant), asked once here so
         * a dropdown can leave out a role that would only be refused after a
         * round trip. Never a patient's role, which is not staff access at
         * all. A hint for the screen; the requests still decide.
         */
        $organization = $request->attributes->get('tenant.organization');
        $held = $user instanceof User && $organization && ! $user->isOwner()
            ? $this->permission->capabilitiesFor($organization, $user)
            : [];

        $roles->each(fn (Role $role) => $role->setAttribute(
            'assignable',
            $user instanceof User
                && ! $role->isForPatients()
                && $this->staff->canGrant($user, $role->capabilityKeys(), $held),
        ));

        return $this->ok(RoleResource::collection($roles));
    }

    /**
     * The branch this person writes roles for — refused unless they may.
     *
     * Only somebody holding `people.roles` at a branch, and only that
     * branch's roles. The owner writes one role only, the patient app's —
     * the organization's part is that and which modules each branch runs;
     * the branch decides the rest.
     *
     * Null for the owner's patient role, which belongs to no branch.
     */
    private function mustBeWritable(Request $request, ?Role $role = null): ?int
    {
        $user = $request->user();
        $organization = $request->attributes->get('tenant.organization');

        if (! $user instanceof User || ! $organization) {
            abort(403, 'This action is not available to you.');
        }

        if ($user->isOwner()) {
            if ($role !== null && $role->slug === PatientAccountProvisioner::ROLE) {
                return null;
            }

            abort(403, 'Each branch sets its own roles. As the owner, you set the patient app role and decide which modules each branch runs.');
        }

        $branch = $this->permission->branchFor($user);

        if ($branch === null
            || ! $this->permission->allows($organization, $user, 'people.roles')) {
            abort(403, 'This action is not available to you.');
        }

        if ($role !== null && (int) $role->location_id !== $branch) {
            /*
             * 404 rather than 403 for any role not this branch's, matching how
             * a staff record out of reach behaves: answering 403 would confirm
             * the id exists to somebody who may not see it.
             */
            abort(404, 'Resource not found.');
        }

        return $branch;
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

        $branch = $this->mustBeWritable($request);

        $locked = $this->lockedFrom($data, $branch);

        $role = DB::connection('organization')->transaction(function () use ($data, $branch, $locked) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'scope' => Role::SCOPE_BRANCH,
                'location_id' => $branch,
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? Role::DEFAULT_ICON,
            ]);

            $role->syncCapabilities($data['capabilities'], $locked);

            return $role;
        });

        return $this->created(
            RoleResource::make($role->loadCount(['users', 'memberships'])),
            'Role created successfully.'
        );
    }

    public function update(SaveRoleRequest $request, Role $role): JsonResponse
    {
        $branch = $this->mustBeWritable($request, $role);

        $data = $request->validated();

        $locked = $this->lockedFrom($data, $branch);

        DB::connection('organization')->transaction(function () use ($data, $role, $locked) {
            /*
             * The slug is not renamed with the name. It is what the software
             * refers to the seeded role by — Role::SEEDED_STAFF — and renaming
             * "Staff" to "Front desk" should change what people read, not what
             * the code can still find.
             *
             * Scope is not editable either, and for a sharper reason: changing
             * it would move every holder's permissions somewhere else without
             * anybody choosing that. A role that turns out to be the wrong
             * scope is a new role.
             */
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? $role->icon ?? Role::DEFAULT_ICON,
            ]);

            $role->syncCapabilities($data['capabilities'], $locked);
        });

        return $this->ok(
            RoleResource::make($role->load('capabilities')->loadCount(['users', 'memberships'])),
            'Role updated successfully.'
        );
    }

    /**
     * Which capabilities this save locks, if it is in a position to say.
     *
     * A lock is the organization telling branches "not this one", so it is the
     * owner's to set and means nothing anywhere else: a role a branch wrote is
     * that branch's own, and there is no third party to lock it against.
     *
     * Null — leave the locks alone — rather than an empty list, so a branch
     * manager editing a role they may edit cannot unlock it by not mentioning
     * it, and neither can an older client.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private function lockedFrom(array $data, ?int $branch): ?array
    {
        if ($branch !== null || ! array_key_exists('locked', $data)) {
            return null;
        }

        return array_values(array_unique($data['locked']));
    }

    /**
     * Who actually holds this role.
     *
     * "4 members" is the number somebody reads before deciding to narrow a
     * role, and the four names are what they need before deciding whether
     * they dare. Kept to this endpoint rather than folded into `show`, which
     * the editor calls on every selection.
     */
    public function members(Request $request, Role $role): JsonResponse
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

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->mustBeWritable($request, $role);

        // The patient app's role is the software's: every patient login is put on it.
        if ($role->slug === PatientAccountProvisioner::ROLE) {
            abort(422, 'The patient app role cannot be deleted.');
        }

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
