<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a doctor charges at one branch.
 *
 * `doctors.default_consultation_fee` was always named as the fallback, not
 * the price — a consultant who costs ₹400 at one branch and ₹600 at another
 * had nowhere to say so. The posting is where "this doctor at this branch"
 * already lives, so the price for that pair lives beside it.
 *
 * Null means "the doctor's default", not "free": a branch that charges
 * nothing sets 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_locations', function (Blueprint $table) {
            $table->decimal('consultation_fee', 10, 2)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_locations', function (Blueprint $table) {
            $table->dropColumn('consultation_fee');
        });
    }
};
