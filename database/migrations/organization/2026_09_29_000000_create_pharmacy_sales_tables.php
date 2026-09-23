<?php

use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacySetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selling: the bill, its lines, and what was paid against it.
 *
 * One document for both ways the pharmacy sells. A counter sale has no
 * prescription; a clinic dispensing is the same sale carrying
 * `prescription_id`, with each line pointing at the prescribed line it
 * fulfils. Two nullable columns are the whole of the clinic integration,
 * which is why a standalone medical store never touches prescription code.
 *
 * What the bill says is copied onto it, never looked up again:
 *
 *   - the item's name and HSN code, because a catalogue entry is edited
 *   - the batch number and expiry, because a batch is emptied and reused
 *   - the tax rate and how it was treated, because a rate changes
 *   - the cost of what was sold, so profit is what it was on the day
 *
 * Nothing here is ever deleted. A mistake is cancelled, which puts the stock
 * back as correcting ledger rows and leaves the bill on the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
         * The bill number takes its prefix from the organisation's settings,
         * so a store billing as "BILL" gets BILL-00001. Read at INSERT by the
         * database, from the same sequence for everyone, so two counters
         * selling at the same moment can never be handed one number twice.
         */
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hms_sale_number() RETURNS text AS $fn$
DECLARE
    prefix text;
BEGIN
    SELECT invoice_prefix INTO prefix FROM pharmacy_settings LIMIT 1;

    RETURN hms_document_number(coalesce(prefix, 'INV'), 'sale_number_seq');
END;
$fn$ LANGUAGE plpgsql VOLATILE;
SQL);

        Schema::create('pharmacy_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number', 24)->default(DB::raw('hms_sale_number()'));

            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            /*
             * Who bought it. A registered customer, or nobody — a medical
             * store sells to whoever walks in, and making every buyer a
             * patient record would be a filing cabinet of one-line entries.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('walk_in_name', 120)->nullable();
            $table->string('walk_in_phone', 20)->nullable();

            // The clinic integration, and all of it.
            $table->foreignId('prescription_id')->nullable()->constrained('prescriptions')->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->restrictOnDelete();

            $table->timestamp('sale_date');
            $table->string('status', 12)->default(PharmacySale::COMPLETED);

            // How this bill was priced, copied from the settings as they were.
            $table->string('price_basis', 10);
            $table->boolean('prices_include_tax');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);

            $table->string('payment_status', 10)->default(PharmacySale::UNPAID);

            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_name', 191)->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();

            $table->unique('sale_number');
            $table->index(['pharmacy_store_id', 'sale_date']);
            $table->index(['customer_id', 'sale_date']);
            $table->index('prescription_id');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sales ADD CONSTRAINT pharmacy_sales_rules_check CHECK ('.
            'status IN ('.$in(PharmacySale::STATUSES).') '.
            'AND payment_status IN ('.$in(PharmacySale::PAYMENT_STATUSES).') '.
            'AND price_basis IN ('.$in(PharmacySetting::PRICE_BASES).') '.
            // Somebody bought it: a name at the counter, or a record.
            'AND (customer_id IS NOT NULL OR walk_in_name IS NOT NULL) '.
            'AND subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '.
            'AND total_amount >= 0 AND paid_amount >= 0)'
        );

        // A retried request answers with the bill the first one made.
        DB::statement(
            'CREATE UNIQUE INDEX pharmacy_sales_idempotency_unique ON pharmacy_sales (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        Schema::create('pharmacy_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_sale_id')->constrained('pharmacy_sales')->restrictOnDelete();

            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained('medicine_batches')->restrictOnDelete();

            // The prescribed line this fulfils, when the sale came from one.
            $table->foreignId('prescription_item_id')->nullable()
                ->constrained('prescription_items')->restrictOnDelete();

            // What the bill says, kept as it was said.
            $table->string('item_name_snapshot', 191);
            $table->string('hsn_code_snapshot', 10)->nullable();
            $table->string('batch_number_snapshot', 60);
            $table->date('expiry_date_snapshot');

            $table->integer('quantity');

            $table->decimal('mrp', 12, 2);
            $table->decimal('unit_price', 12, 2);

            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);

            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);

            $table->decimal('line_total', 12, 2);

            // What it cost the store, so a profit report never re-derives it.
            $table->decimal('unit_cost', 12, 2)->nullable();

            $table->timestamps();

            $table->index('medicine_id');
            $table->index('medicine_batch_id');
            $table->index('prescription_item_id');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sale_items ADD CONSTRAINT pharmacy_sale_items_rules_check CHECK ('.
            'quantity > 0 '.
            'AND unit_price >= 0 AND mrp >= 0 '.
            'AND discount_percent >= 0 AND discount_percent <= 100 '.
            'AND discount_amount >= 0 '.
            'AND tax_rate >= 0 AND tax_rate <= 100 '.
            'AND taxable_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)'
        );

        Schema::create('pharmacy_sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_sale_id')->constrained('pharmacy_sales')->restrictOnDelete();

            $table->string('method', 14);
            $table->decimal('amount', 12, 2);

            // A UPI reference, the last four of a card, a cheque number.
            $table->string('reference', 60)->nullable();

            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('pharmacy_sale_id');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sale_payments ADD CONSTRAINT pharmacy_sale_payments_rules_check CHECK ('.
            'method IN ('.$in(PharmacySalePayment::METHODS).') '.
            'AND amount > 0)'
        );

        /*
         * A bill, its lines and its payments are financial records: they are
         * cancelled, never deleted. The ledger has the same guard, and for
         * the same reason.
         */
        foreach (['pharmacy_sales', 'pharmacy_sale_items', 'pharmacy_sale_payments'] as $table) {
            DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION {$table}_no_delete() RETURNS trigger AS \$fn\$
BEGIN
    RAISE EXCEPTION '% is a financial record and cannot be deleted. Cancel it instead.', TG_TABLE_NAME;
END;
\$fn\$ LANGUAGE plpgsql;

CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
FOR EACH ROW EXECUTE FUNCTION {$table}_no_delete();
SQL);
        }
    }

    public function down(): void
    {
        foreach (['pharmacy_sale_payments', 'pharmacy_sale_items', 'pharmacy_sales'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
            DB::statement("DROP FUNCTION IF EXISTS {$table}_no_delete()");
            Schema::dropIfExists($table);
        }

        DB::statement('DROP FUNCTION IF EXISTS hms_sale_number()');
    }
};
