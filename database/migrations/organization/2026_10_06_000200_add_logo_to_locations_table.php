<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's own letterhead.
 *
 * The organization already has one — `organizations.profile_image`, in the
 * master database, served from the public disk because it is on the sign-in
 * screen. A branch had none, so a chain whose Gurgaon clinic prints under a
 * different mark than its Noida one could not say so.
 *
 * PUBLIC, like the organization's, and worth saying out loud: a letterhead is
 * on every document handed across a counter, so treating it as a secret would
 * be pretending. Patient documents are the private ones, and they are on a
 * different disk entirely.
 *
 * Null is the normal state, and means "use the organization's" — the same
 * inheritance the templates themselves use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('logo_file_id')
                ->nullable()
                ->after('manager_id')
                ->constrained('files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_file_id');
        });
    }
};
