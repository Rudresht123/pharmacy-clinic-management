<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feature_flags` (global default) + `feature_flag_overrides` (per-org
 * override) — Central/Platform DB reference, "Operations" group. Two
 * related tables, one migration, same pattern as
 * `create_organization_history_tables.php`. Kept minimal — nothing reads or
 * writes these yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_enabled_globally')->default(false);
            $table->timestamps();
        });

        Schema::create('feature_flag_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feature_flag_id')->constrained('feature_flags')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->boolean('is_enabled');

            $table->timestamps();

            $table->unique(['feature_flag_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flag_overrides');
        Schema::dropIfExists('feature_flags');
    }
};
