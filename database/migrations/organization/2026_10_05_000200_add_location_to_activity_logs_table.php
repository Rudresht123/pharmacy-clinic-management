<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a change happened.
 *
 * "Who changed what" was answerable; "who changed what AT GURGAON" was not,
 * which is the question somebody reviewing a branch manager's work actually
 * asks. The branch is the one the request was acting in, taken from the same
 * resolved value every permission check used — so the log records the branch
 * the software believed it was in, not one re-derived afterwards.
 *
 * Deliberately NOT a foreign key, matching `user_id` on this table and for the
 * same reason: `activity_logs` is append-only behind a database trigger, and
 * ON DELETE SET NULL makes Postgres issue an UPDATE the trigger refuses — so
 * deleting a branch with any history would fail outright. Immutability is the
 * property this table exists for; the constraint is the part that goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('actor_type');

            // "Everything that happened at this branch, newest first."
            $table->index(['location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'created_at']);
            $table->dropColumn('location_id');
        });
    }
};
