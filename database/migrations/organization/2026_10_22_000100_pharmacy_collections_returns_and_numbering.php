<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pharmacy's own till, finished.
 *
 * The counter could sell and cancel. It could not take the rest of the money
 * on a bill left on credit, take back one strip out of three, or say that it
 * had handed money back. Those are this migration, together with the numbers
 * the pharmacy's documents are issued under:
 *
 *   1. MONEY BOTH WAYS. `pharmacy_sale_payments.is_refund` marks a row as
 *      money handed back — on a cancelled bill, or on a return — so the
 *      day's takings can be counted from payment rows alone, once each.
 *      `idempotency_key` makes a double-tapped "collect" record one payment.
 *      The tender list widens to the organisation's: online and cheque join.
 *
 *   2. RETURNS. `pharmacy_sale_returns` and its lines: what came back, from
 *      which line and batch, at what value, and what was refunded for it.
 *      The sale keeps `returned_amount` and each line `returned_quantity`,
 *      so "what is still owed" and "how much more can come back" are read
 *      from the row that was locked to change them.
 *
 *   3. BRANCH-WISE NUMBERS. A sale and a return are numbered per branch and
 *      per financial year by the same `hms_series_number()` the clinic's
 *      invoices use: GGN/PH/26-27/00001 and GGN/PHR/26-27/00001. Bills
 *      already issued keep the number printed on them — the trigger only
 *      fills a number that is missing.
 *
 * ADDITIVE. Columns and tables are added, CHECK constraints rebuilt to a
 * superset, two columns widened. Nothing is deleted or renumbered.
 */
