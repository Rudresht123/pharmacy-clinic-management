<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two parts of an address a PIN code answers that the record had nowhere
 * to keep.
 *
 * A PIN code resolves to a district, a state and a country. The record already
 * held a city and a state; the district is what an Indian address is actually
 * filed under, and the country is what makes the rest unambiguous the day a
 * patient comes from across a border.
 *
 * Nullable, like every other part of the address: existing patients have
 * neither, and nobody is required to give either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('district', 100)->nullable()->after('city');
            $table->string('country', 100)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['district', 'country']);
        });
    }
};
