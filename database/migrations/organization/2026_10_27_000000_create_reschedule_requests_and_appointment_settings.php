<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A patient asking to move a booking, and the desk's answer.
 *
 * A REQUEST, NOT A MOVE. The patient picks a time they would rather have; the
 * appointment itself does not change until somebody at the clinic approves —
 * because the desk knows things the timetable does not (the doctor is running
 * an hour late that day, the patient has been moved twice already).
 *
 * The time asked for is not held while the request waits: holding it would
 * let one patient block a slot from everybody else just by asking. If it has
 * gone by the time the desk looks, they approve a different one instead.
 *
 * `appointment_settings` is one row per organization: who hears about a new
 * request — the people at the desk who can answer it, the doctor, or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();

            // Copied from the visit so the desk's list reads without a join
            // and stays right after the visit itself has moved.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            // Where the visit was when they asked, and where they asked for.
            $table->date('from_date');
            $table->time('from_slot_at')->nullable();
            $table->date('requested_date');
            $table->time('requested_slot_at');
            $table->foreignId('requested_location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('reason', 191)->nullable();

            $table->string('status', 12)->default('pending');

            // What the visit was actually moved to — the desk may approve a
            // different time from the one asked for, when that one has gone.
            $table->date('approved_date')->nullable();
            $table->time('approved_slot_at')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['location_id', 'status', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE appointment_reschedule_requests ADD CONSTRAINT appointment_reschedule_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn'))"
        );

        // One open request per visit: asking again replaces the question, it
        // does not queue a second one beside it.
        DB::statement(
            "CREATE UNIQUE INDEX appointment_reschedule_one_pending ON appointment_reschedule_requests (appointment_id) WHERE status = 'pending'"
        );

        Schema::create('appointment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('reschedule_notify', 10)->default('desk');

            /*
             * Who moves a patient through the queue, step by step: the desk,
             * the doctor, or either. Not every clinic has a receptionist — a
             * doctor on their own checks in, calls and starts everybody. The
             * defaults are what the software did before there was a choice.
             */
            $table->string('check_in_by', 10)->default('both');
            $table->string('call_by', 10)->default('both');
            $table->string('consult_start_by', 10)->default('doctor');

            $table->timestamps();
        });

        foreach (['reschedule_notify', 'check_in_by', 'call_by', 'consult_start_by'] as $column) {
            DB::statement(
                "ALTER TABLE appointment_settings ADD CONSTRAINT appointment_settings_{$column}_check CHECK ({$column} IN ('desk', 'doctor', 'both'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_settings');
        Schema::dropIfExists('appointment_reschedule_requests');
    }
};
