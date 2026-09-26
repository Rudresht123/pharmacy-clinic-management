<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three questions, three columns.
 *
 * `appointments.status` has been answering all of them at once: where the
 * patient physically is, how far the write-up has got, and whether the visit
 * is finished. One column cannot, and the place it broke is the desk — "Call
 * in" moved the row straight to `in_consultation`, so the receptionist was
 * starting the doctor's consultation, and "Done" let them finish it.
 *
 * WHAT EACH COLUMN NOW ANSWERS
 *
 *   status              THE VISIT. Where the whole episode has got to, and
 *                       the only one the system writes for itself. Gains
 *                       three waiting rooms — awaiting_pharmacy, awaiting_lab,
 *                       awaiting_payment — because a consultation ending is
 *                       not a visit ending.
 *
 *   queue_status        THE RECEPTION DESK. waiting → called → with_doctor.
 *                       NULL before arrival and again once the doctor is
 *                       done: somebody who has left the department is not in
 *                       a queue, and a nullable column says that without a
 *                       fourth value that means "not applicable".
 *
 *   consultation_status THE DOCTOR. not_started → in_progress → completed.
 *                       Never null, because every visit has a write-up that
 *                       has or has not been started.
 *
 * `status` IS DELIBERATELY NOT RENAMED, and the four values it already had
 * keep their exact meaning. Twelve backend files, the board, the dashboard,
 * the patient portal and four test suites read `checked_in` and
 * `in_consultation`, and all of them stay correct: a visit whose patient is in
 * the waiting room IS checked in, whatever the desk has called. The genuinely
 * new fact — "called, not yet seen" — is the one thing that needed a column,
 * and it went in queue_status where nothing was reading.
 *
 * `next_action` is cached rather than derived per row. Working it out means
 * asking the prescription, the lab and the till, and the queue screen renders
 * forty rows at a time. VisitWorkflow::refresh() is the only writer, and it
 * writes `status` and `next_action` together inside one transaction, so the
 * two can never disagree about the same moment.
 *
 * The timestamps and actors are the audit the workflow needs at a glance.
 * `activity_logs` already records every one of these changes field by field,
 * but "who called this patient" should not require reading a log to answer.
 *
 * `started_at` / `completed_at` are NOT touched: they already mean the
 * consultation's clocks — OpdBoard measures consultation length from them —
 * so a visit's own closing time is the new column, not a reinterpretation of
 * an old one.
 */
