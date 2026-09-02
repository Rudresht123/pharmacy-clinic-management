<?php

namespace App\Providers;

use App\Repositories\Platform\Contracts\DbClusterRepositoryInterface;
use App\Repositories\Platform\Contracts\FeatureFlagRepositoryInterface;
use App\Repositories\Platform\Contracts\ModuleRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationDomainRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationProfileRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationStatRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationTypeRepositoryInterface;
use App\Repositories\Platform\Contracts\PlatformAuditLogRepositoryInterface;
use App\Repositories\Platform\Contracts\PlatformSettingRepositoryInterface;
use App\Repositories\Platform\Contracts\PlatformUserRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantBackupRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantDatabaseRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantMigrationRunRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantMigrationStateRepositoryInterface;
use App\Repositories\Platform\Contracts\TenantProvisionEventRepositoryInterface;
use App\Repositories\Platform\DbClusterRepository;
use App\Repositories\Platform\FeatureFlagRepository;
use App\Repositories\Platform\ModuleRepository;
use App\Repositories\Platform\OrganizationDomainRepository;
use App\Repositories\Platform\OrganizationProfileRepository;
use App\Repositories\Platform\OrganizationRepository;
use App\Repositories\Platform\OrganizationStatRepository;
use App\Repositories\Platform\OrganizationTypeRepository;
use App\Repositories\Platform\PlatformAuditLogRepository;
use App\Repositories\Platform\PlatformSettingRepository;
use App\Repositories\Platform\PlatformUserRepository;
use App\Repositories\Platform\TenantBackupRepository;
use App\Repositories\Platform\TenantDatabaseRepository;
use App\Repositories\Platform\TenantMigrationRunRepository;
use App\Repositories\Platform\TenantMigrationStateRepository;
use App\Repositories\Platform\TenantProvisionEventRepository;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Repositories\Tenant\Contracts\LocationRepositoryInterface;
use App\Repositories\Tenant\Contracts\TenantUserRepositoryInterface;
use App\Repositories\Tenant\CustomerRepository;
use App\Repositories\Tenant\LocationRepository;
use App\Repositories\Tenant\TenantUserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires each repository interface to the class that implements it.
 *
 * This is the only file that knows which implementation is in use, so
 * swapping one — for a cached variant, or a fake in a test — is a one-line
 * change here rather than an edit in every controller.
 *
 * Add new bindings to the map below; nothing else needs to change.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Interface => implementation.
     *
     * @var array<class-string, class-string>
     */
    private const BINDINGS = [
        PlatformUserRepositoryInterface::class => PlatformUserRepository::class,
        OrganizationRepositoryInterface::class => OrganizationRepository::class,
        OrganizationTypeRepositoryInterface::class => OrganizationTypeRepository::class,
        OrganizationProfileRepositoryInterface::class => OrganizationProfileRepository::class,
        OrganizationDomainRepositoryInterface::class => OrganizationDomainRepository::class,
        PlatformAuditLogRepositoryInterface::class => PlatformAuditLogRepository::class,
        PlatformSettingRepositoryInterface::class => PlatformSettingRepository::class,
        FeatureFlagRepositoryInterface::class => FeatureFlagRepository::class,
        ModuleRepositoryInterface::class => ModuleRepository::class,
        DbClusterRepositoryInterface::class => DbClusterRepository::class,
        TenantDatabaseRepositoryInterface::class => TenantDatabaseRepository::class,
        TenantProvisionEventRepositoryInterface::class => TenantProvisionEventRepository::class,
        TenantMigrationStateRepositoryInterface::class => TenantMigrationStateRepository::class,
        TenantMigrationRunRepositoryInterface::class => TenantMigrationRunRepository::class,
        TenantBackupRepositoryInterface::class => TenantBackupRepository::class,
        OrganizationStatRepositoryInterface::class => OrganizationStatRepository::class,

        // Tenant-database repositories. These resolve against whichever
        // tenant ResolveTenantFromSession selected for the current request.
        LocationRepositoryInterface::class => LocationRepository::class,
        CustomerRepositoryInterface::class => CustomerRepository::class,
        TenantUserRepositoryInterface::class => TenantUserRepository::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return array_keys(self::BINDINGS);
    }
}
