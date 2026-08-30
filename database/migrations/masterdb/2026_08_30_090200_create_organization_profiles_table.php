<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured profile detail, split out to keep the hot `organizations` row
 * narrow (Central/Platform DB reference, table guide). Scaffolding only —
 * nothing writes to this table yet; provisioning still writes address/logo
 * onto `organizations` directly. Cutting over is deferred to the pass that
 * also rewrites provisioning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->unique()
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->text('description')->nullable();
            $table->string('website_url', 255)->nullable();

            $table->unsignedBigInteger('logo_file_id')->nullable();
            $table->foreign('logo_file_id')->references('id')->on('files')->nullOnDelete();

            $table->string('support_email', 191)->nullable();
            $table->string('support_phone', 20)->nullable();

            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_profiles');
    }
};
