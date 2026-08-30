<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\DbCluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbClusterSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_one_default_cluster_is_seeded(): void
    {
        $this->assertSame(1, DbCluster::count());

        $cluster = DbCluster::first();

        $this->assertTrue($cluster->is_default);
        $this->assertSame(config('database.connections.pgsql.host'), $cluster->host);
        $this->assertSame((int) config('database.connections.pgsql.port'), $cluster->port);
    }
}
