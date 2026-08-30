<?php

use App\Models\Platform\TenantDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The routing table for tenant databases (Central/Platform DB reference).
 * `db_name` becomes the canonical going-forward source for a tenant's
 * database name; `organizations.database_name` stays untouched for
 * backward compatibility until provisioning itself is rewritten to write
 * here.
 *
 * Backfilled from `organizations` only — no tenant database connection is
 * opened by this migration. `db_password_encrypted` is left null for every
 * backfilled row: every tenant today is reached with the shared master
 * credentials, so there is no per-tenant secret yet to store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_databases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->unique()
                ->constrained('organizations')
                ->cascadeOnDelete();

            // Default RESTRICT — a cluster with tenants on it can't be
            // deleted out from under them.
            $table->foreignId('db_cluster_id')->constrained('db_clusters');

            $table->string('db_name', 63)->unique();
            $table->string('db_user', 63);
            $table->text('db_password_encrypted')->nullable();

            $table->string('provision_status', 20);
            $table->string('status', 20)->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->string('last_backup_status', 20)->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE tenant_databases ADD CONSTRAINT tenant_databases_provision_status_check '.
            "CHECK (provision_status IN ('pending', 'provisioning', 'provisioned', 'failed'))"
        );
        DB::statement(
            'ALTER TABLE tenant_databases ADD CONSTRAINT tenant_databases_status_check '.
            "CHECK (status IN ('healthy', 'degraded', 'unreachable'))"
        );
        DB::statement(
            'ALTER TABLE tenant_databases ADD CONSTRAINT tenant_databases_last_backup_status_check '.
            "CHECK (last_backup_status IN ('success', 'failed'))"
        );

        $this->backfill();
    }

    private function backfill(): void
    {
        $clusterId = DB::table('db_clusters')->where('is_default', true)->value('id');
        $dbUser = config('database.connections.pgsql.username');

        DB::table('organizations')
            ->select('id', 'database_name', 'status')
            ->orderBy('id')
            ->each(function ($organization) use ($clusterId, $dbUser) {
                $mapped = TenantDatabase::mapOrganizationStatus($organization->status);

                DB::table('tenant_databases')->insert([
                    'organization_id' => $organization->id,
                    'db_cluster_id' => $clusterId,
                    'db_name' => $organization->database_name,
                    'db_user' => $dbUser,
                    'provision_status' => $mapped['provision_status'],
                    'status' => $mapped['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_databases');
    }
};
