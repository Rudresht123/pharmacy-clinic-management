<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-step provisioning trail (Central/Platform DB reference) — what makes
 * a retry resume instead of restart. Empty scaffolding: no historical
 * per-step data exists to backfill, since today's provisioning flow records
 * no steps at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_provision_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('step', 60);
            $table->string('status', 20);
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'step']);
        });

        DB::statement(
            'ALTER TABLE tenant_provision_events ADD CONSTRAINT tenant_provision_events_status_check '.
            "CHECK (status IN ('pending', 'in_progress', 'completed', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provision_events');
    }
};
