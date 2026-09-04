<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the system/custom distinction from roles.
 *
 * `is_system` looked like it was protecting something and was not. It did
 * three things:
 *
 *   Refused deletion — but RoleController already refuses to delete a role
 *   anybody holds, and that check is the one that matters. All `is_system`
 *   added was protection for an EMPTY seeded role, which harms nobody to
 *   delete.
 *
 *   Grouped the roles screen under two headings — one of which had a single
 *   entry under it.
 *
 *   Let application code find a role by slug — which no application code ever
 *   did. `Role::SYSTEM_STAFF` was referenced in tests and in one comment.
 *
 * The genuine value underneath it was giving a new organization somewhere to
 * start, and the role templates on the Roles screen already do that better:
 * they produce a real role the owner owns, can rename, and can delete, instead
 * of one the software insists on keeping.
 *
 * The seeded `staff` role itself STAYS. Only its special standing goes — it
 * becomes an ordinary role, protected by the holder count like every other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false);
        });

        // Deliberately not restoring which role was "system". Rolling the
        // column back cannot know, and guessing would mark the wrong one.
    }
};
