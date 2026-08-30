<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregate counters only (patients: 4,213) — never a name, phone or
 * diagnosis (Central/Platform DB reference). One row per organization+
 * metric so a new counter needs no schema change. Populated later by the
 * tenant outbox relay, never by a direct cross-database query — empty
 * scaffolding for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_stats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('metric_key', 100);
            $table->bigInteger('metric_value')->default(0);
            $table->timestamp('recorded_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_stats');
    }
};
