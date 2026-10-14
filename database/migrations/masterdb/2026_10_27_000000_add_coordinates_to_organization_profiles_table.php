<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an organization actually is, for the super admin's map.
 *
 * `location_source` says how far the point can be trusted:
 *   manual   somebody dragged the pin onto the building
 *   address  found from the street address
 *   pincode  only the PIN code's area was found
 *   city     only the city was found
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('location_source', 20)->nullable()->after('longitude');
            $table->timestamp('located_at')->nullable()->after('location_source');
        });
    }

    public function down(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_source', 'located_at']);
        });
    }
};
