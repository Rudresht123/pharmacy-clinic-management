<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_key` — the immutable identifier the tenant database name is
 * derived from, replacing today's derivation from the mutable
 * `organization_name`. Format is enforced by a CHECK constraint rather than
 * application code, matching `widenStatus()`'s pattern in
 * `expand_organizations_to_spec.php`: the string becomes a literal segment
 * of a `CREATE DATABASE hms_tenant_<tenant_key>` identifier, so it must stay
 * lowercase, start with a letter, and contain nothing Postgres would refuse
 * to use unquoted.
 *
 * Only new organizations get the new `hms_tenant_<tenant_key>` naming —
 * already-provisioned tenant databases keep their existing `database_name`
 * untouched; this migration never rewrites that column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tenant_key', 40)->nullable()->after('slug');
        });

        $this->backfillTenantKey();

        DB::statement('ALTER TABLE organizations ALTER COLUMN tenant_key SET NOT NULL');
        DB::statement(
            'ALTER TABLE organizations ADD CONSTRAINT organizations_tenant_key_format_check '.
            "CHECK (tenant_key ~ '^[a-z][a-z0-9_]{2,39}$')"
        );

        Schema::table('organizations', function (Blueprint $table) {
            $table->unique('tenant_key');
        });
    }

    /**
     * Derive from `slug`, not `database_name`: slug is already unique,
     * immutable, and built from the same identity backfill in
     * `expand_organizations_to_spec.php`. `database_name` for pre-existing
     * rows has no governed shape and — per this migration's own contract —
     * is never touched, so there is nothing to keep it in sync with.
     */
    private function backfillTenantKey(): void
    {
        $rows = DB::table('organizations')->select('id', 'slug')->get();

        foreach ($rows as $row) {
            $base = str_replace('-', '_', (string) $row->slug);

            // The CHECK requires a leading letter; a slug born from a
            // digit-leading name (e.g. "24x7 Pharmacy") would otherwise fail it.
            if (! preg_match('/^[a-z]/', $base)) {
                $base = 'org_'.$base;
            }

            $base = substr($base, 0, 36); // headroom for a "_N" dedupe suffix

            $key = $base;
            $counter = 1;

            while (DB::table('organizations')->where('tenant_key', $key)->exists()) {
                $key = "{$base}_{$counter}";
                $counter++;
            }

            DB::table('organizations')->where('id', $row->id)->update(['tenant_key' => $key]);
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['tenant_key']);
        });

        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_tenant_key_format_check');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('tenant_key');
        });
    }
};
