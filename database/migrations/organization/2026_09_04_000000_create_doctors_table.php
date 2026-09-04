<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The doctors an organization's patients are seen by.
 *
 * Deliberately has no `location_id`. Which branches a doctor works at, and
 * when, is what `doctor_schedules` says — a visiting doctor sits at three
 * places on three days, and a column here could only ever hold one of them.
 * Adding one alongside the schedules would give "does Dr. Sharma work at
 * Delhi" two answers that can disagree.
 *
 * A doctor is an identity, not an employee record: no joining date, no
 * salary, no department. Those belong to an HR module that does not exist
 * and may never.
 *
 * A doctor need not be able to sign in. That is the whole reason this is its
 * own table rather than a role on `users` — a visiting consultant who never
 * touches the software still has to exist, be scheduled, and be named on a
 * prescription. Those who do sign in are linked through the `userable`
 * columns already on `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();

            $table->string('name', 191);

            // A short handle for OPD slips and the queue board.
            $table->string('code', 30)->nullable();

            $table->string('specialisation', 120)->nullable();
            $table->string('qualification', 191)->nullable();
            $table->string('registration_no', 60)->nullable();

            $table->string('phone', 20)->nullable();
            $table->string('email', 191)->nullable();

            /*
             * Named for what it is: the fallback, not the price. Branch and
             * visit-type pricing arrives with billing, as rules that end at
             * this column — so the column never has to be renamed or start
             * meaning something it does not.
             */
            $table->decimal('default_consultation_fee', 10, 2)->nullable();

            /*
             * Not a status column. "On leave" and "not visiting this month"
             * are absences from a schedule, not states of a person, and they
             * are answered properly by schedule exceptions rather than by a
             * word here that every screen would then have to interpret.
             */
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            // Values for fields the organization adds itself.
            $table->jsonb('custom_fields')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        /*
         * Unique among live rows only, so removing a doctor frees their code
         * and their registration number for reuse. Postgres treats NULLs as
         * distinct, and the explicit IS NOT NULL keeps the index small on
         * organizations that record neither.
         */
        DB::statement(
            'CREATE UNIQUE INDEX doctors_code_unique ON doctors (code) '.
            'WHERE deleted_at IS NULL AND code IS NOT NULL'
        );

        /*
         * A council registration number identifies a person. Two live
         * doctors sharing one is a duplicate record, which would later split
         * one doctor's prescriptions across two identities.
         */
        DB::statement(
            'CREATE UNIQUE INDEX doctors_registration_no_unique ON doctors (registration_no) '.
            'WHERE deleted_at IS NULL AND registration_no IS NOT NULL'
        );

        // Widen the settings CHECK so this screen can be configured too.
        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user', 'customer', 'doctor'))"
        );
    }

    public function down(): void
    {
        DB::table('entity_field_settings')->where('entity', 'doctor')->delete();

        DB::statement(
            'ALTER TABLE entity_field_settings DROP CONSTRAINT IF EXISTS entity_field_settings_entity_check'
        );

        DB::statement(
            'ALTER TABLE entity_field_settings ADD CONSTRAINT entity_field_settings_entity_check '.
            "CHECK (entity IN ('location', 'user', 'customer'))"
        );

        DB::statement('DROP INDEX IF EXISTS doctors_registration_no_unique');
        DB::statement('DROP INDEX IF EXISTS doctors_code_unique');

        Schema::dropIfExists('doctors');
    }
};
