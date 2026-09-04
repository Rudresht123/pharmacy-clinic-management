<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\EmailTemplate;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The first tests to actually exercise provision() — deferred until now per
 * OrganizationTest's own docblock ("belongs with the idempotency work in
 * step 4"). Runs against real Postgres (already required by phpunit.xml)
 * and issues real CREATE DATABASE calls, so every test drops whatever
 * tenant database it caused to be created, pass or fail.
 */
class OrganizationProvisioningTest extends TestCase
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

    private function actingAsAdmin(): self
    {
        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();

        return $this->actingAs($admin, 'platform');
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy '.uniqid(),
            'slug' => 'pharmacy-'.uniqid(),
            'is_active' => true,
        ]);

        $unique = uniqid();

        return [
            'organization_name' => 'Provision Test '.$unique,
            'organization_code' => 'PT'.$unique,
            'organization_type_id' => $type->id,
            'subdomain' => 'provision-test-'.$unique,
            'email' => "owner-{$unique}@example.com",
        ];
    }

    public function test_a_successful_provision_creates_the_tenant_database_and_reaches_active(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/v1/admin/organizations', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', Organization::ACTIVE);

        $organization = Organization::sole();
        $this->createdDatabases[] = $organization->database_name;

        $this->assertTrue(DatabaseService::exists($organization->database_name));

        (new TenantConnectionService)->connect($organization->database_name);
        $this->assertTrue(Schema::connection('organization')->hasTable('users'));

        $tenantDatabase = $organization->tenantDatabase;
        $this->assertNotNull($tenantDatabase);
        $this->assertSame('provisioned', $tenantDatabase->provision_status);
        $this->assertSame('healthy', $tenantDatabase->status);

        $completedSteps = $organization->provisionEvents()
            ->where('status', 'completed')
            ->pluck('step')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['create_database', 'migrate_tenant', 'send_setup_email'], $completedSteps);
    }

    public function test_a_failed_step_leaves_the_organization_and_database_intact_for_retry(): void
    {
        EmailTemplate::where('template_key', 'organization_setup')->update(['is_active' => false]);

        $this->actingAsAdmin()
            ->postJson('/api/v1/admin/organizations', $this->validPayload())
            ->assertStatus(500);

        $organization = Organization::sole();
        $this->createdDatabases[] = $organization->database_name;

        $this->assertSame(Organization::FAILED, $organization->status);
        $this->assertTrue(DatabaseService::exists($organization->database_name));

        $this->assertSame('failed', $organization->tenantDatabase->provision_status);

        $this->assertDatabaseHas('tenant_provision_events', [
            'organization_id' => $organization->id,
            'step' => 'create_database',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('tenant_provision_events', [
            'organization_id' => $organization->id,
            'step' => 'migrate_tenant',
            'status' => 'completed',
        ]);

        $failedEvent = $organization->provisionEvents()
            ->where('step', 'send_setup_email')
            ->where('status', 'failed')
            ->sole();

        $this->assertNotEmpty($failedEvent->error);
    }

    public function test_retrying_resumes_instead_of_restarting(): void
    {
        EmailTemplate::where('template_key', 'organization_setup')->update(['is_active' => false]);

        $client = $this->actingAsAdmin();

        $client->postJson('/api/v1/admin/organizations', $this->validPayload())
            ->assertStatus(500);

        $organization = Organization::sole();
        $this->createdDatabases[] = $organization->database_name;

        EmailTemplate::where('template_key', 'organization_setup')->update(['is_active' => true]);

        $client->postJson("/api/v1/admin/organizations/{$organization->uuid}/retry-provisioning")
            ->assertOk()
            ->assertJsonPath('data.status', Organization::ACTIVE);

        $this->assertSame(
            1,
            $organization->provisionEvents()->where('step', 'create_database')->count(),
            'create_database should not have been re-run on retry.'
        );
        $this->assertSame(
            1,
            $organization->provisionEvents()->where('step', 'migrate_tenant')->count(),
            'migrate_tenant should not have been re-run on retry.'
        );
        $this->assertSame(
            2,
            $organization->provisionEvents()->where('step', 'send_setup_email')->count(),
            'send_setup_email should have one failed attempt and one successful retry.'
        );
    }

    public function test_retry_rejects_an_already_active_organization(): void
    {
        $organization = Organization::factory()->active()->create();

        $this->actingAsAdmin()
            ->postJson("/api/v1/admin/organizations/{$organization->uuid}/retry-provisioning")
            ->assertStatus(500);
    }

    public function test_a_soft_deleted_organizations_subdomain_and_code_can_be_reused(): void
    {
        $deleted = Organization::factory()->create([
            'subdomain' => 'reclaimed-subdomain',
            'organization_code' => 'RECLAIMED',
        ]);
        $deleted->delete();

        $payload = array_merge($this->validPayload(), [
            'subdomain' => 'reclaimed-subdomain',
            'organization_code' => 'RECLAIMED',
        ]);

        $this->actingAsAdmin()
            ->postJson('/api/v1/admin/organizations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subdomain', 'reclaimed-subdomain');

        $organization = Organization::where('subdomain', 'reclaimed-subdomain')->sole();
        $this->createdDatabases[] = $organization->database_name;
    }

    public function test_database_service_exists_reflects_reality(): void
    {
        $name = 'hms_tenant_exists_check_'.uniqid();

        $this->assertFalse(DatabaseService::exists($name));

        DatabaseService::create($name);
        $this->createdDatabases[] = $name;

        $this->assertTrue(DatabaseService::exists($name));
    }
}
