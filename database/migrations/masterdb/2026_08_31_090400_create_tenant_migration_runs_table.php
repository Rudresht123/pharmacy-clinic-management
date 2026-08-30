<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per migration attempt per tenant, grouped by `batch_uuid`
 * (Central/Platform DB reference) — all tenants migrated in the same run
 * share a batch. Empty scaffolding; no historical attempt data exists to
 * backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_migration_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('batch_uuid', 36);
            $table->string('status', 20);
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'batch_uuid']);
        });

        DB::statement(
            'ALTER TABLE tenant_migration_runs ADD CONSTRAINT tenant_migration_runs_status_check '.
            "CHECK (status IN ('success', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_migration_runs');
    }
};
