<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's profile photo — same shape as `doctors.photo`: a file id
 * in the tenant's own `files` table, not a path, so a replaced photo does not
 * leave an orphaned file and a deleted file does not take the account with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('avatar')->nullable()->after('email');

            $table->foreign('avatar')
                ->references('id')
                ->on('files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar']);
            $table->dropColumn('avatar');
        });
    }
};
