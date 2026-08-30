<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align on the plural table name used everywhere else in the schema
 * (organizations, platform_users, ...) and add the FK that migration
 * ordering made impossible originally: `create_organizations_table` ran
 * before `create_organization_type_table`, so `organization_type_id` has
 * never been constrained. That blocker is gone now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('organization_type', 'organization_types');

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('organization_type_id')
                ->references('id')->on('organization_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['organization_type_id']);
        });

        Schema::rename('organization_types', 'organization_type');
    }
};
