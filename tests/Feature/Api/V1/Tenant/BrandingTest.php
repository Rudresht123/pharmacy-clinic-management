<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one endpoint a tenant login screen can call before anyone has signed
 * in — resolved from the Host header alone, same as LoginRequest.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService())->disconnect();
            DatabaseService::drop($databaseName);
        }

        parent::tearDown();
    }

    private function provisionOrganization(): Organization
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy '.uniqid(),
            'slug' => 'pharmacy-'.uniqid(),
            'is_active' => true,
        ]);

        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
        $unique = uniqid();

        $organization = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/organizations', [
                'organization_name' => 'Branding Test '.$unique,
                'organization_code' => 'BR'.$unique,
                'organization_type_id' => $type->id,
                'subdomain' => 'branding-'.$unique,
                'email' => "owner-{$unique}@example.com",
            ])->json('data');

        $organization = Organization::where('uuid', $organization['uuid'])->firstOrFail();
        $this->createdDatabases[] = $organization->database_name;

        return $organization;
    }

    public function test_branding_resolves_from_the_host_header(): void
    {
        $organization = $this->provisionOrganization();

        $this->getJson("http://{$organization->subdomain}.hms.local/api/v1/tenant/branding")
            ->assertOk()
            ->assertJsonPath('data.name', $organization->organization_name)
            ->assertJsonPath('data.subdomain', $organization->subdomain)
            ->assertJsonPath('data.has_logo', false);
    }

    public function test_an_unknown_subdomain_returns_not_found(): void
    {
        $this->getJson('http://no-such-clinic-'.uniqid().'.hms.local/api/v1/tenant/branding')
            ->assertStatus(404);
    }

    public function test_a_host_outside_the_main_domain_returns_not_found(): void
    {
        $this->getJson('/api/v1/tenant/branding')->assertStatus(404);
    }
}
