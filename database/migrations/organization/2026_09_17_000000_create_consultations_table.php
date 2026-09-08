<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happened in the room.
 *
 * The appointment says somebody was due and turned up; this says what the
 * doctor found. They are deliberately separate rows: an appointment is intent
 * and can be cancelled, moved or never arrive, while a consultation is a
 * clinical record and, once written, is the thing a later doctor reads.
 *
 * One per appointment, enforced by a unique index rather than by hope — a
 * second consultation against the same visit would be two answers to "what was
 * the diagnosis".
 *
 * The repeating parts (diagnoses, prescription lines, investigations, vitals)
 * are jsonb rather than four child tables. They are written and read as a whole
 * consultation, never queried across patients, and a prescription line means
 * nothing away from the visit it belongs to. When a drug catalogue arrives and
 * "how many patients are on amlodipine" becomes a real question, the lines can
 * be lifted into their own table — the shape here does not prevent it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('organization')->create('consultations', function (Blueprint $table) {
            $table->id();

            /*
             * The visit it belongs to. Cascades: a deleted appointment never
             * happened, and a consultation with no visit is a note about
             * nobody.
             */
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();

            /*
             * Restated rather than read through the appointment.
             *
             * "Who saw this patient, and which patient" must survive the
             * appointment being edited afterwards, and every read of a
             * patient's history filters on the customer without wanting a join
             * to get there.
             */
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();

            // Why they came, in their words.
            $table->text('chief_complaint')->nullable();

            // What the doctor thinks it is — a list, because it often is.
            $table->jsonb('diagnoses')->nullable();

            // What was measured at the desk or in the room.
            $table->jsonb('vitals')->nullable();

            // What they were given, and what was ordered.
            $table->jsonb('prescription')->nullable();
            $table->jsonb('investigations')->nullable();

            $table->text('advice')->nullable();
            $table->text('notes')->nullable();

            // "After 7 days" — days rather than a date, because that is how it
            // is said and it stays true if the visit itself moves.
            $table->smallInteger('follow_up_days')->nullable();

            $table->timestamps();
        });

        DB::connection('organization')->statement(
            'CREATE UNIQUE INDEX consultations_appointment_unique
             ON consultations (appointment_id)'
        );

        DB::connection('organization')->statement(
            'ALTER TABLE consultations
             ADD CONSTRAINT consultations_follow_up_days_check
             CHECK (follow_up_days IS NULL OR (follow_up_days > 0 AND follow_up_days <= 3650))'
        );

        // The two reads that happen: this patient's history, and this doctor's.
        Schema::connection('organization')->table('consultations', function (Blueprint $table) {
            $table->index(['customer_id', 'created_at']);
            $table->index(['doctor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('organization')->dropIfExists('consultations');
    }
};
