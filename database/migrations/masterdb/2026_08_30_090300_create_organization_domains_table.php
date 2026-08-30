<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subdomain + custom domains — the future tenant-resolution table (Central/
 * Platform DB reference, table guide). Scaffolding only: `organizations.
 * subdomain` remains the single source tenant resolution reads today; no
 * backfill happens here, since an unread backfilled row would just go stale
 * the moment a new org is provisioned without a matching write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_domains', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->string('domain', 191)->unique();
            $table->string('type', 20)->default('subdomain');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'is_primary']);
        });

        DB::statement(
            'ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_type_check '.
            "CHECK (type IN ('subdomain', 'custom'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_domains');
    }
};
