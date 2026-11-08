<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The doctor's note on one test — "Fasting", "Repeat after 2 hours".
 *
 * Its own column rather than `notes`, which is the bench's: what the
 * technician writes about a result, or why a test was cancelled. A doctor's
 * instruction must not be overwritten by either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->string('instructions', 191)->nullable()->after('specimen');
        });
    }

    public function down(): void
    {
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }
};
