<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second kind of thread: the desk's own, with a patient, apart from
 * whichever doctor that patient also messages.
 *
 * `doctor_id` becomes nullable and `branch_id` arrives beside it — exactly
 * one of the two is ever set, which is what makes a row either a doctor's
 * thread or the desk's. The read/delivered pointer columns stay as they are
 * and are reused rather than renamed: on a desk thread they hold the desk's
 * pointer where they held the doctor's, the same column meaning "whichever
 * side on staff's end this row belongs to".
 *
 * `unique(branch_id, customer_id)` finds the desk's one thread with a patient
 * the same way `unique(doctor_id, customer_id)` already finds a doctor's —
 * Postgres never collides two NULLs, so a doctor's thread (branch_id null)
 * and the desk's (doctor_id null) sit in each other's blind spot without a
 * partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('doctor_id')->constrained('locations')->cascadeOnDelete();

            $table->unique(['branch_id', 'customer_id']);
            $table->index(['branch_id', 'last_message_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'customer_id']);
            $table->dropIndex(['branch_id', 'last_message_at']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable(false)->change();
        });
    }
};
