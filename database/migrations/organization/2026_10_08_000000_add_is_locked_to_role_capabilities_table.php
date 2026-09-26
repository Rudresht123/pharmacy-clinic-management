<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a role's capabilities a branch may not take away.
 *
 * The organization writes a role once; branches then customise it for
 * themselves (`branch_role_capabilities`). This is the organization saying
 * "not this one" — a doctor may not be stripped of the ability to read the
 * record of the patient in front of them, whatever a branch decides locally.
 *
 * A column on the grant rather than a table of its own, because a lock is a
 * property OF the grant: it cannot exist without one, it dies with one, and
 * asking "may this be customised" must never involve wondering whether a
 * second table has fallen out of step with the first.
 *
 * Defaults to false. Nothing is locked until somebody deliberately locks it,
 * so every role in every existing organization keeps behaving exactly as it
 * does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_capabilities', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('capability');
        });

        /*
         * Read on every branch-override lookup, to subtract the overrides that
         * a lock has since made void — and locked rows are the rare ones, so
         * a partial index is most of the table's size saved.
         */
        DB::statement(
            'CREATE INDEX role_capabilities_locked_idx ON role_capabilities '.
            '(role_id, capability) WHERE is_locked = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS role_capabilities_locked_idx');

        Schema::table('role_capabilities', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
