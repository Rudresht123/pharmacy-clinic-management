<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Http\Middleware\EnsureTenantUserIsOwner;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Tenant\Location;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
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
        (new TenantConnectionService())->connect($organization->database_name);

        $location = Location::on(TenantConnectionService::CONNECTION)
            ->create($this->validPayload($attributes));

        (new TenantConnectionService())->disconnect();

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

    /** The schema accepts CLINIC; validation withholds it until that module exists. */
    public function test_the_reserved_clinic_type_is_not_selectable(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/locations', $this->validPayload([
            'type' => Location::CLINIC,
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
     * Declaration order on the route is not what runs — Laravel sorts
     * gathered middleware by priority, which is what disguised an earlier
     * bug where auth ran before the tenant database was selected.
     */
    public function test_the_owner_check_runs_after_the_guard_and_the_tenant_is_resolved_first(): void
    {
        $router = app('router');
        $route = $router->getRoutes()->getByName('tenant.locations.store');

        $this->assertNotNull($route, 'The locations.store route is missing.');

        $middleware = array_values(array_filter(
            $router->gatherRouteMiddleware($route),
            'is_string'
        ));

        $tenant = null;
        $guard = null;
        $owner = null;

        foreach ($middleware as $position => $name) {
            if (str_starts_with($name, \App\Http\Middleware\ResolveTenantFromSession::class)) {
                $tenant ??= $position;
            }

            if (str_starts_with($name, \Illuminate\Auth\Middleware\Authenticate::class)) {
                $guard ??= $position;
            }

            if (str_starts_with($name, EnsureTenantUserIsOwner::class)) {
                $owner ??= $position;
            }
        }

        $order = implode(' -> ', $middleware);

        $this->assertNotNull($tenant, "No tenant resolution on locations.store: {$order}");
        $this->assertNotNull($guard, "No guard on locations.store: {$order}");
        $this->assertNotNull($owner, "No owner check on locations.store: {$order}");

        $this->assertLessThan($guard, $tenant, "Guard runs before the tenant database: {$order}");
        $this->assertLessThan($owner, $guard, "Owner check runs before the guard: {$order}");
    }
}
