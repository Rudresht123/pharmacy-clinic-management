<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `organization_code` and `subdomain` are business-facing labels, not
 * identity — unlike `slug`/`database_name`/`tenant_key`/`uuid`, which stay
 * globally unique forever because they point at a real physical database
 * that a soft delete deliberately leaves in place. A soft-deleted org's
 * code or subdomain should be free for a new org to take; the plain unique
 * constraint had no way to express that, so `StoreOrganizationRequest`'s own
 * `->whereNull('deleted_at')` validation could approve a create that then
 * failed at the database with a raw unique-constraint violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique('organizations_organization_code_unique');
            $table->dropUnique('organizations_subdomain_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX organizations_organization_code_unique '.
            'ON organizations (organization_code) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX organizations_subdomain_unique '.
            'ON organizations (subdomain) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_organization_code_unique');
        DB::statement('DROP INDEX IF EXISTS organizations_subdomain_unique');

        Schema::table('organizations', function (Blueprint $table) {
            $table->unique('organization_code');
            $table->unique('subdomain');
        });
    }
};
