<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\DbCluster;
use App\Models\Platform\Organization;
use App\Models\Platform\TenantDatabase;
use App\Repositories\Platform\Contracts\TenantDatabaseRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `RefreshDatabase` re-runs every migration fresh with an empty
 * `organizations` table, so the migration's own backfill loop never has
 * anything to backfill inside a test — there is nothing to integration-test
 * there. What's actually worth testing is the mapping logic the backfill
 * (and the eventual provisioning rewrite) both rely on.
 */
class TenantDatabaseBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    public static function statusMappings(): array
    {
        return [
            'active' => [Organization::ACTIVE, 'provisioned', 'healthy'],
            'suspended' => [Organization::SUSPENDED, 'provisioned', 'healthy'],
            'cancelled' => [Organization::CANCELLED, 'provisioned', 'healthy'],
            'failed' => [Organization::FAILED, 'failed', null],
            'provisioning' => [Organization::PROVISIONING, 'provisioning', null],
            'pending' => [Organization::PENDING, 'pending', null],
        ];
    }

    #[DataProvider('statusMappings')]
    public function test_organization_status_maps_to_the_expected_technical_state(
        string $organizationStatus,
        string $expectedProvisionStatus,
        ?string $expectedStatus
    ): void {
        $mapped = TenantDatabase::mapOrganizationStatus($organizationStatus);

        $this->assertSame($expectedProvisionStatus, $mapped['provision_status']);
        $this->assertSame($expectedStatus, $mapped['status']);
    }

    public function test_a_tenant_database_row_can_be_created_from_the_mapping(): void
    {
        $organization = Organization::factory()->active()->create();
        $cluster = DbCluster::where('is_default', true)->firstOrFail();
        $mapped = TenantDatabase::mapOrganizationStatus($organization->status);

        $tenantDatabase = app(TenantDatabaseRepositoryInterface::class)->create([
            'organization_id' => $organization->id,
            'db_cluster_id' => $cluster->id,
            'db_name' => $organization->database_name,
            'db_user' => config('database.connections.pgsql.username'),
            'provision_status' => $mapped['provision_status'],
            'status' => $mapped['status'],
        ]);

        $this->assertSame($cluster->id, $tenantDatabase->db_cluster_id);
        $this->assertSame($organization->database_name, $tenantDatabase->db_name);
        $this->assertSame('provisioned', $tenantDatabase->provision_status);
        $this->assertSame('healthy', $tenantDatabase->status);
    }

    public function test_provision_status_check_constraint_rejects_an_unknown_value(): void
    {
        $organization = Organization::factory()->create();
        $cluster = DbCluster::where('is_default', true)->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('tenant_databases')->insert([
            'organization_id' => $organization->id,
            'db_cluster_id' => $cluster->id,
            'db_name' => $organization->database_name,
            'db_user' => 'postgres',
            'provision_status' => 'not_a_real_status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
