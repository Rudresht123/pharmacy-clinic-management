<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `tenant_key` — the immutable identifier the tenant database name is
 * derived from, replacing the old derivation off the mutable
 * `organization_name`.
 */
class TenantKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_key_check_constraint_rejects_uppercase(): void
    {
        $this->expectException(QueryException::class);

        DB::table('organizations')->insert(array_merge(
            Organization::factory()->make()->toArray(),
            ['tenant_key' => 'Uppercase']
        ));
    }

    public function test_tenant_key_check_constraint_rejects_spaces(): void
    {
        $this->expectException(QueryException::class);

        DB::table('organizations')->insert(array_merge(
            Organization::factory()->make()->toArray(),
            ['tenant_key' => 'has spaces']
        ));
    }

    public function test_generate_tenant_key_dedupes_on_collision(): void
    {
        Organization::factory()->create(['tenant_key' => 'city_pharmacy']);

        $this->assertSame('city_pharmacy_1', Organization::generateTenantKey('City Pharmacy'));
    }

    public function test_generate_database_name_uses_the_hms_tenant_prefix(): void
    {
        $this->assertSame('hms_tenant_acme', Organization::generateDatabaseName('acme'));
    }
}
