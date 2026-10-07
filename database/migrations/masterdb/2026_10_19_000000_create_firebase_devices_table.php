<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones (and browsers) that can receive push notifications.
 *
 * MASTER DATABASE, deliberately — see App\Models\Platform\FirebaseDevice. The
 * whole point is `fcm_token` being unique across every organization: a phone
 * that signs out of one clinic and into another MOVES its row, so the first
 * can never push to it again. A table per tenant database could not see the
 * other clinic's row to move it.
 *
 * `user_id`, `branch_id` and `access_token_id` point into the tenant database
 * and carry no foreign key; Postgres cannot reference across databases. They
 * are only ever read together with `organization_id`.
 *
 * No patient data: who is reachable, never what they are told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firebase_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Tenant ids — meaningful only beside organization_id.
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            // The app's own id for the install, stable across token rotations.
            $table->string('device_id', 191);

            // Unique across EVERY organization — the property this table exists for.
            $table->string('fcm_token', 512)->unique();

            // The sign-in this registration belongs to, so signing out of it
            // switches this device off and no other.
            $table->unsignedBigInteger('access_token_id')->nullable();

            $table->string('platform', 20);
            $table->string('app_version', 40)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'device_id']);

            // Who to push to: always one organization's people, live devices.
            $table->index(['organization_id', 'user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_devices');
    }
};
