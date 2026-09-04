<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a sitting starts and stops applying.
 *
 * "Dr. Sharma sits on Mondays from next month" and "she stopped visiting
 * Delhi in March" are both ordinary, and without these the only way to say
 * either is to delete the sitting — which loses the record of when it ran
 * and orphans every appointment that remembers it.
 *
 * Both nullable, and null is the common case: no start means it has always
 * applied, no end means it applies until somebody says otherwise. A doctor
 * on leave for a fortnight is not this — that is a schedule exception, which
 * is about a date rather than about the sitting itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('is_active');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        DB::statement(
            'ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_effective_check '.
            'CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE doctor_schedules DROP CONSTRAINT IF EXISTS doctor_schedules_effective_check'
        );

        Schema::table('doctor_schedules', function (Blueprint $table) {
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};
