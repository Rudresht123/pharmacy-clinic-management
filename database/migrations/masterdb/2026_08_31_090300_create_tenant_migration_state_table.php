<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Current vs target schema version per tenant (Central/Platform DB
 * reference) — answers "who is behind?" without opening every tenant
 * database. Empty scaffolding: computing real versions means reading each
 * tenant's own migrations table, which this schema-only pass deliberately
 * does not do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_migration_state', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->unique()
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('current_version', 191)->nullable();
            $table->string('target_version', 191)->nullable();
            $table->string('status', 20)->default('in_sync');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE tenant_migration_state ADD CONSTRAINT tenant_migration_state_status_check '.
            "CHECK (status IN ('in_sync', 'behind', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_migration_state');
    }
};
