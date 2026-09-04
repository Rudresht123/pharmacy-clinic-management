<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody intending to see a doctor.
 *
 * An appointment is an *intent*. What actually happened is a visit, which
 * arrives in Phase 3 and is where clinical records will hang — nothing
 * medical belongs on this table, however tempting it will be to add it.
 *
 * One table for both ways in. A booked patient has a `slot_at`; a walk-in
 * does not. Everything after arrival is identical for the two, and splitting
 * them would mean two queues, two status vocabularies, and a report that has
 * to union them back together to answer "how many did we see today".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();

            // Restricted, all three: an appointment that has lost its
            // patient, its doctor or its branch is not a record anybody can
            // act on, and deleting one out from under it would be worse than
            // refusing the delete.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            /*
             * Which sitting this came out of — provenance, not truth.
             *
             * The queue groups by session and the desk wants to know which
             * sitting somebody booked into, so it is worth remembering. But
             * the appointment carries its own date, time and branch, so
             * editing or deleting the weekly pattern can never move a
             * booking: it only forgets which sitting produced it. The same
             * idiom as customers.registered_location_id.
             */
            $table->foreignId('doctor_schedule_id')
                ->nullable()
                ->constrained('doctor_schedules')
                ->nullOnDelete();

            $table->date('appointment_date');

            $table->string('type', 20);
            $table->string('status', 20)->default('booked');

            // Booked only. A walk-in has no promised time, which is exactly
            // what distinguishes the two.
            $table->time('slot_at')->nullable();

            /*
             * Issued at check-in, not at booking. A booked patient who never
             * arrives must not consume a number, or the day's tokens have
             * holes nobody can explain.
             */
            $table->smallInteger('token_no')->nullable();

            /*
             * The three that measure something: how long somebody waited,
             * and how long they were in. Deliberately no `cancelled_at` —
             * the status plus the field-level audit already record when it
             * changed and who did it, and a timestamp per status is
             * denormalised state that can disagree with the status itself.
             */
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('cancellation_reason', 191)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The queue: one doctor, one branch, one day.
            $table->index(['doctor_id', 'location_id', 'appointment_date']);

            // A patient's own history, which Phase 3 will read as a timeline.
            $table->index(['customer_id', 'appointment_date']);
        });

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_type_check '.
            "CHECK (type IN ('booked', 'walk_in'))"
        );

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_status_check '.
            "CHECK (status IN ('booked', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'))"
        );

        /*
         * Token numbers are unique within one doctor's day at one branch.
         *
         * Not merely tidy: check-in reads the highest number and adds one,
         * and two receptionists doing that at the same moment would both
         * read the same value. With this index the second insert fails
         * loudly and can retry, instead of two patients silently holding
         * token 17.
         */
        DB::statement(
            'CREATE UNIQUE INDEX appointments_token_unique ON appointments '.
            '(doctor_id, location_id, appointment_date, token_no) '.
            'WHERE token_no IS NOT NULL AND deleted_at IS NULL'
        );

        /*
         * One booking per slot.
         *
         * Scoped to the doctor and the date without the branch, because a
         * doctor cannot be in two places at once — their sittings are
         * validated as non-overlapping, so the same time at two branches is
         * already impossible.
         *
         * A cancelled booking frees its slot; a no-show does not, because
         * that day is over and the slot was genuinely used up.
         */
        DB::statement(
            'CREATE UNIQUE INDEX appointments_slot_unique ON appointments '.
            '(doctor_id, appointment_date, slot_at) '.
            "WHERE slot_at IS NOT NULL AND deleted_at IS NULL AND status <> 'cancelled'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
