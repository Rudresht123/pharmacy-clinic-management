<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When and where a doctor sits.
 *
 * This is the only relationship between a doctor and a branch, which is why
 * `doctors` has no location column: the answer to "does Dr. Sharma work at
 * Delhi" is a row here, and it comes with the day and the hours attached.
 *
 * An availability window, NOT a set of appointments. `slot_minutes` is an
 * input to a generator: 10:00–13:00 at 15 minutes means twelve slots are
 * *computable*, never that twelve rows exist. Nothing materialises a slot
 * table — free slots are worked out at read time as the generated windows
 * minus what is already booked minus that date's exceptions. Persisting them
 * would mean regenerating on every schedule edit and reconciling bookings
 * against rows that had moved underneath them.
 *
 * There is deliberately no unique constraint on (doctor, location, weekday):
 * a doctor who sits 10–1 and again 5–8 is two rows, and that is the normal
 * case, not a mistake. What must not happen — the same doctor in two places
 * at once — is a time overlap, which no single-column constraint can express
 * and which the request validates across the whole submitted week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();

            // Remove a doctor and their sittings go with them: a schedule
            // for nobody is not a record anybody would want to keep.
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();

            /*
             * Restricted, unlike `users.location_id` and
             * `customers.registered_location_id`, which null when a branch
             * closes. Those rows survive without a branch; a sitting with no
             * place does not mean anything. Locations soft-delete, so this
             * never fires in normal use — it guards a hard delete from a
             * console session.
             */
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            // "Morning OPD" — what the queue board and the audit log call
            // this sitting, instead of "DoctorSchedule #7".
            $table->string('name', 60)->nullable();

            // Monday 0 … Sunday 6. See App\Support\Opd\Weekday, which is the
            // only place a date is turned into this number.
            $table->smallInteger('weekday');

            $table->time('starts_at');
            $table->time('ends_at');

            $table->smallInteger('slot_minutes')->default(15);

            // How many walk-ins this sitting will take. Null is uncapped.
            $table->smallInteger('max_walkins')->nullable();

            // Pause a sitting for a month without losing it.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // "This doctor's sittings on this day" — the booking screen.
            $table->index(['doctor_id', 'weekday']);

            // "Who sits at this branch today" — the queue board.
            $table->index(['location_id', 'weekday']);
        });

        DB::statement(
            'ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_weekday_check '.
            'CHECK (weekday BETWEEN 0 AND 6)'
        );

        DB::statement(
            'ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_hours_check '.
            'CHECK (ends_at > starts_at)'
        );

        DB::statement(
            'ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_slot_check '.
            'CHECK (slot_minutes > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
