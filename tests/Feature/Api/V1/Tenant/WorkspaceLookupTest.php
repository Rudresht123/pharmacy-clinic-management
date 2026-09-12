<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Platform\OrganizationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lookup a phone does before it can address a tenant at all.
 *
 * BrandingTest covers the browser's route to the same answer, off the Host
 * header. This covers the app's: by code, from anywhere.
 */
class WorkspaceLookupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService)->disconnect();
            DatabaseService::drop($databaseName);
        }

        parent::tearDown();
    }

    private function provisionOrganization(string $code): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Clinic '.uniqid(),
            'slug' => 'clinic-'.uniqid(),
            'is_active' => true,
        ]);

        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $unique = uniqid();

        $created = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => 'Workspace Test '.$unique,
                'organization_code' => $code,
                'organization_type_id' => $type->id,
                'subdomain' => 'workspace-'.$unique,
                'email' => "owner-{$unique}@example.com",
            ])->json('data');

        $organization = Organization::where('uuid', $created['uuid'])->firstOrFail();
        $this->createdDatabases[] = $organization->database_name;

        return $organization;
    }

    /** An organization that is ready to be signed in to hands back its identity. */
    public function test_an_active_workspace_is_found_by_its_code(): void
    {
        $code = OrganizationCode::generate();
        $organization = $this->provisionOrganization($code);

        $organization->forceFill([
            'is_active' => true,
            'is_setup_completed' => true,
            'status' => Organization::ACTIVE,
        ])->save();

        $this->getJson("/api/v1/tenant/workspace/{$code}")
            ->assertOk()
            ->assertJsonPath('data.name', $organization->organization_name)
            ->assertJsonPath('data.subdomain', $organization->subdomain)
            ->assertJsonPath('data.code', $code);
    }

    /**
     * The app is the only client, and a phone keyboard capitalises the first
     * letter by itself. Rejecting that would be a puzzle with no clue attached.
     */
    public function test_the_code_is_matched_regardless_of_case(): void
    {
        $code = mb_strtolower(OrganizationCode::generate());
        $organization = $this->provisionOrganization($code);

        $organization->forceFill([
            'is_active' => true,
            'is_setup_completed' => true,
            'status' => Organization::ACTIVE,
        ])->save();

        $this->getJson('/api/v1/tenant/workspace/'.mb_strtoupper($code))
            ->assertOk()
            ->assertJsonPath('data.subdomain', $organization->subdomain);
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->getJson('/api/v1/tenant/workspace/'.OrganizationCode::generate())
            ->assertStatus(404);
    }

    /**
     * Half-provisioned and suspended organizations answer exactly as a
     * non-existent one does. They are different to us and identical to the
     * person typing -- in every case there is nothing here to sign in to -- and
     * separating them would hand an outsider a census of organizations that
     * exist but are switched off.
     */
    public function test_a_workspace_that_cannot_be_signed_in_to_is_indistinguishable_from_a_missing_one(): void
    {
        $code = OrganizationCode::generate();
        $organization = $this->provisionOrganization($code);

        // Still being set up.
        $organization->forceFill([
            'is_setup_completed' => false,
            'status' => Organization::ACTIVE,
        ])->save();

        $this->getJson("/api/v1/tenant/workspace/{$code}")->assertStatus(404);

        // Set up, but suspended. Everything else stays passing, so a 404 here
        // can only be the suspension -- otherwise this case proves nothing.
        $organization->forceFill([
            'is_active' => true,
            'is_setup_completed' => true,
            'status' => Organization::SUSPENDED,
        ])->save();

        $this->getJson("/api/v1/tenant/workspace/{$code}")->assertStatus(404);

        // Set up and active in status, but switched off.
        $organization->forceFill([
            'is_active' => false,
            'is_setup_completed' => true,
            'status' => Organization::ACTIVE,
        ])->save();

        $this->getJson("/api/v1/tenant/workspace/{$code}")->assertStatus(404);
    }

    /**
     * The response is what the app is allowed to know before anyone has signed
     * in. `database_name` in particular must never appear here.
     */
    public function test_the_response_carries_nothing_operational(): void
    {
        $code = OrganizationCode::generate();
        $organization = $this->provisionOrganization($code);

        $organization->forceFill([
            'is_active' => true,
            'is_setup_completed' => true,
            'status' => Organization::ACTIVE,
        ])->save();

        $body = $this->getJson("/api/v1/tenant/workspace/{$code}")->assertOk()->json('data');

        // Asserted as an exact list on purpose. A new field added to the
        // resource is a new field published to anyone holding a code, so this
        // test is meant to fail until somebody has decided that is alright.
        $this->assertSame(
            ['name', 'code', 'subdomain', 'logo_url', 'has_logo', 'type', 'address'],
            array_keys($body)
        );

        $this->assertStringNotContainsString($organization->database_name, json_encode($body));
    }
}
