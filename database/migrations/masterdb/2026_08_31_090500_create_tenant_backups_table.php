<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The backup register driving the ops dashboard, retention and restore
 * tooling (Central/Platform DB reference). Empty scaffolding; no backup
 * mechanism exists yet to have produced historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_backups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('status', 20);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_path', 255)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retained_until')->nullable();

            $table->timestamps();

            $table->index('organization_id');
        });

        DB::statement(
            'ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_status_check '.
            "CHECK (status IN ('pending', 'success', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
    }
};
