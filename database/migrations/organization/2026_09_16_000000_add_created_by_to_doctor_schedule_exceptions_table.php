<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who cancelled the clinic.
 *
 * A doctor's Wednesday disappearing is the kind of thing a practice argues
 * about a fortnight later, and "the system says it was cancelled" is not an
 * answer. The activity log already holds it, but a log is where you go to
 * investigate; the list of upcoming changes is where somebody notices, and the
 * name belongs there.
 *
 * Nullable, and nulls on delete: the exceptions already in the table were
 * written before anybody was recorded, and a staff member leaving must not
 * take their cancellations with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('organization')->table('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('organization')->table('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
