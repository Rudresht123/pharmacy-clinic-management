<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each department bills for itself.
 *
 * The billing module was built to fold a visit's consultation, lab tests and
 * medicines into one consolidated invoice. That path never ran — the trigger
 * resolver was never resolved in a real request — and the organisation has
 * decided against it: the OPD bills the consultation, the lab bills its own
 * order, and the pharmacy keeps its own till. This is the schema half.
 *
 *   1. LAB TIMING. `billing_settings.lab_trigger` says when a lab order
 *      becomes a bill — when it is ordered, when the lab signs it off, or
 *      only when somebody bills it by hand. Every existing organisation
 *      starts on `manual`, so nothing is billed until they choose.
 *      `default_trigger` is left exactly as each organisation set it; it is
 *      now read as the OPD timing, and a value that is no longer an OPD
 *      timing is read as "after the consultation" without being rewritten.
 *
 *   2. A LAB INVOICE. `invoices.kind = 'lab'`, pointing at its order, one
 *      live invoice per order — enforced by a partial unique index, the same
 *      shape as one live visit invoice per appointment.
 *
 *   3. WHAT A BILL WAS PRICED UNDER. `currency_code` and
 *      `prices_include_tax` are copied onto the invoice when it is drawn, so
 *      changing the settings never re-reads an issued bill. Existing invoices
 *      are given the settings as they stand now — the only record there is.
 *
 *   4. REFUNDS CREDIT THE BILL. `refunded_amount` is money handed back, and
 *      it reduces what the bill is for: a bill paid in full and refunded in
 *      full owes nothing, rather than asking for the whole amount again.
 *      Existing invoices keep 0 and their status — nothing already recorded
 *      is reinterpreted; the next payment or refund on one recomputes it.
 *
 *   5. A PAYMENT TAKEN ONCE. `invoice_payments.idempotency_key` — a
 *      double-tapped "take payment" answers with the payment the first tap
 *      recorded.
 *
 *   6. AN UNPAID BILL CAN BE CORRECTED. The guard that refuses deleting an
 *      invoice line now steps aside for exactly one case: the edit path has
 *      said so (`SET LOCAL hms.invoice_rewrite`), the invoice is not
 *      cancelled, and no money has ever moved against it. A line on a bill
 *      somebody has paid against is as undeletable as before.
 *
 *   7. A FOLLOW-UP FEE. `billable_services.kind = 'follow_up'` is charged in
 *      place of the doctor's fee when the patient returns inside the
 *      follow-up period the doctor set.
 *
 * ADDITIVE. Columns are added, CHECK constraints are rebuilt to allow a
 * superset of what they allowed, and `invoices.status` is widened. No row is
 * deleted, no issued number changes, no stored setting is overwritten.
 */
