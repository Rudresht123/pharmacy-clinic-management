<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opens field configuration up to the People screen as well as Locations.
 *
 * Two halves: the settings table's CHECK has to accept the new entity key,
 * and `users` needs somewhere to keep the values of the fields an
 * organization adds — the same jsonb column `locations` already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user'))"
        );

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('custom_fields')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });

        // Rows for the entity being withdrawn would violate the narrower
        // constraint, so they go first.
        DB::table('entity_field_settings')->where('entity', 'user')->delete();

        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location'))"
        );
    }
};