return new class extends Migration
{
    private const TENDERS = ['cash', 'card', 'upi', 'bank_transfer', 'online', 'cheque', 'other'];

    private const SERIES = ['invoice', 'receipt', 'refund', 'pharmacy_sale', 'pharmacy_return'];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
        |------------------------------------------------------------------
        | 2 · Returns — first, because a refund row can point at one
        |------------------------------------------------------------------
        */
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            // The value of what came back — what the bill is no longer for.
            $table->decimal('returned_amount', 12, 2)->default(0)->after('paid_amount');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sales ADD CONSTRAINT pharmacy_sales_returned_check '.
            'CHECK (returned_amount >= 0 AND returned_amount <= total_amount)'
        );

        Schema::table('pharmacy_sale_items', function (Blueprint $table) {
            $table->integer('returned_quantity')->default(0)->after('quantity');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sale_items ADD CONSTRAINT pharmacy_sale_items_returned_check '.
            'CHECK (returned_quantity >= 0 AND returned_quantity <= quantity)'
        );

        Schema::create('pharmacy_sale_returns', function (Blueprint $table) {
            $table->id();

            // Taken by the database inside the INSERT — see the trigger below.
            $table->string('return_number', 64);

            $table->foreignId('pharmacy_sale_id')->constrained('pharmacy_sales')->restrictOnDelete();
            $table->foreignId('pharmacy_store_id')->constrained('pharmacy_stores')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            $table->timestamp('return_date');
            $table->string('reason', 500);

            // What came back, valued as the bill valued it.
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // What was handed back for it — only what had been paid over the new total.
            $table->decimal('refund_amount', 12, 2)->default(0);

            $table->string('idempotency_key', 100)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_name', 191)->nullable();

            $table->timestamps();

            $table->unique('return_number');
            $table->index(['pharmacy_store_id', 'return_date']);
            $table->index('pharmacy_sale_id');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sale_returns ADD CONSTRAINT pharmacy_sale_returns_rules_check CHECK ('.
            'taxable_amount >= 0 AND tax_amount >= 0 AND total_amount > 0 '.
            'AND refund_amount >= 0 AND refund_amount <= total_amount)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX pharmacy_sale_returns_idempotency_unique ON pharmacy_sale_returns (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        Schema::create('pharmacy_sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_sale_return_id')->constrained('pharmacy_sale_returns')->restrictOnDelete();
            $table->foreignId('pharmacy_sale_item_id')->constrained('pharmacy_sale_items')->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->constrained('medicine_batches')->restrictOnDelete();

            $table->integer('quantity');
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->index('pharmacy_sale_return_id');
            $table->index('pharmacy_sale_item_id');
        });

        DB::statement(
            'ALTER TABLE pharmacy_sale_return_items ADD CONSTRAINT pharmacy_sale_return_items_rules_check CHECK ('.
            'quantity > 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)'
        );

        /*
        |------------------------------------------------------------------
        | 1 · Money both ways
        |------------------------------------------------------------------
        */
        Schema::table('pharmacy_sale_payments', function (Blueprint $table) {
            $table->boolean('is_refund')->default(false)->after('amount');
            $table->foreignId('refunds_payment_id')->nullable()->after('is_refund')
                ->constrained('pharmacy_sale_payments')->restrictOnDelete();
            $table->foreignId('pharmacy_sale_return_id')->nullable()->after('refunds_payment_id')
                ->constrained('pharmacy_sale_returns')->restrictOnDelete();
            $table->string('notes', 500)->nullable()->after('reference');
            $table->string('idempotency_key', 100)->nullable();
        });

        DB::statement('ALTER TABLE pharmacy_sale_payments ALTER COLUMN method TYPE varchar(20)');
        DB::statement('ALTER TABLE pharmacy_sale_payments DROP CONSTRAINT pharmacy_sale_payments_rules_check');
        DB::statement(
            'ALTER TABLE pharmacy_sale_payments ADD CONSTRAINT pharmacy_sale_payments_rules_check CHECK ('.
            'method IN ('.$in(self::TENDERS).') '.
            'AND amount > 0)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX pharmacy_sale_payments_idempotency_unique ON pharmacy_sale_payments (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        // A return and its lines are financial records, like the bill they undo.
        foreach (['pharmacy_sale_returns', 'pharmacy_sale_return_items'] as $table) {
            DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION {$table}_no_delete() RETURNS trigger AS \$fn\$
BEGIN
    RAISE EXCEPTION '% is a financial record and cannot be deleted.', TG_TABLE_NAME;
END;
\$fn\$ LANGUAGE plpgsql;

CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
FOR EACH ROW EXECUTE FUNCTION {$table}_no_delete();
SQL);
        }

        /*
        |------------------------------------------------------------------
        | 3 · Branch-wise numbers for the pharmacy
        |------------------------------------------------------------------
        */
        DB::statement('ALTER TABLE number_series ALTER COLUMN document_type TYPE varchar(20)');
        DB::statement('ALTER TABLE number_series DROP CONSTRAINT number_series_rules_check');
        DB::statement(
            'ALTER TABLE number_series ADD CONSTRAINT number_series_rules_check CHECK ('.
            'document_type IN ('.$in(self::SERIES).") AND prefix <> '')"
        );

        /*
         * The same function as before, taught two more documents — and to
         * look at the pharmacy's numbers too when deciding whether a default
         * prefix has already been used. Every line that was there is kept.
         */
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hms_series_default_prefix(p_location bigint, p_type text) RETURNS text AS $fn$
DECLARE
    branch text;
    kind text;
    candidate text;
BEGIN
    SELECT upper(coalesce(nullif(trim(code), ''), 'B' || id)) INTO branch FROM locations WHERE id = p_location;

    kind := CASE p_type
        WHEN 'invoice' THEN coalesce(
            nullif(upper(rtrim(trim((SELECT invoice_prefix FROM billing_settings LIMIT 1)), '-/')), ''),
            'INV'
        )
        WHEN 'receipt' THEN 'RCP'
        WHEN 'refund' THEN 'RFD'
        WHEN 'pharmacy_sale' THEN 'PH'
        WHEN 'pharmacy_return' THEN 'PHR'
    END;

    candidate := left(branch || '/' || kind, 40);

    IF EXISTS (SELECT 1 FROM number_series WHERE upper(prefix) = candidate)
        OR EXISTS (SELECT 1 FROM invoices WHERE invoice_number LIKE candidate || '/%')
        OR EXISTS (SELECT 1 FROM invoice_payments WHERE receipt_number LIKE candidate || '/%')
        OR EXISTS (SELECT 1 FROM pharmacy_sales WHERE sale_number LIKE candidate || '/%')
        OR EXISTS (SELECT 1 FROM pharmacy_sale_returns WHERE return_number LIKE candidate || '/%')
    THEN
        candidate := left(candidate, 32) || '-' || p_location;
    END IF;

    RETURN candidate;
END;
$fn$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE FUNCTION pharmacy_sales_number() RETURNS trigger AS $fn$
BEGIN
    IF NEW.sale_number IS NULL THEN
        NEW.sale_number := hms_series_number(NEW.location_id, 'pharmacy_sale', NEW.sale_date);
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION pharmacy_sale_returns_number() RETURNS trigger AS $fn$
BEGIN
    IF NEW.return_number IS NULL THEN
        NEW.return_number := hms_series_number(NEW.location_id, 'pharmacy_return', NEW.return_date);
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;
SQL);

        /*
         * The column default has to go for the trigger to see a missing
         * number: Postgres fills a default in before a BEFORE INSERT trigger
         * runs, and the trigger would find the old organisation-wide number
         * already taken. Widened for prefix + year + sequence.
         */
        DB::statement('ALTER TABLE pharmacy_sales ALTER COLUMN sale_number DROP DEFAULT');
        DB::statement('ALTER TABLE pharmacy_sales ALTER COLUMN sale_number TYPE varchar(64)');

        DB::statement(
            'CREATE TRIGGER pharmacy_sales_number BEFORE INSERT ON pharmacy_sales '.
            'FOR EACH ROW EXECUTE FUNCTION pharmacy_sales_number()'
        );

        // NOT NULL is checked after a BEFORE trigger, so the column can stay required.
        DB::statement(
            'CREATE TRIGGER pharmacy_sale_returns_number BEFORE INSERT ON pharmacy_sale_returns '.
            'FOR EACH ROW EXECUTE FUNCTION pharmacy_sale_returns_number()'
        );
    }

    public function down(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement('DROP TRIGGER IF EXISTS pharmacy_sales_number ON pharmacy_sales');
        DB::statement('ALTER TABLE pharmacy_sales ALTER COLUMN sale_number SET DEFAULT hms_sale_number()');
        // The column stays wide: numbers issued under the branch series may not fit back.

        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS pharmacy_sales_number();
DROP FUNCTION IF EXISTS pharmacy_sale_returns_number();

CREATE OR REPLACE FUNCTION hms_series_default_prefix(p_location bigint, p_type text) RETURNS text AS $fn$
DECLARE
    branch text;
    kind text;
    candidate text;
BEGIN
    SELECT upper(coalesce(nullif(trim(code), ''), 'B' || id)) INTO branch FROM locations WHERE id = p_location;

    kind := CASE p_type
        WHEN 'invoice' THEN coalesce(
            nullif(upper(rtrim(trim((SELECT invoice_prefix FROM billing_settings LIMIT 1)), '-/')), ''),
            'INV'
        )
        WHEN 'receipt' THEN 'RCP'
        WHEN 'refund' THEN 'RFD'
    END;

    candidate := left(branch || '/' || kind, 40);

    IF EXISTS (SELECT 1 FROM number_series WHERE upper(prefix) = candidate)
        OR EXISTS (SELECT 1 FROM invoices WHERE invoice_number LIKE candidate || '/%')
        OR EXISTS (SELECT 1 FROM invoice_payments WHERE receipt_number LIKE candidate || '/%')
    THEN
        candidate := left(candidate, 32) || '-' || p_location;
    END IF;

    RETURN candidate;
END;
$fn$ LANGUAGE plpgsql STABLE;
SQL);

        DB::statement('ALTER TABLE number_series DROP CONSTRAINT number_series_rules_check');
        DB::statement(
            'ALTER TABLE number_series ADD CONSTRAINT number_series_rules_check CHECK ('.
            "document_type IN ('invoice', 'receipt', 'refund') AND prefix <> '')"
        );

        DB::statement('DROP INDEX IF EXISTS pharmacy_sale_payments_idempotency_unique');
        DB::statement('ALTER TABLE pharmacy_sale_payments DROP CONSTRAINT pharmacy_sale_payments_rules_check');
        DB::statement(
            'ALTER TABLE pharmacy_sale_payments ADD CONSTRAINT pharmacy_sale_payments_rules_check CHECK ('.
            'method IN ('.$in(['cash', 'card', 'upi', 'bank_transfer', 'other']).') AND amount > 0)'
        );

        Schema::table('pharmacy_sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pharmacy_sale_return_id');
            $table->dropConstrainedForeignId('refunds_payment_id');
            $table->dropColumn(['is_refund', 'notes', 'idempotency_key']);
        });

        foreach (['pharmacy_sale_return_items', 'pharmacy_sale_returns'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
            DB::statement("DROP FUNCTION IF EXISTS {$table}_no_delete()");
        }

        DB::statement('DROP TRIGGER IF EXISTS pharmacy_sale_returns_number ON pharmacy_sale_returns');

        Schema::dropIfExists('pharmacy_sale_return_items');
        Schema::dropIfExists('pharmacy_sale_returns');

        DB::statement('ALTER TABLE pharmacy_sale_items DROP CONSTRAINT pharmacy_sale_items_returned_check');
        Schema::table('pharmacy_sale_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        DB::statement('ALTER TABLE pharmacy_sales DROP CONSTRAINT pharmacy_sales_returned_check');
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropColumn('returned_amount');
        });
    }
};
