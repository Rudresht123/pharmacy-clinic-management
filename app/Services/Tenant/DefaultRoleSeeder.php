<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Role;
use App\Services\Modules\ModuleAccess;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Roles\RoleTemplates;
use Illuminate\Support\Facades\DB;

/**
 * Give an organization the roles a clinic actually staffs.
 *
 * A service rather than a migration, and for a concrete reason: which roles
 * an organization should get depends on which MODULES it was sold, and that
 * lives in the master database. A migration cannot read it — Laravel's
 * migrator calls `setDefaultConnection('organization')` while it runs, which
 * mutates `database.default`, so any model without an explicit connection
 * resolves to the tenant's own database and `organizations` is not there.
 *
 * Both callers already hold the Organization, so neither has to discover it:
 * provisioning has just created it, and `tenants:migrate` is looping over
 * them. The connection is theirs to manage too — this is called with the
 * tenant already connected.
 *
 * IDEMPOTENT by name and slug. A tenant that already has a role called
 * "Doctor" — one somebody wrote by hand — keeps theirs untouched. This adds;
 * it never edits and never replaces.
 */
class DefaultRoleSeeder
{
    public function __construct(
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * @return list<string> the slugs actually created, for the caller to report
     */
    public function seed(Organization $organization): array
    {
        $modules = $this->modules->enabled($organization);
        $connection = TenantConnectionService::CONNECTION;
        $created = [];
        $now = now();

        foreach (RoleTemplates::all() as $template) {
            if (! RoleTemplates::appliesTo($template, $modules)) {
                continue;
            }

            $taken = Role::on($connection)
                ->where(function ($query) use ($template) {
                    $query->where('slug', $template['slug'])
                        ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($template['name'])]);
                })
                ->exists();

            if ($taken) {
                continue;
            }

            /*
             * One transaction per role rather than one for all of them. A
             * template that fails — a capability retired between releases,
             * say — should not take the other four with it.
             */
            DB::connection($connection)->transaction(function () use ($template, $modules, $now, $connection) {
                $role = Role::on($connection)->create([
                    'name' => $template['name'],
                    'slug' => $template['slug'],
                    'scope' => $template['scope'],
                    /*
                     * Null: written by the ORGANIZATION, so offered at every
                     * branch. A branch-scoped role with a null location is the
                     * normal case — `scope` says where it can be assigned,
                     * `location_id` says who wrote it.
                     */
                    'location_id' => null,
                    'description' => $template['description'],
                    'icon' => $template['icon'],
                ]);

                $role->forceFill(['created_at' => $now, 'updated_at' => $now])->saveQuietly();

                $role->syncCapabilities(RoleTemplates::capabilitiesFor($template, $modules));
            });

            $created[] = $template['slug'];
        }

        return $created;
    }
}
