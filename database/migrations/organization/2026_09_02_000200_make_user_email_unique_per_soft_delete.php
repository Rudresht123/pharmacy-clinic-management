<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A removed staff member's address should be free to use again.
 *
 * `users` soft-deletes, but the plain unique index counted deleted rows, so
 * someone who left and came back — or was removed by mistake — could never
 * be re-added under the same address. Same reasoning, and the same fix, as
 * masterdb/..._make_organization_code_and_subdomain_unique_per_soft_delete:
 * reuse the index name Laravel generated so nothing else has to know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
