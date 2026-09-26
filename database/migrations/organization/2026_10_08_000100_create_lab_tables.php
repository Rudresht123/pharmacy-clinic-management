<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the doctor sent to the lab, and what came back.
 *
 * Two tables for the same reason prescriptions has two: the ORDER is one
 * clinical act with one author and one moment, and the TESTS on it each
 * finish at their own time. A CBC back in twenty minutes and an LFT back
 * tomorrow are one order and two results, and a single table would have to
 * choose which of those two facts to lose.
 *
 * MODELLED ON PRESCRIPTIONS ON PURPOSE. Same numbering function, same
 * restated patient/doctor/branch columns, same never-deleted-only-cancelled
 * rule, same soft-delete audit. A lab order and a prescription are the two
 * things a consultation produces, they gate the visit in exactly the same way,
 * and the pharmacist's screen and the technician's screen should not have been
 * written against two different shapes.
 *
 * `consultations.investigations` is NOT replaced and NOT migrated. It is the
 * doctor's free-text note of what they want looked at — it predates this, it
 * prints on the letterhead, and plenty of clinics will keep writing there and
 * never open a lab order. A lab order is the tracked version: it has a
 * technician, a state and a result. Forcing the old notes into orders would
 * manufacture a queue of work nobody asked for, addressed to a lab that may
 * not exist.
 *
 * WHY MULTIPLE ORDERS PER VISIT ARE ALLOWED, unlike prescriptions. A
 * prescription is one document the patient carries out of the room, so a
 * second is an error. A doctor who orders bloods, reads them and then orders a
 * scan has done two separate things, both of which need their own author, time
 * and state.
 */
return new class extends Migration
{
    private const ORDER_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    private const ITEM_STATUSES = ['pending', 'completed', 'cancelled'];

    /** Where the sample comes from — a shortlist, not a taxonomy. */
    private const SPECIMENS = ['blood', 'urine', 'stool', 'swab', 'tissue', 'imaging', 'other'];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement(
            'CREATE SEQUENCE IF NOT EXISTS lab_order_number_seq AS bigint START WITH 1 MINVALUE 1 NO CYCLE'
        );

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();

            // LAB-00001, taken by the database inside the INSERT — two
            // doctors ordering at once can never draw the same number.
            $table->string('order_number', 20)
                ->default(DB::raw("hms_document_number('LAB', 'lab_order_number_seq')"));

            // Restated rather than read through the appointment, exactly as
            // prescriptions and consultations do: who ordered what for whom
            // must survive the visit row being edited.
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->restrictOnDelete();

            $table->date('order_date');
            $table->string('status', 20)->default('pending');

            // Why the tests were asked for. Kept out of activity_logs, like
            // every other piece of clinical free text.
            $table->text('clinical_notes')->nullable();

            /*
             * The technician's two acts, each with its author.
             *
             * `started_at` is the sample being taken in hand, which is worth
             * separating from completion: a lab that has a backlog and a lab
             * that is slow look identical without it.
             */
            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            // The technician's queue: this branch, what is still outstanding.
            $table->index(['location_id', 'status']);
            $table->index(['customer_id', 'order_date']);
            $table->index('appointment_id');
        });

        DB::statement(
            'ALTER TABLE lab_orders ADD CONSTRAINT lab_orders_rules_check CHECK ('.
            'status IN ('.$in(self::ORDER_STATUSES).') '.
            "AND (status <> 'in_progress' OR started_at IS NOT NULL) ".
            // Completed implies it was started, even if both happened at once.
            "AND (status <> 'completed' OR (started_at IS NOT NULL AND completed_at IS NOT NULL)) ".
            "AND (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL)))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX lab_orders_number_unique ON lab_orders (order_number) '.
            'WHERE deleted_at IS NULL'
        );

        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('lab_orders')->restrictOnDelete();

            /*
             * Free text, not a foreign key.
             *
             * There is no test catalogue, and inventing one here would be a
             * second master nobody maintains — every clinic's panel is its
             * own, and a doctor typing "CBC" must not be blocked because
             * nobody has set up a catalogue yet. When a catalogue arrives, a
             * nullable `lab_test_id` sits beside this exactly as
             * `prescription_items.medicine_id` sits beside its snapshot.
             */
            $table->string('test_name', 191);
            $table->string('test_code', 40)->nullable();
            $table->string('specimen', 20)->nullable();

            $table->string('status', 20)->default('pending');

            /*
             * The result as three fields rather than one blob: the value, what
             * it is measured in, and what normal looks like. A report that
             * says "14.2" is unreadable; one that says "14.2 g/dL (13.0–17.0)"
             * is the whole point of recording it.
             */
            $table->string('result_value', 191)->nullable();
            $table->string('result_unit', 40)->nullable();
            $table->string('reference_range', 100)->nullable();

            // The technician's judgement, not a computed comparison — a range
            // is free text and "13.0–17.0" cannot be parsed reliably.
            $table->boolean('is_abnormal')->default(false);

            $table->text('notes')->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['lab_order_id', 'sort_order']);
        });

        DB::statement(
            'ALTER TABLE lab_order_items ADD CONSTRAINT lab_order_items_rules_check CHECK ('.
            'status IN ('.$in(self::ITEM_STATUSES).') '.
            'AND (specimen IS NULL OR specimen IN ('.$in(self::SPECIMENS).')) '.
            // A finished test has a time and something to report.
            "AND (status <> 'completed' OR (completed_at IS NOT NULL AND result_value IS NOT NULL)) ".
            'AND length(btrim(test_name)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');

        DB::statement('DROP SEQUENCE IF EXISTS lab_order_number_seq');
    }
};
