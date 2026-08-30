<?php

namespace Tests\Feature\Platform;

use App\Repositories\Platform\Contracts\FeatureFlagRepositoryInterface;
use App\Repositories\Platform\Contracts\ModuleRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationDomainRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationProfileRepositoryInterface;
use App\Repositories\Platform\Contracts\PlatformSettingRepositoryInterface;
use App\Repositories\Platform\FeatureFlagRepository;
use App\Repositories\Platform\ModuleRepository;
use App\Repositories\Platform\OrganizationDomainRepository;
use App\Repositories\Platform\OrganizationProfileRepository;
use App\Repositories\Platform\PlatformSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The remaining Phase 1 tables — nothing consumes them yet, so these just
 * prove the table and its repository binding exist, not any behavior.
 */
class Phase1ScaffoldingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{0: string, 1: class-string, 2: class-string}> */
    public static function tables(): array
    {
        return [
            'organization_profiles' => ['organization_profiles', OrganizationProfileRepositoryInterface::class, OrganizationProfileRepository::class],
            'organization_domains' => ['organization_domains', OrganizationDomainRepositoryInterface::class, OrganizationDomainRepository::class],
            'platform_settings' => ['platform_settings', PlatformSettingRepositoryInterface::class, PlatformSettingRepository::class],
            'feature_flags' => ['feature_flags', FeatureFlagRepositoryInterface::class, FeatureFlagRepository::class],
            'feature_flag_overrides' => ['feature_flag_overrides', null, null],
            'modules' => ['modules', ModuleRepositoryInterface::class, ModuleRepository::class],
        ];
    }

    #[DataProvider('tables')]
    public function test_table_and_repository_exist(string $table, ?string $interface, ?string $concrete): void
    {
        $this->assertTrue(Schema::hasTable($table), "Table [{$table}] does not exist.");

        if ($interface !== null) {
            $this->assertInstanceOf($concrete, app($interface));
        }
    }
}
