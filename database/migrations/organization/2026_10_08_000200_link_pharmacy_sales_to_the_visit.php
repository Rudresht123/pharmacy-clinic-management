<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which visit a bill belongs to.
 *
 * `pharmacy_sales.prescription_id` already says which prescription was
 * dispensed, and a prescription already knows its appointment — so this column
 * is reachable through a join and is stored anyway, for one reason: the visit
 * workflow asks "is anything on this visit unpaid" on every queue refresh, for
 * every row on the screen. Through the join that is a three-table read per
 * row; here it is an index lookup.
 *
 * It is also the column a bill that is NOT a dispensing can use. A
 * consultation fee taken at the desk, or dressings sold against a visit with
 * no prescription behind them, have an appointment and no prescription — and
 * without this there was nowhere to say so.
 *
 * NULLABLE AND IT STAYS NULLABLE. A standalone medical store rings up walk-ins
 * all day and has no appointments at all; that is the same reason
 * `prescription_id` is nullable, and it is what keeps the pharmacy sellable
 * without the clinic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('prescription_id')
                ->constrained('appointments')->restrictOnDelete();
        });

        // Backfilled through the prescription, which is where the link has
        // been living implicitly since dispensing was first wired up.
        DB::statement(<<<'SQL'
            UPDATE pharmacy_sales SET appointment_id = p.appointment_id
            FROM prescriptions p
            WHERE p.id = pharmacy_sales.prescription_id
        SQL);

        Schema::table('pharmacy_sales', function (Blueprint $table) {
            // "What does this visit still owe" — the only question asked of it.
            $table->index(['appointment_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropIndex(['appointment_id', 'payment_status']);
            $table->dropConstrainedForeignKey('appointment_id');
        });
    }
};
