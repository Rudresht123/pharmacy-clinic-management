<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every doctor runs their own queue, and the waiting room can watch it.
 *
 * NO `opd_queues` TABLE, ON PURPOSE. `appointments` already is the queue: one
 * row per visit, booked or walk-in, with its token, its `queue_status`, and
 * the clocks for arriving, being called, starting and finishing. A second
 * table would mean two status vocabularies kept in step by hand, which is the
 * exact thing the create-appointments migration says it exists to prevent. So
 * this adds to that row what a smart queue needs and it does not have yet.
 *
 * WHAT IS NEW
 *
 *   appointments              the token's prefix, the priority and the reason
 *                             for it, and the `skipped` queue state.
 *   queue_counters            one row per doctor (or shared department) per
 *                             branch per day; holding its lock is what issuing
 *                             a number means.
 *   queue_events              what happened to a queue, by whom. Append-only.
 *   doctor_queue_settings     a doctor's room, prefix and habits AT A BRANCH —
 *                             the same doctor sits in different rooms at two.
 *   department_queue_settings the optional shared run of numbers for a
 *                             department that hands out one sequence.
 *   queue_displays            the televisions: which doctors, which look, and
 *                             the HASH of the secret in the URL. Never the
 *                             secret itself.
 *
 * BIGINT KEYS, NOT UUIDS. Every tenant table uses them, and a queue row's id
 * never leaves a signed-in screen: the only identifier a stranger ever sees is
 * the display token, which is random and hashed here.
 */
