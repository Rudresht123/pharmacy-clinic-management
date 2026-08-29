<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build Spec §4 — the two remaining tenant-side tables.
 *
 * organization_status_history is what the Overview and Audit tabs read to
 * answer "when did this become suspended, and who did it" without scanning
 * the platform audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            // Null on the very first row — the organization came from nowhere.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            $table->string('reason', 255)->nullable();

            // Null when a scheduled command moved it rather than a person.
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('platform_users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('organization_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('role', 60)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();

            /*
             * The one contact we actually reach out to. Not a database
             * constraint — PostgreSQL has no "at most one true per group"
             * without a partial unique index, and the repository is a
             * clearer place to enforce it than a constraint whose violation
             * message means nothing to the user.
             */
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index(['organization_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_contacts');
        Schema::dropIfExists('organization_status_history');
    }
};
