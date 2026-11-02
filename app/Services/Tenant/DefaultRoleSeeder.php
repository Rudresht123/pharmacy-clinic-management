<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Services\Modules\FeatureResolver;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Modules\ModuleRegistry;
use App\Support\Roles\RoleTemplates;
use Illuminate\Support\Facades\DB;

/**
 * Give each branch the roles a clinic actually staffs, as its own — and the
 * organization its one role of its own, the patient app's.
 *
 * EACH BRANCH SETS ITS OWN ROLES, so these are a starting point a branch then
 * edits, renames or deletes — written at the branch (`location_id` set), never
 * shared across branches. Drawn from the modules running AT THAT BRANCH, so a
 * branch that does not run the pharmacy is not given a Pharmacist.
 *
 * A service rather than a migration, for a concrete reason: which modules run
 * where lives partly in the master database, which a migration cannot read.
 * Called with the tenant already connected.
 */
class DefaultRoleSeeder
{
    /**
     * Templates that only ever meant "across every branch". Head office's
     * job is the network's, and a branch copy of it would be a second Branch
     * manager under a misleading name.
     */
    private const ACROSS_BRANCHES_ONLY = ['head_office'];

    /*
     * FeatureResolver rather than Permission: Permission remembers what it
     * worked out for the rest of the request, and seeding runs while the
     * organization is still being set up — a cached "no modules yet" would
     * outlive the moment it was true.
     */
    public function __construct(
        private readonly FeatureResolver $features,
    ) {}

    /**
     * Starting roles for every branch that has none yet.
     *
     * Only branches with no roles at all: a branch that deleted a starting
     * role it did not want must not see it come back on the next deploy.
     *
     * @return list<string> the slugs actually created, for the caller to report
     */
    public function seed(Organization $organization): array
    {
        // The patient app's role is predefined, and the organization's own.
        app(PatientAccountProvisioner::class)->role($this->features->enabled($organization, null));

        $created = [];

        $branches = Location::on(TenantConnectionService::CONNECTION)
            ->whereDoesntHave('roles')
            ->orderBy('id')
            ->get();

        foreach ($branches as $branch) {
            array_push($created, ...$this->seedBranch($organization, $branch));
        }

        return $created;
    }

    /**
     * One branch's starting roles.
     *
     * IDEMPOTENT by name and slug within the branch: a role the branch already
     * has under that name — one somebody wrote by hand — is left untouched.
     *
     * @return list<string> the slugs actually created
     */
    public function seedBranch(Organization $organization, Location $branch): array
    {
        $modules = $this->features->enabled($organization, $branch->id);
        $created = [];

        foreach (RoleTemplates::all() as $template) {
            if (in_array($template['key'], self::ACROSS_BRANCHES_ONLY, true)
                || ! RoleTemplates::appliesTo($template, $modules)) {
                continue;
            }

            $role = $this->seedOne($template, $branch, $modules);

            if ($role !== null) {
                $created[] = $role->slug;
            }
        }

        return $created;
    }

    /**
     * The branch's own copy of one starting role — found, or written now.
     *
     * How a doctor posted to a branch gets that branch's Doctor role without
     * the branch having been seeded first.
     *
     * @param  array<string, mixed>  $template  one of RoleTemplates::all()
     * @param  list<string>|null  $modules  what runs at the branch, when already known
     */
    public function roleAt(array $template, Location $branch, Organization $organization, ?array $modules = null): ?Role
    {
        $existing = Role::on(TenantConnectionService::CONNECTION)
            ->where('location_id', $branch->id)
            ->where('slug', Role::slugAt($template['slug'], $branch->id))
            ->first();

        return $existing ?? $this->seedOne($template, $branch, $modules ?? $this->features->enabled($organization, $branch->id));
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  list<string>  $modules
     */
    private function seedOne(array $template, Location $branch, array $modules): ?Role
    {
        $connection = TenantConnectionService::CONNECTION;
        $slug = Role::slugAt($template['slug'], $branch->id);

        $taken = Role::on($connection)
            ->where('location_id', $branch->id)
            ->where(fn ($query) => $query
                ->where('slug', $slug)
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($template['name'])]))
            ->exists();

        if ($taken) {
            return null;
        }

        // A branch role holds branch-level permissions only.
        $capabilities = array_values(array_filter(
            RoleTemplates::capabilitiesFor($template, $modules),
            fn (string $capability) => ModuleRegistry::capabilityScope($capability) !== ModuleRegistry::SCOPE_ORGANIZATION,
        ));

        if ($capabilities === []) {
            return null;
        }

        /*
         * One transaction per role rather than one for all of them. A template
         * that fails — a capability retired between releases, say — should
         * not take the others with it.
         */
        return DB::connection($connection)->transaction(function () use ($template, $branch, $slug, $capabilities, $connection) {
            $role = Role::on($connection)->create([
                'name' => $template['name'],
                'slug' => $slug,
                'scope' => Role::SCOPE_BRANCH,
                'location_id' => $branch->id,
                'description' => $template['description'],
                'icon' => $template['icon'],
            ]);

            $role->syncCapabilities($capabilities);

            return $role;
        });
    }
}
