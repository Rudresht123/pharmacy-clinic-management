<?php

use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\BillingSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing: the invoice, its lines, the money against it, and what it is drawn
 * from.
 *
 * A generic bill. `invoice_items.source_type` names what the line represents
 * (`consultation`, `pharmacy_sale_item`, `lab_test`, `procedure`, `service`,
 * `custom`) and `source_id` points at the row when there is one. That is the
 * whole modularity: a clinic with no pharmacy raises a consultation-only bill,
 * a clinic with one adds a `pharmacy_sale_item` line to the same invoice, and
 * the same schema carries both.
 *
 * WHAT THE BILL SAYS IS COPIED ONTO IT, never looked up again — a service can
 * be renamed, a price changed, the tax rules edited, and last week's invoice
 * has to still read as it was.
 *
 * BILLING_SETTINGS is one row per organisation. `default_trigger` is what
 * decides when a visit becomes a bill; a change to it only affects future
 * visits (an already-drawn invoice keeps the trigger it was drawn under).
 *
 * BILLABLE_SERVICES is an organisation catalogue — the doctor sitting fee, an
 * injection charge, a dressing — read the same way the medicine master is:
 * everybody's invoice pulls from the same list, so `INV-00234` at Delhi means
 * the same thing as `INV-00234` at Lucknow.
 *
 * Nothing here is ever deleted. A mistake is cancelled, which sets `status`
 * and stamps who did it and why; the row stays for the ledger. The Postgres
 * DELETE trigger below is the same guard PharmacySale has and for the same
 * reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
         * Invoice numbering, per organisation.
         *
         * A Postgres sequence + `hms_document_number()` produce the printed
         * number, same as the pharmacy's own. The prefix is read from
         * `billing_settings.invoice_prefix` inside the function, so an
         * organisation billing as "CLINIC" gets CLINIC-00001. Sequences are
         * not rolled back with a transaction — a failed save leaves a gap —
         * which is the normal behaviour of a document register.
         */
        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq AS bigint START WITH 1 MINVALUE 1 NO CYCLE');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hms_invoice_number() RETURNS text AS $fn$
DECLARE
    prefix text;
BEGIN
    SELECT invoice_prefix INTO prefix FROM billing_settings LIMIT 1;

    RETURN hms_document_number(coalesce(prefix, 'INV'), 'invoice_number_seq');
