<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One login per person the login stands for.
 *
 * `users.userable_type` / `userable_id` link an account to whoever it
 * represents — a doctor, today. Nothing stopped two accounts pointing at the
 * same doctor, and prescriptions will ask "who wrote this": with two
 * identities for one person, that question has two answers and the patient's
 * record cannot say which.
 *
 * Live rows only, so removing an account frees the link. A doctor with no
 * account at all stays perfectly valid — that is why `doctors` is its own
 * table — which is what the IS NOT NULL clause preserves.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX users_userable_unique ON users (userable_type, userable_id) '.
            'WHERE deleted_at IS NULL AND userable_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_userable_unique');
    }
};
