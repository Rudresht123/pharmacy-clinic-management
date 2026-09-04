<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mark for each role.
 *
 * Roles are picked from a list far more often than they are read, and at a
 * glance a column of same-shaped rows is slower to scan than one where the
 * receptionist and the pharmacist look different. The icon is chosen by the
 * owner rather than derived from the capabilities: a role's capabilities
 * change as the organization does, and having its picture move underneath
 * somebody every time they tick a box would be worse than no picture.
 *
 * Stored as the icon class rather than a key mapped in the client, so the two
 * cannot disagree — and validated against Role::ICONS, so a value that reaches
 * a `class` attribute is always one of ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('icon', 40)->nullable()->after('description');
        });

        // The seeded role gets one too, or it alone would render blank.
        DB::table('roles')
            ->where('slug', 'staff')
            ->update(['icon' => 'ti ti-users']);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
