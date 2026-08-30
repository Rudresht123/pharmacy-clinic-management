<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantMigrationStateCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_check_constraint_rejects_an_unknown_value(): void
    {
        $organization = Organization::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('tenant_migration_state')->insert([
            'organization_id' => $organization->id,
            'status' => 'not_a_real_status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