END;
$fn$ LANGUAGE plpgsql VOLATILE;
SQL);

        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();

            /*
             * When a visit becomes a bill.
             *
             *   checkin       an invoice at check-in — advance-payment clinics
             *   consultation  when the doctor finishes — the common case
             *   pharmacy      after dispensing — a shop-forward flow
             *   laboratory    after the lab signs off
             *   manual        never automatically; the counter draws each one
             *
             * `consultation` is the default a clinic starts on, because the
             * one thing every visit has is a doctor. The frontend hides
             * triggers whose module is not enabled — a pharmacy trigger on a
             * clinic that does not sell medicine would never fire — but the
             * column carries the raw value, and `BillingTriggerResolver`
             * refuses at write time.
             */
            $table->string('default_trigger', 20)->default(BillingSetting::TRIGGER_CONSULTATION);

            /*
             * What the till accepts. A list rather than one flag per method
             * because a clinic that takes UPI and cash but not card wants to
             * say so once, not toggle three booleans.
             */
            $table->jsonb('payment_methods')->default(DB::raw("'[\"cash\",\"upi\",\"card\"]'::jsonb"));

            /*
             * Whether an invoice may be left unpaid or partially paid — the
             * counter refuses if this says full-payment-only.
             */
            $table->string('payment_behaviour', 12)->default(BillingSetting::PAYMENT_FULL);

            $table->boolean('prices_include_tax')->default(false);
            $table->decimal('default_tax_percent', 5, 2)->default(0);

            /*
             * Whether staff may edit a generated invoice's lines before
             * payment — a clinic that trusts the doctor's fee schedule turns
             * this off, so the invoice reads as the software drew it.
             */
            $table->boolean('allow_edit_before_payment')->default(true);

            $table->string('invoice_prefix', 12)->default('INV');
            $table->string('currency_code', 3)->default('INR');
            $table->string('currency_symbol', 4)->default('₹');

            $table->text('terms')->nullable();
            $table->text('footer')->nullable();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_rules_check CHECK ('.
            'default_trigger IN ('.$in(BillingSetting::TRIGGERS).') '.
            'AND payment_behaviour IN ('.$in(BillingSetting::PAYMENT_BEHAVIOURS).') '.
            'AND default_tax_percent >= 0 AND default_tax_percent <= 100)'
        );

        // One row per organisation, which is one row per tenant DB — enforce it.
        DB::statement('CREATE UNIQUE INDEX billing_settings_singleton ON billing_settings ((true))');

        Schema::create('billable_services', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable: null is the organisation default that every branch
             * inherits; a row with a branch is that branch's own override, and
             * a branch that has no override reads the null row.
             */
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();

            $table->string('name', 120);
            $table->string('code', 24)->nullable();

            /*
             * What kind of thing this service is — `consultation` (the doctor
             * sitting fee — used by the automatic trigger to pick a price)
             * `procedure` `service` `custom`. `pharmacy` and `lab` are
             * deliberately absent: those lines are drawn from the pharmacy sale
             * or the lab order, not chosen from a catalogue.
             */
            $table->string('kind', 16)->default('service');

            $table->decimal('default_price', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);

            $table->boolean('active')->default(true);
            $table->integer('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'active']);
            $table->index('kind');
        });

        DB::statement(
            'ALTER TABLE billable_services ADD CONSTRAINT billable_services_rules_check CHECK ('.
            "kind IN ('consultation', 'procedure', 'service', 'custom') ".
            'AND default_price >= 0 AND tax_percent >= 0 AND tax_percent <= 100)'
        );

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 32)->default(DB::raw('hms_invoice_number()'));

            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            /*
             * Nullable customer — a walk-in for a certificate or a report
             * counter may not be on the register — same shape as
             * `pharmacy_sales`. Every OPD invoice will carry one.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('walk_in_name', 120)->nullable();
            $table->string('walk_in_phone', 20)->nullable();

            /*
             * The visit this invoice is for, when there is one. Both nullable:
             * a manual invoice (drawn by the counter without a consultation
             * behind it) has neither.
             */
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->restrictOnDelete();

            $table->timestamp('invoice_date');

            $table->string('status', 16)->default(Invoice::STATUS_PENDING);
            $table->string('trigger', 20);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);

            $table->string('payment_status', 12)->default(Invoice::UNPAID);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_name', 191)->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();

            $table->unique('invoice_number');
            $table->index(['location_id', 'invoice_date']);
            $table->index(['customer_id', 'invoice_date']);
            $table->index('appointment_id');
        });

        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_rules_check CHECK ('.
            'status IN ('.$in(Invoice::STATUSES).') '.
            'AND payment_status IN ('.$in(Invoice::PAYMENT_STATUSES).') '.
            'AND trigger IN ('.$in(BillingSetting::TRIGGERS).') '.
            'AND (customer_id IS NOT NULL OR walk_in_name IS NOT NULL) '.
            'AND subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '.
            'AND total_amount >= 0 AND paid_amount >= 0)'
        );

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            /*
             * What this line is, and where it came from — the whole point of
             * the generic bill. `source_type` and `source_id` together are
             * how the software knows a consultation has already been billed
             * (see the partial unique index below).
             */
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('billable_service_id')->nullable()
                ->constrained('billable_services')->nullOnDelete();

            // What the bill says, kept as it was said.
            $table->string('description', 191);

            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2);

            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);

            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);

            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->index('invoice_id');
            $table->index(['source_type', 'source_id']);
        });

        DB::statement(
            'ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_rules_check CHECK ('.
            "source_type IN ('consultation', 'pharmacy_sale_item', 'lab_test', 'procedure', 'service', 'custom') ".
            'AND quantity > 0 '.
            'AND unit_price >= 0 '.
            'AND discount_percent >= 0 AND discount_percent <= 100 '.
            'AND discount_amount >= 0 '.
            'AND tax_percent >= 0 AND tax_percent <= 100 '.
            'AND tax_amount >= 0 AND line_total >= 0)'
        );

        /*
         * Idempotency by source: the same consultation cannot be billed twice
         * on the same invoice. Two separate invoices for the same consultation
         * is another problem, prevented in `BillingService` (which checks for
         * any live invoice against the consultation before drawing one), but
         * the row-level guard here catches a second attempt to add the same
         * source to an invoice already carrying it.
         */
        DB::statement(
            'CREATE UNIQUE INDEX invoice_items_source_unique ON invoice_items '.
            '(invoice_id, source_type, source_id) WHERE source_id IS NOT NULL'
        );

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            $table->string('method', 14);
            $table->decimal('amount', 12, 2);

            // A UPI reference, the last four of a card, a cheque number.
            $table->string('reference', 60)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            // A refund undoes a payment; the row it undoes is stamped here.
            $table->boolean('is_refund')->default(false);
            $table->foreignId('refunds_payment_id')->nullable()
                ->constrained('invoice_payments')->restrictOnDelete();

            $table->timestamps();

            $table->index('invoice_id');
        });

        DB::statement(
            'ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_rules_check CHECK ('.
            'method IN ('.$in(InvoicePayment::METHODS).') '.
            /* A payment is a positive amount; a refund carries a positive
               amount too, and `is_refund` says which direction it moved. */
            'AND amount > 0 '.
            'AND (is_refund = false OR refunds_payment_id IS NOT NULL))'
        );

        /*
         * A bill, its lines and its payments are financial records: they are
         * cancelled, never deleted. The pharmacy has the same guard, and for
         * the same reason.
         */
        foreach (['invoices', 'invoice_items', 'invoice_payments'] as $table) {
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
        foreach (['invoice_payments', 'invoice_items', 'invoices'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
            DB::statement("DROP FUNCTION IF EXISTS {$table}_no_delete()");
            Schema::dropIfExists($table);
        }

        Schema::dropIfExists('billable_services');
        Schema::dropIfExists('billing_settings');

        DB::statement('DROP FUNCTION IF EXISTS hms_invoice_number()');
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
    }
};