return new class extends Migration
{
    private const QUEUE = ['waiting', 'called', 'with_doctor', 'skipped'];

    private const OLD_QUEUE = ['waiting', 'called', 'with_doctor'];

    private const CONSULTATION = ['not_started', 'in_progress', 'completed'];

    private const NEXT_ACTION = ['pharmacy', 'laboratory', 'billing', 'follow_up', 'none'];

    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            /*
             * The letter(s) in front of the number, copied from the doctor's
             * setting when the token is issued. Copied rather than joined:
             * this morning's A012 stays A012 if somebody renames the prefix at
             * lunchtime. Null on every token issued before there were prefixes.
             */
            $table->string('token_prefix', 8)->nullable()->after('token_no');

            $table->string('queue_priority', 12)->default('normal')->after('queue_status');
            $table->string('priority_reason', 191)->nullable()->after('queue_priority');

            // The moment the room gave up on them, and the last time they were
            // re-announced. Neither is clinical; both explain the screen.
            $table->timestamp('skipped_at')->nullable()->after('called_by');
            $table->timestamp('recalled_at')->nullable()->after('skipped_at');
        });

        $this->replaceWorkflowCheck(self::QUEUE);

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_priority_check '.
            "CHECK (queue_priority IN ('normal', 'urgent', 'emergency'))"
        );

        /*
         * One doctor's live queue — the query behind every card on every
         * television. Partial, because at the end of a year almost no row is
         * in a queue and a full index would be mostly about nothing.
         */
        DB::statement(
            'CREATE INDEX appointments_live_queue_index ON appointments '.
            '(doctor_id, location_id, appointment_date, queue_status) '.
            'WHERE queue_status IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::create('doctor_queue_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();

            $table->string('room_no', 30)->nullable();
            $table->string('token_prefix', 8)->nullable();

            // Used until there is enough of their own history to go on.
            $table->smallInteger('average_consultation_minutes')->default(10);

            $table->boolean('auto_call_enabled')->default(false);
            $table->boolean('allow_priority')->default(true);

            // Off keeps the doctor off the board and the televisions. Patients
            // still check in; the visit does not depend on the queue screen.
            $table->boolean('queue_enabled')->default(true);

            /*
             * Paused is a fact about a moment, not a setting: it is the stamp
             * that says so, and only counts on the day it was made. A doctor
             * who pauses for lunch and goes home must not open tomorrow paused.
             */
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('pause_until')->nullable();
            $table->string('pause_reason', 191)->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['location_id', 'doctor_id']);
        });

        // One A at a branch. Case-folded: an `a` and an `A` read the same on a
        // television and are the same thing said out loud.
        DB::statement(
            'CREATE UNIQUE INDEX doctor_queue_settings_prefix_unique ON doctor_queue_settings '.
            '(location_id, UPPER(token_prefix)) WHERE token_prefix IS NOT NULL'
        );

        Schema::create('department_queue_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('token_prefix', 8);

            $table->timestamps();

            $table->unique(['location_id', 'department_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX department_queue_settings_prefix_unique ON department_queue_settings '.
            '(location_id, UPPER(token_prefix))'
        );

        Schema::create('queue_counters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->cascadeOnDelete();

            // The clinic's own calendar day. A new day is simply a new row,
            // which is all "tokens reset at midnight" has ever needed to mean.
            $table->date('queue_date');

            $table->string('prefix', 8)->nullable();
            $table->integer('last_number')->default(0);

            $table->timestamps();
        });

        // A run belongs to a doctor or to a department, never both and never neither.
        DB::statement(
            'ALTER TABLE queue_counters ADD CONSTRAINT queue_counters_owner_check '.
            'CHECK ((doctor_id IS NULL) <> (department_id IS NULL))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX queue_counters_doctor_unique ON queue_counters '.
            '(location_id, doctor_id, queue_date) WHERE doctor_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX queue_counters_department_unique ON queue_counters '.
            '(location_id, department_id, queue_date) WHERE department_id IS NOT NULL'
        );

        Schema::create('queue_events', function (Blueprint $table) {
            $table->id();

            // Null for what happens to the whole queue: paused, resumed.
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->date('queue_date');

            $table->string('event_type', 30);
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            // The reason, the old and new doctor, the token at the time.
            $table->jsonb('metadata')->nullable();

            // No updated_at: nothing here is ever changed.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['appointment_id', 'created_at']);
            $table->index(['doctor_id', 'location_id', 'queue_date', 'created_at']);
        });

        Schema::create('queue_displays', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('display_name', 120);
            $table->string('display_type', 20);

            // SHA-256 of the whole token. Long and random, so a slow hash would
            // add nothing — and it has to be looked up, not just compared.
            $table->char('secure_display_token_hash', 64)->unique();

            $table->string('layout', 20)->default('grid');
            $table->string('theme', 10)->default('dark');

            $table->boolean('audio_enabled')->default(false);
            $table->jsonb('audio_languages')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'is_active']);
        });

        DB::statement(
            'ALTER TABLE queue_displays ADD CONSTRAINT queue_displays_type_check '.
            "CHECK (display_type IN ('reception', 'department', 'doctor', 'laboratory'))"
        );

        DB::statement(
            'ALTER TABLE queue_displays ADD CONSTRAINT queue_displays_layout_check '.
            "CHECK (layout IN ('grid', 'list'))"
        );

        DB::statement(
            'ALTER TABLE queue_displays ADD CONSTRAINT queue_displays_theme_check '.
            "CHECK (theme IN ('dark', 'light'))"
        );

        Schema::create('queue_display_doctors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('queue_display_id')->constrained('queue_displays')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->smallInteger('sort_order')->default(0);

            $table->unique(['queue_display_id', 'doctor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_display_doctors');
        Schema::dropIfExists('queue_displays');
        Schema::dropIfExists('queue_events');
        Schema::dropIfExists('queue_counters');
        Schema::dropIfExists('department_queue_settings');
        Schema::dropIfExists('doctor_queue_settings');

        DB::statement('DROP INDEX IF EXISTS appointments_live_queue_index');
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_priority_check');

        // Somebody set aside goes back in line: the old vocabulary has no word for them.
        DB::statement("UPDATE appointments SET queue_status = 'waiting' WHERE queue_status = 'skipped'");

        $this->replaceWorkflowCheck(self::OLD_QUEUE);

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['token_prefix', 'queue_priority', 'priority_reason', 'skipped_at', 'recalled_at']);
        });
    }

    /**
     * The three-axis CHECK from the workflow split, with one more queue state.
     *
     * Dropped and written back whole, because a CHECK cannot be amended.
     *
     * @param  list<string>  $queue
     */
    private function replaceWorkflowCheck(array $queue): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_workflow_check');

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_workflow_check CHECK ('.
            '(queue_status IS NULL OR queue_status IN ('.$in($queue).')) '.
            'AND consultation_status IN ('.$in(self::CONSULTATION).') '.
            'AND (next_action IS NULL OR next_action IN ('.$in(self::NEXT_ACTION).')) '.

            "AND (consultation_status <> 'in_progress' OR queue_status = 'with_doctor') ".
            "AND (consultation_status = 'not_started' OR started_at IS NOT NULL) ".
            "AND (consultation_status <> 'completed' OR completed_at IS NOT NULL) ".

            "AND (status NOT IN ('awaiting_pharmacy', 'awaiting_lab', 'awaiting_payment') ".
            "     OR consultation_status = 'completed') ".

            "AND (status <> 'completed' OR consultation_status = 'completed') ".
            "AND (status <> 'in_consultation' OR queue_status = 'with_doctor') ".

            "AND (queue_status <> 'called' OR called_at IS NOT NULL))"
        );
    }
};
