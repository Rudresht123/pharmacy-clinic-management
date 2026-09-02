<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The person an organization serves.
 *
 * Deliberately has no `location_id`. A customer belongs to the organization,
 * never to one store — that is what lets their purchases from every branch,
 * their prescriptions and their ledger add up to one history rather than
 * three unrelated ones. Anything that happens at a particular store records
 * the location on itself, not here.
 *
 * The clinic module is expected to extend this same row rather than open a
 * second patients table, so the shape stays general: nothing here is
 * pharmacy-specific.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 191);

            /*
             * In practice the phone number is how a counter finds somebody,
             * so it is indexed and unique when given — but optional, because
             * a walk-in paying cash may not leave one.
             */
            $table->string('phone', 20)->nullable();
            $table->string('email', 191)->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();

            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // Values for fields the organization adds itself.
            $table->jsonb('custom_fields')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        DB::statement(
            'ALTER TABLE customers ADD CONSTRAINT customers_gender_check '.
            "CHECK (gender IS NULL OR gender IN ('male', 'female', 'other'))"
        );

        /*
         * Unique among live rows only, so a removed customer frees their
         * number. Postgres treats NULLs as distinct in a unique index, so any
         * number of customers may have no phone at all.
         */
        DB::statement(
            'CREATE UNIQUE INDEX customers_phone_unique ON customers (phone) '.
            'WHERE deleted_at IS NULL AND phone IS NOT NULL'
        );

        // Widen the settings CHECK so this screen can be configured too.
        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user', 'customer'))"
        );
    }

    public function down(): void
    {
        DB::table('entity_field_settings')->where('entity', 'customer')->delete();

        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user'))"
        );

        DB::statement('DROP INDEX IF EXISTS customers_phone_unique');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_gender_check');

        Schema::dropIfExists('customers');
    }
};
