<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a patient needs to sign in to the app with their phone.
 *
 * A patient login is an ordinary `users` row whose `userable` is their
 * customer record — the same shape a doctor's login already has. Two things
 * stood in the way of that:
 *
 *   `users.email` was required. A patient signs in with the number the clinic
 *   already holds for them, and asking for an email they may not have would
 *   put a form in front of the one screen that has to be effortless. The
 *   unique index on it is already partial (WHERE deleted_at IS NULL), and
 *   Postgres never treats two NULLs as equal, so any number of patients can
 *   have none while staff addresses stay unique.
 *
 *   `users.role` was checked to owner/staff at the database. A patient login
 *   is a third KIND of account (not a permission — see User::PATIENT).
 *
 *   There was nowhere to keep a one-time code while it is outstanding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // The account kind gains `patient` (User::PATIENT). The DB facade, as
        // in the migration that added the check: a migration must replay
        // against the schema as it was, not against today's model.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner', 'staff', 'patient'))");

        Schema::create('patient_login_codes', function (Blueprint $table) {
            $table->id();

            // The last ten digits — what Customer::scopeWithPhone matches on.
            $table->string('phone', 20);

            // Hashed like a password. A code is a credential for as long as it
            // is outstanding, and a database dump must not hand them out.
            $table->string('code_hash');

            // Wrong guesses against THIS code. It is spent after a handful, so
            // six digits cannot be walked by brute force.
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_login_codes');

        // Patient logins would violate the narrower check, so they go first.
        DB::table('users')->where('role', 'patient')->delete();
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner', 'staff'))");

        // Not reversed to NOT NULL: once a patient has signed in there are
        // rows without an address, and the rollback would fail on them.
    }
};
