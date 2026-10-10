<?php

use App\Models\Tenant\PharmacySale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for `billed_via_invoice` in `pharmacy_sales.payment_status`.
 *
 * The consolidate-visit-billing migration added the value to the CHECK, but
 * the column stayed varchar(10) — and 'billed_via_invoice' is eighteen
 * characters. Nothing noticed, because the code that writes it (a dispensing
 * billed onto its visit's invoice) never ran: the billing trigger it hangs off
 * was never populated by the container. Once it was, every visit-linked
 * dispensing failed on "value too long".
 *
 * Widened, not shortened: the value is a public contract already named in the
 * CHECK and in PharmacySale::PAYMENT_STATUSES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->string('payment_status', 20)->default(PharmacySale::UNPAID)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->string('payment_status', 10)->default(PharmacySale::UNPAID)->change();
        });
    }
};