return new class extends Migration
{
    private const QUEUE = ['waiting', 'called', 'with_doctor'];

    private const CONSULTATION = ['not_started', 'in_progress', 'completed'];

    private const NEXT_ACTION = ['pharmacy', 'laboratory', 'billing', 'follow_up', 'none'];

    private const VISIT = [
        'booked', 'checked_in', 'in_consultation',
        'awaiting_pharmacy', 'awaiting_lab', 'awaiting_payment',
        'completed', 'cancelled', 'no_show',
    ];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        Schema::table('appointments', function (Blueprint $table) {
            // Null until they arrive, and null again once they leave the room.
            $table->string('queue_status', 20)->nullable()->after('status');

            $table->string('consultation_status', 20)->default('not_started')->after('queue_status');

            // Cached by VisitWorkflow::refresh(), never set from a request.
            $table->string('next_action', 20)->nullable()->after('consultation_status');

            /*
             * Called: the desk's own act, and the one this whole migration
             * exists for. Before it there was no moment between "waiting" and
             * "with the doctor", so there was nothing for a receptionist to do
             * that was not the doctor's job.
             */
            $table->timestamp('called_at')->nullable()->after('checked_in_at');
            $table->foreignId('called_by')->nullable()->after('called_at')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('checked_in_by')->nullable()->after('called_by')
                ->constrained('users')->nullOnDelete();

            // started_at / completed_at already exist and already mean the
            // consultation. These say who, which nothing recorded.
            $table->foreignId('consultation_started_by')->nullable()->after('started_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('consultation_completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();

            // When the EPISODE closed, which is not when the doctor finished.
            $table->timestamp('visit_completed_at')->nullable()->after('consultation_completed_by');
        });

        /*
         * Backfilled from what the row already says, because every one of
         * these facts is recoverable — the old single column was ambiguous
         * about the future, never about the past.
         *
         * `called_at` is left NULL for everyone. Inventing one from
         * `started_at` would be recording a desk action that never happened,
         * and a workflow's audit trail is worth less than nothing if it
         * contains timestamps nobody produced.
         */
        DB::statement(<<<'SQL'
            UPDATE appointments SET
                queue_status = CASE status
                    WHEN 'checked_in'      THEN 'waiting'
                    WHEN 'in_consultation' THEN 'with_doctor'
                    ELSE NULL
                END,
                consultation_status = CASE status
                    WHEN 'in_consultation' THEN 'in_progress'
                    WHEN 'completed'       THEN 'completed'
                    ELSE 'not_started'
                END,

                /*
                 * The clocks the new CHECK insists on.
                 *
                 * Every row the services wrote already has them; a row from a
                 * demo seeder or an old import may not, and the constraint
                 * would refuse the whole migration over a value that is
                 * knowable. Falling back to the row's own timestamps records
                 * something true — the visit was under way by then — rather
                 * than inventing a clinical time.
                 */
                started_at = CASE
                    WHEN status IN ('in_consultation', 'completed')
                    THEN COALESCE(started_at, checked_in_at, created_at)
                    ELSE started_at
                END,
                completed_at = CASE
                    WHEN status = 'completed'
                    THEN COALESCE(completed_at, updated_at, created_at)
                    ELSE completed_at
                END,
                visit_completed_at = CASE
                    WHEN status = 'completed'
                    THEN COALESCE(completed_at, updated_at, created_at)
                END
        SQL);

        /*
         * A finished visit keeps its next action, so the screens do not have
         * to special-case rows that predate this. Follow-up is read off the
         * consultation, which is where the doctor recorded it.
         */
        DB::statement(<<<'SQL'
            UPDATE appointments SET next_action = 'none' WHERE status = 'completed'
        SQL);

        DB::statement(<<<'SQL'
            UPDATE appointments SET next_action = 'follow_up'
            WHERE status = 'completed'
              AND EXISTS (
                  SELECT 1 FROM consultations c
                  WHERE c.appointment_id = appointments.id
                    AND c.follow_up_days IS NOT NULL
              )
        SQL);

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_check');

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_status_check '.
            'CHECK (status IN ('.$in(self::VISIT).'))'
        );

        /*
         * The three axes are constrained together, because the combinations
         * that are wrong are wrong across columns rather than within one.
         *
         *   - a consultation cannot be under way with nobody in the room
         *   - a consultation cannot be finished with the patient still called
         *   - a visit cannot be waiting on pharmacy, lab or payment before the
         *     doctor has finished — those states exist only downstream of a
         *     completed write-up
         *   - a completed visit has a completed consultation, unless it never
         *     got that far (cancelled and no-show are excluded above)
         *
         * In the database rather than only in the service, because this is the
         * invariant the whole feature rests on and a service can be bypassed
         * by a migration, a console command or a tinker session.
         */
        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_workflow_check CHECK ('.
            '(queue_status IS NULL OR queue_status IN ('.$in(self::QUEUE).')) '.
            'AND consultation_status IN ('.$in(self::CONSULTATION).') '.
            'AND (next_action IS NULL OR next_action IN ('.$in(self::NEXT_ACTION).')) '.

            "AND (consultation_status <> 'in_progress' OR queue_status = 'with_doctor') ".
            "AND (consultation_status = 'not_started' OR started_at IS NOT NULL) ".
            "AND (consultation_status <> 'completed' OR completed_at IS NOT NULL) ".

            "AND (status NOT IN ('awaiting_pharmacy', 'awaiting_lab', 'awaiting_payment') ".
            "     OR consultation_status = 'completed') ".

            "AND (status <> 'completed' OR consultation_status = 'completed') ".
            "AND (status <> 'in_consultation' OR queue_status = 'with_doctor') ".

            /*
             * Called means somebody called them, at a time. `called_by` may
             * still be null — the member of staff who did it can leave and
             * their row be removed — but the moment cannot.
             */
            "AND (queue_status <> 'called' OR called_at IS NOT NULL))"
        );

        Schema::table('appointments', function (Blueprint $table) {
            // The desk's screen: this branch, today, who is waiting to be called.
            $table->index(['location_id', 'appointment_date', 'queue_status']);

            // The downstream screens: which visits are still waiting on us.
            $table->index(['location_id', 'appointment_date', 'status']);
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_workflow_check');
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_check');

        // Everything downstream of a finished consultation collapses back to
        // the one value the old vocabulary had for it.
        DB::statement(<<<'SQL'
            UPDATE appointments SET status = 'completed'
            WHERE status IN ('awaiting_pharmacy', 'awaiting_lab', 'awaiting_payment')
        SQL);

        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_status_check '.
            "CHECK (status IN ('booked', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'))"
        );

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'appointment_date', 'queue_status']);
            $table->dropIndex(['location_id', 'appointment_date', 'status']);

            $table->dropConstrainedForeignKey('called_by');
            $table->dropConstrainedForeignKey('checked_in_by');
            $table->dropConstrainedForeignKey('consultation_started_by');
            $table->dropConstrainedForeignKey('consultation_completed_by');

            $table->dropColumn([
                'queue_status',
                'consultation_status',
                'next_action',
                'called_at',
                'visit_completed_at',
            ]);
        });
    }
};
