<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happens on one particular date that the weekly pattern does not say.
 *
 * The weekly schedule is what usually happens. This is the exception:
 * leave, a public holiday, hours moved for one morning, an extra Sunday
 * clinic. Without it the only way to record a doctor's fortnight off is to
 * delete their sittings and put them back afterwards, which loses the
 * pattern and orphans anything that referenced it.
 *
 * Three kinds, and they are genuinely different rather than one flag:
 *
 *   unavailable    — nothing happens. With a schedule, that one sitting is
 *                    cancelled; without one, the whole day is off.
 *   changed_hours  — a named sitting runs at different times that day.
 *   extra_session  — a sitting that is not in the weekly pattern at all.
 *
 * Availability is derived: the weekly sittings for that weekday, minus what
 * is cancelled, with changed hours applied, plus anything extra. Nothing is
 * materialised — see App\Services\Opd\AvailabilityService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();

            /*
             * Which sitting this is about. Null means the whole day, which is
             * what leave usually is. Nulled rather than cascaded if the
             * sitting is later deleted: the fact that somebody was away that
             * day is still true, and losing it would silently reopen a date
             * the doctor was never available on.
             */
            $table->foreignId('doctor_schedule_id')
                ->nullable()
                ->constrained('doctor_schedules')
                ->nullOnDelete();

            // Only an extra session needs its own place; the others inherit
            // the sitting's, or affect the whole day wherever it was.
            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->restrictOnDelete();

            $table->date('date');

            $table->string('type', 20);

            // Required for changed_hours and extra_session, meaningless for
            // unavailable — enforced by the request, which knows the type.
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            $table->smallInteger('slot_minutes')->nullable();
            $table->smallInteger('max_walkins')->nullable();

            $table->string('reason', 191)->nullable();

            $table->timestamps();

            // "What is different for this doctor on this date" — the question
            // availability asks for every doctor it resolves.
            $table->index(['doctor_id', 'date']);

            // "What is different at this branch today" — the day view.
            $table->index(['location_id', 'date']);
        });

        DB::statement(
            'ALTER TABLE doctor_schedule_exceptions ADD CONSTRAINT doctor_schedule_exceptions_type_check '.
            "CHECK (type IN ('unavailable', 'changed_hours', 'extra_session'))"
        );

        DB::statement(
            'ALTER TABLE doctor_schedule_exceptions ADD CONSTRAINT doctor_schedule_exceptions_hours_check '.
            'CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedule_exceptions');
    }
};
