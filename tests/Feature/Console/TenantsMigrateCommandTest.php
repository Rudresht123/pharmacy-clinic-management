<?php

namespace Tests\Feature\Console;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Services\Platform\OrganizationProvisioningService;
use App\Services\Tenancy\DatabaseService;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Platform\OrganizationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real Postgres, same as OrganizationProvisioningTest — the tenant
 * connection isn't wrapped by RefreshDatabase's per-test transaction (only
 * the default `pgsql` connection is), so migrating a real tenant database
 * inside a test needs no special handling.
 */
class TenantsMigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->createdDatabases as $databaseName) {
            (new TenantConnectionService)->disconnect();

            if (DatabaseService::exists($databaseName)) {
                DatabaseService::drop($databaseName);
            }
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

        $unique = uniqid();
        $name = 'Migrate Test '.$unique;
        $tenantKey = Organization::generateTenantKey($name);

        $organization = app(OrganizationProvisioningService::class)->provision([
            'organization_name' => $name,
            'organization_code' => OrganizationCode::generate(),
            'organization_type_id' => $type->id,
            'subdomain' => 'migrate-test-'.$unique,
            'email' => "owner-{$unique}@example.com",
            'slug' => Organization::generateSlug($name),
            'tenant_key' => $tenantKey,
            'database_name' => Organization::generateDatabaseName($tenantKey),
        ]);

        $this->createdDatabases[] = $organization->database_name;

        return $organization;
    }

    public function test_org_scoped_migration_reports_in_sync(): void
    {
        $organization = $this->provisionOrganization();

        $this->artisan('tenants:migrate', ['--org' => $organization->uuid, '--force' => true])
            ->assertExitCode(0);

        $state = $organization->migrationState()->sole();

        $this->assertSame('in_sync', $state->status);
        $this->assertSame($state->current_version, $state->target_version);
        $this->assertSame(1, $state->attempts);

        $run = $organization->migrationRuns()->sole();
        $this->assertSame('success', $run->status);
    }

    public function test_pretend_does_not_record_a_run_or_increment_attempts(): void
    {
        $organization = $this->provisionOrganization();

        $this->artisan('tenants:migrate', [
            '--org' => $organization->uuid, '--pretend' => true, '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, $organization->migrationRuns()->count());

        $state = $organization->migrationState()->sole();
        $this->assertSame(0, $state->attempts);
        $this->assertSame('in_sync', $state->status);
    }

    /**
     * A dry run is dry for the master database too.
     *
     * The master paths were once migrated without --pretend, so a preview
     * applied every pending master migration for real.
     */
    public function test_pretend_leaves_pending_master_migrations_pending(): void
    {
        $pending = '2026_09_20_000100_shift_timestamps_to_asia_kolkata';

        // Make one master migration pending again, as on a server behind.
        DB::table('migrations')->where('migration', $pending)->delete();

        $this->artisan('tenants:migrate', ['--pretend' => true, '--force' => true])->assertExitCode(0);

        $this->assertFalse(DB::table('migrations')->where('migration', $pending)->exists());
    }

    public function test_unknown_org_uuid_fails_cleanly(): void
    {
        $this->artisan('tenants:migrate', ['--org' => (string) Str::uuid(), '--force' => true])
            ->assertExitCode(1);
    }

    public function test_a_dropped_tenant_database_is_recorded_as_a_failure(): void
    {
        $organization = $this->provisionOrganization();

        (new TenantConnectionService)->disconnect();
        DatabaseService::drop($organization->database_name);
        $this->createdDatabases = array_diff($this->createdDatabases, [$organization->database_name]);

        $this->artisan('tenants:migrate', ['--org' => $organization->uuid, '--force' => true])
            ->assertExitCode(1);

        $run = $organization->migrationRuns()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($run->error);

        $state = $organization->migrationState()->sole();
        $this->assertSame('failed', $state->status);
    }

    public function test_all_organizations_share_one_batch_uuid(): void
    {
        $first = $this->provisionOrganization();
        $second = $this->provisionOrganization();

        $this->artisan('tenants:migrate', ['--force' => true])->assertExitCode(0);

        $firstRun = $first->migrationRuns()->sole();
        $secondRun = $second->migrationRuns()->sole();

        $this->assertSame('success', $firstRun->status);
        $this->assertSame('success', $secondRun->status);
        $this->assertSame($firstRun->batch_uuid, $secondRun->batch_uuid);
    }
}