return new class extends Migration
{
    private const STATUSES = ['draft', 'pending', 'partially_paid', 'paid', 'cancelled', 'refunded', 'partially_refunded'];

    private const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];

    /** Everything an invoice may record as having produced it — old values kept for old rows. */
    private const TRIGGERS = ['checkin', 'consultation', 'pharmacy', 'laboratory', 'manual', 'lab_order'];

    private const KINDS = ['visit', 'registration', 'manual', 'lab'];

    private const LAB_TRIGGERS = ['lab_order', 'laboratory', 'manual'];

    private const SERVICE_KINDS = ['registration', 'consultation', 'follow_up', 'procedure', 'service', 'custom'];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
         * 1 · When a lab order becomes a bill. `manual` for everybody who
         * already exists: a clinic that has never been billed for tests must
         * not start being billed by a deploy.
         */
        Schema::table('billing_settings', function (Blueprint $table) {
            $table->string('lab_trigger', 20)->default('manual')->after('default_trigger');
        });

        DB::statement(
            'ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_lab_trigger_check '.
            'CHECK (lab_trigger IN ('.$in(self::LAB_TRIGGERS).'))'
        );

        // 7 · The follow-up fee is a kind of service.
        DB::statement('ALTER TABLE billable_services DROP CONSTRAINT billable_services_rules_check');
        DB::statement(
            'ALTER TABLE billable_services ADD CONSTRAINT billable_services_rules_check CHECK ('.
            'kind IN ('.$in(self::SERVICE_KINDS).') '.
            'AND default_price >= 0 AND tax_percent >= 0 AND tax_percent <= 100)'
        );

        /*
         * 2–4 · The invoice. `partially_refunded` is eighteen characters, so
         * the status column is widened first — a widening never rewrites a
         * row.
         */
        DB::statement('ALTER TABLE invoices ALTER COLUMN status TYPE varchar(20)');

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('lab_order_id')->nullable()->after('consultation_id')
                ->constrained('lab_orders')->restrictOnDelete();

            $table->string('currency_code', 3)->nullable()->after('kind');
            $table->boolean('prices_include_tax')->nullable()->after('currency_code');

            $table->decimal('refunded_amount', 12, 2)->default(0)->after('paid_amount');

            $table->index('lab_order_id');
        });

        // The settings as they stand are the only record of what these were drawn under.
        DB::statement(<<<'SQL'
UPDATE invoices SET
    currency_code = coalesce((SELECT currency_code FROM billing_settings LIMIT 1), 'INR'),
    prices_include_tax = coalesce((SELECT prices_include_tax FROM billing_settings LIMIT 1), false)
WHERE currency_code IS NULL
SQL);

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_rules_check');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_rules_check CHECK ('.
            'status IN ('.$in(self::STATUSES).') '.
            'AND payment_status IN ('.$in(self::PAYMENT_STATUSES).') '.
            'AND trigger IN ('.$in(self::TRIGGERS).') '.
            'AND (customer_id IS NOT NULL OR walk_in_name IS NOT NULL) '.
            'AND subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '.
            'AND total_amount >= 0 AND paid_amount >= 0 AND refunded_amount >= 0)'
        );

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_kind_check');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_kind_check CHECK ('.
            'kind IN ('.$in(self::KINDS).') '.
            // A lab bill always says which order it is for.
            "AND (kind <> 'lab' OR lab_order_id IS NOT NULL))"
        );

        /*
         * One live lab invoice per order. Two events racing to bill the same
         * order — a double-clicked "Bill tests", a completion landing as the
         * desk bills by hand — leave the loser to read the winner's row.
         */
        DB::statement(
            'CREATE UNIQUE INDEX invoices_one_live_per_lab_order ON invoices (lab_order_id) '.
            "WHERE lab_order_id IS NOT NULL AND kind = 'lab' AND status <> 'cancelled'"
        );

        // 5 · One payment per tap.
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX invoice_payments_idempotency_unique ON invoice_payments (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        /*
         * 6 · The line guard, with its one door.
         *
         * The setting is transaction-local and BillingService::rewrite() is
         * the only thing that sets it, so a stray DELETE — a console session,
         * a future bug — is refused exactly as before. Even through the door,
         * a line on a cancelled bill, or on a bill any money has moved
         * against, stays where it is.
         */
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_no_delete() RETURNS trigger AS $fn$
BEGIN
    IF coalesce(current_setting('hms.invoice_rewrite', true), '') = 'on'
        AND EXISTS (SELECT 1 FROM invoices WHERE id = OLD.invoice_id AND status <> 'cancelled')
        AND NOT EXISTS (SELECT 1 FROM invoice_payments WHERE invoice_id = OLD.invoice_id)
    THEN
        RETURN OLD;
    END IF;

    RAISE EXCEPTION '% is a financial record and cannot be deleted. Cancel it instead.', TG_TABLE_NAME;
END;
$fn$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_no_delete() RETURNS trigger AS $fn$
BEGIN
    RAISE EXCEPTION '% is a financial record and cannot be deleted. Cancel it instead.', TG_TABLE_NAME;
END;
$fn$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP INDEX IF EXISTS invoice_payments_idempotency_unique');

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });

        DB::statement('DROP INDEX IF EXISTS invoices_one_live_per_lab_order');

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_kind_check');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_kind_check CHECK ('.
            "kind IN ('visit', 'registration', 'manual'))"
        );

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_rules_check');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_rules_check CHECK ('.
            'status IN ('.$in(array_diff(self::STATUSES, ['partially_refunded'])).') '.
            'AND payment_status IN ('.$in(self::PAYMENT_STATUSES).') '.
            'AND trigger IN ('.$in(array_diff(self::TRIGGERS, ['lab_order'])).') '.
            'AND (customer_id IS NOT NULL OR walk_in_name IS NOT NULL) '.
            'AND subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 '.
            'AND total_amount >= 0 AND paid_amount >= 0)'
        );

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['lab_order_id']);
            $table->dropConstrainedForeignId('lab_order_id');
            $table->dropColumn(['currency_code', 'prices_include_tax', 'refunded_amount']);
        });

        // The status column stays wide: narrowing it could not hold a row written since.

        DB::statement('ALTER TABLE billable_services DROP CONSTRAINT billable_services_rules_check');
        DB::statement(
            'ALTER TABLE billable_services ADD CONSTRAINT billable_services_rules_check CHECK ('.
            'kind IN ('.$in(array_diff(self::SERVICE_KINDS, ['follow_up'])).') '.
            'AND default_price >= 0 AND tax_percent >= 0 AND tax_percent <= 100)'
        );

        DB::statement('ALTER TABLE billing_settings DROP CONSTRAINT billing_settings_lab_trigger_check');

        Schema::table('billing_settings', function (Blueprint $table) {
            $table->dropColumn('lab_trigger');
        });
    }
};
