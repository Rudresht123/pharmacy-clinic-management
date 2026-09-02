<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer was first signed up — provenance, not ownership.
 *
 * The name matters. `customers` deliberately has no `location_id`, because a
 * customer belongs to the organization and their history has to add up
 * across every branch. This column answers a different question — which
 * branch enrolled them — and must never be used to scope who can see them:
 * staff at any location still see every customer.
 *
 * Nullable, because customers already on file predate it and a walk-in can
 * be recorded without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('registered_location_id')
                ->nullable()
                ->after('id')
                ->constrained('locations')
                // A closed branch must not take its customers with it.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_location_id');
        });
    }
};
