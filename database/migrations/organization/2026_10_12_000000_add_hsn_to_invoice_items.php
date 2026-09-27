<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax code a printed invoice has to carry.
 *
 * A GST invoice names the HSN or SAC code of what it charged for, and the
 * clinic's own invoice had nowhere to keep one — the code lived on
 * `pharmacy_sale_items` and stopped there, so a consolidated bill printed a
 * blank column.
 *
 * A SNAPSHOT, like every other printed figure on the row: the medicine
 * master is edited, and a bill reprinted next year must still show the code
 * it was actually raised under. Nullable forever, because a consultation has
 * no HSN and inventing one on a tax document is worse than leaving it blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('hsn_code', 10)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('hsn_code');
        });
    }
};
