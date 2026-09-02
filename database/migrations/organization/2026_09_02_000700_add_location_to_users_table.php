<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branch somebody works at.
 *
 * One branch per person: a pharmacy hand is at one counter, and a single
 * column keeps "which branch registered this customer" unambiguous. If
 * relief staff covering several branches ever becomes real, this becomes a
 * pivot — deliberately not built for it now.
 *
 * Nullable, and the organization's owner is expected to have none: they work
 * across the whole network rather than at one till.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('role')
                ->constrained('locations')
                // Closing a branch must not delete the people who worked there.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
