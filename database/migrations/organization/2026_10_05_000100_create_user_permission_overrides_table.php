<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One person, minus one thing.
 *
 * A role is the unit of permission and stays the unit: capabilities are held
 * BY REFERENCE, so editing a role still changes what its holders can do. This
 * table is the exception that keeps that true — "the receptionist role, but
 * this one person may not issue refunds" used to mean cloning the role, and
 * three receptionists with three small differences meant three roles that
 * then drifted apart.
 *
 * DENY ONLY, on purpose.
 *
 * A deny cannot escalate. It needs no new guard, and there is no way to get it
 * wrong that ends with somebody holding more than they should. An `allow`
 * would need the full StaffScope::canGrant treatment — an override granting
 * what the granter does not hold is precisely the escalation this system is
 * built to prevent — and in practice the case that comes up is taking one
 * thing away, not adding one. Adding `effect = allow` later is additive and
 * changes no row written now.
 *
 * `location_id` is nullable and means "everywhere". Somebody who is a
 * receptionist at two branches and may not refund at either is one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /* Null = at every branch. Set = that branch alone. */
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnDelete();

            /*
             * A varchar naming a ModuleRegistry capability, not a foreign key
             * — the same choice `role_capabilities` makes, for the same
             * reason: the registry is code, and a capability retired from it
             * must stop working rather than block a migration.
             */
            $table->string('capability', 100);

            /*
             * Only 'deny' is written today. The column exists so adding
             * 'allow' later needs no migration of the rows already here.
             */
            $table->string('effect', 10)->default('deny');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'location_id']);
        });

        /*
         * One answer per person per capability per place — as TWO partial
         * indexes, not one composite unique.
         *
         * Postgres treats NULLs as distinct in a unique index, so a plain
         * unique on (user_id, location_id, capability) would happily accept
         * the same "everywhere" deny twice. Splitting on whether the branch
         * is set says what was actually meant, on every Postgres version.
         */
        DB::statement(
            'CREATE UNIQUE INDEX upo_unique_at_branch ON user_permission_overrides '
            .'(user_id, location_id, capability) WHERE location_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX upo_unique_everywhere ON user_permission_overrides '
            .'(user_id, capability) WHERE location_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
