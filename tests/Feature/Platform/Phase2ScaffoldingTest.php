<?php

namespace Tests\Feature\Platform;

use App\Repositories\Platform\Contracts\DbClusterRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationStatRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantBackupRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantDatabaseRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantMigrationRunRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantMigrationStateRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantProvisionEventRepositoryInterface;
use App\Repositories\Platform\DbClusterRepository;
use App\Repositories\Platform\OrganizationStatRepository;
use App\Repositories\Platform\TenantBackupRepository;
use App\Repositories\Platform\TenantDatabaseRepository;
use App\Repositories\Platform\TenantMigrationRunRepository;
use App\Repositories\Platform\TenantMigrationStateRepository;
use App\Repositories\Platform\TenantProvisionEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Phase 2 tenant/db-ops tables — nothing consumes them yet beyond the
 * one-time backfill (see TenantDatabaseBackfillTest), so these just prove
 * the table and its repository binding exist, not any behavior.
 */
class Phase2ScaffoldingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: class-string, 2: class-string}> */
    public static function tables(): array
    {
        return [
            'db_clusters' => ['db_clusters', DbClusterRepositoryInterface::class, DbClusterRepository::class],
            'tenant_databases' => ['tenant_databases', TenantDatabaseRepositoryInterface::class, TenantDatabaseRepository::class],
            'tenant_provision_events' => ['tenant_provision_events', TenantProvisionEventRepositoryInterface::class, TenantProvisionEventRepository::class],
            'tenant_migration_state' => ['tenant_migration_state', TenantMigrationStateRepositoryInterface::class, TenantMigrationStateRepository::class],
            'tenant_migration_runs' => ['tenant_migration_runs', TenantMigrationRunRepositoryInterface::class, TenantMigrationRunRepository::class],
            'tenant_backups' => ['tenant_backups', TenantBackupRepositoryInterface::class, TenantBackupRepository::class],
            'organization_stats' => ['organization_stats', OrganizationStatRepositoryInterface::class, OrganizationStatRepository::class],
        ];
    }

    #[DataProvider('tables')]
    public function test_table_and_repository_exist(string $table, string $interface, string $concrete): void
    {
        $this->assertTrue(Schema::hasTable($table), "Table [{$table}] does not exist.");
        $this->assertInstanceOf($concrete, app($interface));
    }
}
