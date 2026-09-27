<?php

use App\Models\Tenant\BillableService;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One visit, one invoice.
 *
 * The billing module shipped drawing a consultation invoice and leaving the
 * pharmacy to bill separately — so a patient who saw a doctor and collected
 * medicines was handed two documents for one visit. This is the schema half
 * of consolidating them:
 *
 *   1. LAB TESTS GET A PRICE. `lab_test_catalog` is a small master — name,
 *      code, price, tax — and `lab_order_items` keeps a price SNAPSHOT taken
 *      at order time, because a catalogue is edited and a bill is not. The
 *      catalogue is optional: a doctor typing "CBC" freehand still works, the
 *      line simply carries no charge.
 *
 *   2. A PHARMACY SALE CAN SAY "THE CLINIC BILLED THIS". `billed_via_invoice`
 *      is a payment_status meaning the money is owed on the visit's invoice
 *      rather than at the pharmacy till — so the counter's own reports stop
 *      counting it as outstanding, and the patient is not asked twice.
 *
 *   3. INVOICES FINALIZE. An invoice now starts as `draft` while the visit is
 *      still producing charges, and becomes `pending` when the organisation's
 *      trigger says the bill is complete. Payment is refused on a draft: the
 *      whole point of consolidating is that nobody pays before the last
 *      charge has landed.
 *
 * `finalized_at` is stamped once and kept, the way `visit_completed_at` is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
         * The tests a lab offers, and what each costs.
         *
         * Deliberately small. It is a price list, not a LOINC taxonomy —
         * reference ranges and specimen handling stay on the order item where
         * the technician writes them.
         */
        Schema::create('lab_test_catalog', function (Blueprint $table) {
            $table->id();

            // Null is the organisation's list; a row with a branch is that
            // branch's own price for the same test.
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();

            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->string('specimen', 16)->nullable();

            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);

            $table->boolean('active')->default(true);
            $table->integer('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'active']);
            $table->index('code');
        });

        DB::statement(
            'ALTER TABLE lab_test_catalog ADD CONSTRAINT lab_test_catalog_rules_check CHECK ('.
            'price >= 0 AND tax_percent >= 0 AND tax_percent <= 100)'
        );

        Schema::table('lab_order_items', function (Blueprint $table) {
            /*
             * Which catalogue entry this line came from, when it came from
             * one. Nullable forever: freehand ordering is not going away.
             */
            $table->foreignId('lab_test_catalog_id')->nullable()->after('test_code')
                ->constrained('lab_test_catalog')->nullOnDelete();

            // The price as it was on the day, not as the catalogue reads now.
            $table->decimal('price_snapshot', 12, 2)->nullable()->after('lab_test_catalog_id');
            $table->decimal('tax_percent_snapshot', 5, 2)->nullable()->after('price_snapshot');
        });

        /*
         * The pharmacy's bill can now defer to the clinic's.
         *
         * Rebuilt rather than altered because the old CHECK names the allowed
         * values inline, and Postgres has no "add a value to a check".
         */
        DB::statement('ALTER TABLE pharmacy_sales DROP CONSTRAINT pharmacy_sales_rules_check');
        DB::statement(
            'ALTER TABLE pharmacy_sales ADD CONSTRAINT pharmacy_sales_rules_check CHECK ('.
            'status IN ('.$in(PharmacySale::STATUSES).') '.
            'AND payment_status IN ('.$in(PharmacySale::PAYMENT_STATUSES).') '.
            'AND price_basis IN ('.$in(PharmacySetting::PRICE_BASES).') '.
            'AND (customer_id IS NOT NULL OR walk_in_name IS NOT NULL) '.
            'AND subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '.
            'AND total_amount >= 0 AND paid_amount >= 0)'
        );

        /*
         * A registration fee is its own kind of billable service — charged
         * once per patient at the desk, not once per attendance. Rebuilt for
         * the same reason the pharmacy check above is.
         */
        DB::statement('ALTER TABLE billable_services DROP CONSTRAINT billable_services_rules_check');
        DB::statement(
            'ALTER TABLE billable_services ADD CONSTRAINT billable_services_rules_check CHECK ('.
            'kind IN ('.$in(BillableService::KINDS).') '.
            'AND default_price >= 0 AND tax_percent >= 0 AND tax_percent <= 100)'
        );

        Schema::table('invoices', function (Blueprint $table) {
            /*
             * When the bill was declared complete. Null while a visit is still
             * producing charges; payment is refused until it is set.
             */
            $table->timestamp('finalized_at')->nullable()->after('trigger');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')
                ->constrained('users')->restrictOnDelete();

            /*
             * What KIND of bill this is. A visit invoice consolidates
             * everything the visit produced; a registration receipt and a
             * manual bill stand alone — neither belongs to a visit, so
             * neither should be swept into one.
             */
            $table->string('kind', 16)->default('visit')->after('trigger');
        });

        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_kind_check CHECK ('.
            "kind IN ('visit', 'registration', 'manual'))"
        );

        /*
         * One live VISIT invoice per appointment, enforced rather than hoped
         * for. `BillingTriggerResolver` looks one up before drawing, but two
         * events landing in the same millisecond — a lab result and a
         * dispensing — would both find none. This is what makes the loser of
         * that race retry against the winner's row instead of drawing a
         * second invoice.
         *
         * Registration and manual invoices are excluded: a patient may have
         * any number of those.
         */
        DB::statement(
            'CREATE UNIQUE INDEX invoices_one_live_per_visit ON invoices (appointment_id) '.
            "WHERE appointment_id IS NOT NULL AND kind = 'visit' AND status <> 'cancelled'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_one_live_per_visit');
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_kind_check');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['finalized_at', 'finalized_by', 'kind']);
        });

        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->dropColumn(['lab_test_catalog_id', 'price_snapshot', 'tax_percent_snapshot']);
        });

        Schema::dropIfExists('lab_test_catalog');
    }
};
