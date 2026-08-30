<?php

namespace Tests\Feature\Platform;

use App\Repositories\Platform\Contracts\PlatformAuditLogRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * `platform_audit_logs` is append-only — enforced by a `BEFORE UPDATE OR
 * DELETE` trigger, not by application convention (Central/Platform DB
 * reference, decision #6: "convention is not a control").
 */
class PlatformAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function aRowId(): int
    {
        return DB::table('platform_audit_logs')->insertGetId([
            'action' => 'organization.created',
            'entity_type' => 'organization',
            'entity_id' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_the_append_only_trigger_blocks_update(): void
    {
        $id = $this->aRowId();

        $this->expectException(QueryException::class);

        DB::table('platform_audit_logs')->where('id', $id)->update(['action' => 'tampered']);
    }

    public function test_the_append_only_trigger_blocks_delete(): void
    {
        $id = $this->aRowId();

        $this->expectException(QueryException::class);

        DB::table('platform_audit_logs')->where('id', $id)->delete();
    }

    public function test_the_repository_fails_fast_on_update_or_delete(): void
    {
        $model = app(PlatformAuditLogRepositoryInterface::class)->create([
            'action' => 'organization.created',
            'entity_type' => 'organization',
            'entity_id' => 1,
        ]);

        $this->expectException(LogicException::class);

        app(PlatformAuditLogRepositoryInterface::class)->update($model, ['action' => 'tampered']);
    }
}
