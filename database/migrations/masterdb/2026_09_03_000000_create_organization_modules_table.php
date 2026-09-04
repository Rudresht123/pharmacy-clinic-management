<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which modules an organization is entitled to.
 *
 * The catalogue (`modules`) says what exists; this says who has what. It
 * lives in the master database because an entitlement is a platform and
 * billing fact — the tenant database must not be able to grant itself a
 * module it has not been sold.
 *
 * Core modules are deliberately NOT rows here. Every organization has them
 * by virtue of being an organization, and writing four rows per tenant to
 * say so would invite somebody to delete one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            // Mirrors ModuleRegistry, which stays the source of truth; these
            // are synced so the binding screen can render without asking PHP.
            $table->string('group', 40)->default('foundation')->after('description');
            $table->string('icon', 60)->nullable()->after('group');
            $table->boolean('is_core')->default(false)->after('icon');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_core');
        });

        Schema::create('organization_modules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();

            /*
             * Kept alongside the dates rather than implied by them. Switching
             * a module off for a fortnight while a dispute is settled must
             * not destroy the subscription window it will come back to.
             */
            $table->boolean('is_enabled')->default(true);

            /*
             * Both optional. No starts_at means "from now"; no expires_at
             * means "until somebody says otherwise", which is what a paid
             * subscription without a fixed term looks like. A trial sets
             * both.
             */
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();

            $table->timestamps();

            // One binding per pair; history is the audit log's job, not this
            // table's, or "is it enabled" stops having a single answer.
            $table->unique(['organization_id', 'module_id']);
        });

        // Reading an organization's entitlements is the hot path — every
        // tenant request that checks a module walks this.
        DB::statement(
            'CREATE INDEX organization_modules_live_idx ON organization_modules '.
            '(organization_id) WHERE is_enabled = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_modules');

        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['group', 'icon', 'is_core', 'sort_order']);
        });
    }
};
