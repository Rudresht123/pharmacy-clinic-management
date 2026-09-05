<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\EnsureTenantCan;
use App\Http\Middleware\EnsureTenantUserIsOwner;
use App\Http\Middleware\ResolveTenantFromSession;
use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Locations — the first CRUD resource that lives inside a tenant database,
 * and the first place tenant authorization is enforced.
 */
class LocationTest extends TenantTestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main Street Pharmacy',
            'code' => 'MSP-01',
            'type' => Location::RETAIL_STORE,
            'is_active' => true,
            'city' => 'Pune',
            'phone' => '9999999999',
        ], $overrides);
    }

    /** Creates a location directly in the tenant database. */
    private function makeLocation(Organization $organization, array $attributes = []): Location
    {
        (new TenantConnectionService)->connect($organization->database_name);

        $location = Location::on(TenantConnectionService::CONNECTION)
            ->create($this->validPayload($attributes));

        (new TenantConnectionService)->disconnect();

        return $location;
    }

    public function test_an_owner_can_create_and_list_locations(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'MSP-01')
            ->assertJsonPath('data.type', Location::RETAIL_STORE);

        $this->getJson('/api/v1/tenant/locations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Main Street Pharmacy')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_an_owner_can_update_and_delete_a_location(): void
    {
        $organization = $this->provisionOrganization();
        $location = $this->makeLocation($organization);
        $this->signIn($organization, $organization->email);

        $this->putJson("/api/v1/tenant/locations/{$location->id}", $this->validPayload([
            'name' => 'Renamed Pharmacy',
        ]))->assertOk()->assertJsonPath('data.name', 'Renamed Pharmacy');

        $this->deleteJson("/api/v1/tenant/locations/{$location->id}")->assertOk();

        $this->getJson('/api/v1/tenant/locations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * The partial unique index and the validator have to agree: a plain
     * unique constraint would let validation pass and then fail at the
     * database with a raw 23505.
     */
    public function test_a_soft_deleted_locations_code_can_be_reused(): void
    {
        $organization = $this->provisionOrganization();
        $location = $this->makeLocation($organization);
        $this->signIn($organization, $organization->email);

        $this->deleteJson("/api/v1/tenant/locations/{$location->id}")->assertOk();

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'name' => 'Replacement Pharmacy',
        ]))->assertCreated()->assertJsonPath('data.code', 'MSP-01');
    }

    public function test_a_duplicate_code_is_rejected_while_the_location_is_live(): void
    {
        $organization = $this->provisionOrganization();
        $this->makeLocation($organization);
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_staff_may_read_locations(): void
    {
        $organization = $this->provisionOrganization();
        $location = $this->makeLocation($organization);
        $this->signIn($organization, self::STAFF_EMAIL);

        $this->getJson('/api/v1/tenant/locations')->assertOk();
        $this->getJson("/api/v1/tenant/locations/{$location->id}")->assertOk();
    }

    /**
     * Against a real id on purpose. EnsureTenantUserIsOwner sorts after
     * SubstituteBindings, so a made-up id would 404 before the owner check
     * ran and the test would pass for the wrong reason.
     */
    public function test_staff_may_not_write_locations(): void
    {
        $organization = $this->provisionOrganization();
        $location = $this->makeLocation($organization);
        $this->signIn($organization, self::STAFF_EMAIL);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload(['code' => 'OTHER-1']))
            ->assertStatus(403);

        $this->putJson("/api/v1/tenant/locations/{$location->id}", $this->validPayload())
            ->assertStatus(403);

        $this->deleteJson("/api/v1/tenant/locations/{$location->id}")
            ->assertStatus(403);
    }

    /**
     * CLINIC is selectable now that OPD gives it a use.
     *
     * This test used to assert the opposite — that validation withheld the
     * type while nothing depended on it. It is rewritten rather than deleted
     * so the change of behaviour is recorded rather than quietly disappearing
     * to make a new feature pass.
     */
    public function test_the_clinic_type_is_selectable(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'type' => Location::CLINIC,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.type', Location::CLINIC);
    }

    /** A type the schema does not know is still refused. */
    public function test_an_unknown_location_type_is_rejected(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'type' => 'DENTAL_LAB',
        ]))->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_licence_expiry_without_a_licence_number_is_rejected(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'drug_license_expiry_date' => '2030-01-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('drug_license_no');
    }

    /** An expired licence is a fact to record, not an input to refuse. */
    public function test_an_already_expired_licence_can_be_recorded(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'drug_license_no' => 'DL-123',
            'drug_license_expiry_date' => '2020-01-01',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.drug_license_expiry_date', '2020-01-01')
            ->assertJsonPath('data.has_expired_licence', true);
    }

    /**
     * A licence that runs out today has not run out yet.
     *
     * The check used to be isPast(), which compares against this instant:
     * a licence expiring today read as expired from midnight, so a pharmacy
     * was told it was trading illegally on the last day it was not.
     */
    public function test_a_licence_expiring_today_is_not_expired_yet(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'drug_license_no' => 'DL-TODAY',
            'drug_license_expiry_date' => now()->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.has_expired_licence', false);

        // Yesterday's, by contrast, is gone.
        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'code' => 'YDAY',
            'drug_license_no' => 'DL-YDAY',
            'drug_license_expiry_date' => now()->subDay()->toDateString(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.has_expired_licence', true);
    }

    /**
     * The branch form can create somebody who can run the branch.
     *
     * Three things in one write: a role owned by that branch, a person with
     * NOTHING in the organization slot, and a primary membership joining
     * them. Asserted individually, because two of the three succeeding is the
     * failure that leaves an email taken by a user nobody can see.
     */
    public function test_a_branch_can_be_created_with_an_admin_who_can_sign_in(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'admin' => [
                'name' => 'Priya Sharma',
                'email' => 'priya@branch.test',
                'password' => 'branch-secret-1',
            ],
        ]))->assertCreated();

        (new TenantConnectionService)->connect($organization->database_name);

        $branch = Location::on(TenantConnectionService::CONNECTION)
            ->where('code', 'MSP-01')->firstOrFail();

        $admin = User::on(TenantConnectionService::CONNECTION)
            ->where('email', 'priya@branch.test')->first();

        $this->assertNotNull($admin, 'The branch admin was not created.');
        $this->assertSame(User::STAFF, $admin->role);

        // Their authority is this branch's. On users.role_id it would be the
        // whole network's, which is the distinction the membership exists for.
        $this->assertNull($admin->role_id);

        $membership = BranchMembership::on(TenantConnectionService::CONNECTION)
            ->where('user_id', $admin->id)->first();

        $this->assertNotNull($membership, 'The admin was not posted to the branch.');
        $this->assertSame($branch->id, $membership->location_id);
        $this->assertTrue($membership->is_primary);

        $role = Role::on(TenantConnectionService::CONNECTION)
            ->with('capabilities')
            ->findOrFail($membership->role_id);

        // The branch's own, not the organization's — that is what lets the
        // branch change it without changing every other branch.
        $this->assertSame($branch->id, $role->location_id);
        $this->assertContains('people.roles', $role->capabilityKeys());

        (new TenantConnectionService)->disconnect();

        // The point of all of it: they can actually get in.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => 'priya@branch.test',
            'password' => 'branch-secret-1',
        ])->assertOk();
    }

    /**
     * The role holds only what the branch actually runs.
     *
     * A capability whose module is off would be a permission that means
     * nothing, and SaveRoleRequest would refuse it anyway — better to never
     * write it. Both halves are asserted in one test on purpose: "does not
     * contain appointments.book" passes just as well when the provisioner
     * grants nothing at all, so it is only worth anything beside a branch
     * where the same capability does appear.
     */
    public function test_the_branch_admin_role_holds_only_the_modules_that_are_on(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'code' => 'OFF-1',
            'admin' => [
                'name' => 'Off Admin',
                'email' => 'off@branch.test',
                'password' => 'branch-secret-1',
            ],
        ]))->assertCreated();

        $this->grantModule($organization, 'appointments');

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'code' => 'ON-1',
            'admin' => [
                'name' => 'On Admin',
                'email' => 'on@branch.test',
                'password' => 'branch-secret-1',
            ],
        ]))->assertCreated();

        [$off, $on] = $this->onTenant($organization, fn () => [
            $this->branchAdminRole('OFF-1'),
            $this->branchAdminRole('ON-1'),
        ]);

        $this->assertNotContains('appointments.book', $off);
        $this->assertContains('appointments.book', $on);

        // Both branches still get the core ones, so the first list is short
        // rather than empty — which is what makes the absence meaningful.
        $this->assertContains('people.roles', $off);
    }

    /**
     * What the branch admin at this code may do. Must run inside onTenant().
     *
     * @return list<string>
     */
    private function branchAdminRole(string $code): array
    {
        $locationId = Location::on(TenantConnectionService::CONNECTION)
            ->where('code', $code)->value('id');

        return Role::on(TenantConnectionService::CONNECTION)
            ->with('capabilities')
            ->where('location_id', $locationId)
            ->firstOrFail()
            ->capabilityKeys();
    }

    /** An email somebody already signs in with is a 422, not a 500. */
    public function test_a_branch_admin_email_that_is_already_in_use_is_rejected(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'admin' => [
                'name' => 'Clash',
                'email' => self::STAFF_EMAIL,
                'password' => 'branch-secret-1',
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('admin.email');

        // And nothing was written — the branch must not survive its admin.
        $this->getJson('/api/v1/tenant/locations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /** The admin section is optional; leaving it out creates nobody. */
    public function test_a_branch_can_still_be_created_without_an_admin(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'admin' => ['name' => '', 'email' => '', 'password' => ''],
        ]))->assertCreated();

        (new TenantConnectionService)->connect($organization->database_name);

        $this->assertSame(
            0,
            Role::on(TenantConnectionService::CONNECTION)->whereNotNull('location_id')->count()
        );

        (new TenantConnectionService)->disconnect();
    }

    /**
     * Declaration order on the route is not what runs — Laravel sorts
     * gathered middleware by priority, which is what disguised an earlier
     * bug where auth ran before the tenant database was selected.
     *
     * Was written against EnsureTenantUserIsOwner, which used to guard this
     * route; it is now EnsureTenantCan, and the trap is identical. Both are
     * kept out of the middleware priority list precisely so they sort after
     * the guard, and both would be handed a request with no signed-in user if
     * anybody ever listed them. Rewritten rather than deleted: a test that
     * only stops passing because the thing it protects moved must be pointed
     * at where it moved to.
     */
    public function test_the_permission_check_runs_after_the_guard_and_the_tenant_is_resolved_first(): void
    {
        $router = app('router');
        $checks = [
            'tenant.locations.store' => EnsureTenantCan::class,
            // Still owner-only: a branch that could switch its own modules on
            // could re-open a door the owner closed.
            'tenant.locations.modules.update' => EnsureTenantUserIsOwner::class,
        ];

        foreach ($checks as $routeName => $permissionMiddleware) {
            $route = $router->getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "The {$routeName} route is missing.");

            $middleware = array_values(array_filter(
                $router->gatherRouteMiddleware($route),
                'is_string'
            ));

            $tenant = null;
            $guard = null;
            $permission = null;

            foreach ($middleware as $position => $name) {
                if (str_starts_with($name, ResolveTenantFromSession::class)) {
                    $tenant ??= $position;
                }

                if (str_starts_with($name, Authenticate::class)) {
                    $guard ??= $position;
                }

                if (str_starts_with($name, $permissionMiddleware)) {
                    $permission ??= $position;
                }
            }

            $order = implode(' -> ', $middleware);

            $this->assertNotNull($tenant, "No tenant resolution on {$routeName}: {$order}");
            $this->assertNotNull($guard, "No guard on {$routeName}: {$order}");
            $this->assertNotNull($permission, "No permission check on {$routeName}: {$order}");

            $this->assertLessThan($guard, $tenant, "Guard runs before the tenant database: {$order}");
            $this->assertLessThan($permission, $guard, "Permission check runs before the guard: {$order}");
        }
    }
}
