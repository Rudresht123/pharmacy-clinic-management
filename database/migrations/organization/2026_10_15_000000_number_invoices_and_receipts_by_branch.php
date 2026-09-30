<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice, receipt and refund numbers, per branch and per financial year.
 *
 *   GGN/INV/26-27/00001   Gurgaon's first invoice of FY 2026-27
 *   GGN/RCP/26-27/00001   its first payment receipt
 *   GGN/RFD/26-27/00001   its first refund
 *   DEL/INV/26-27/00001   Delhi, counting on its own
 *
 * WHY BRANCH-WISE. A GST invoice series belongs to a GSTIN, and a clinic with
 * branches in two states has two. One organisation-wide counter made every
 * branch's register full of holes where another branch's bills sat.
 *
 * WHY THE FINANCIAL YEAR IS IN THE NUMBER. Every series restarts on 1 April,
 * the way Indian registers are kept. Each year gets its own counter row, so a
 * document dated into an earlier year — the backfill below, a correction —
 * takes the next number of THAT year and never drags the current one back.
 *
 * STILL THE DATABASE'S NUMBER, taken inside the INSERT, exactly as before. A
 * BEFORE INSERT trigger rather than a column default only because the number
 * now depends on the row: which branch, which day, payment or refund.
 *
 * GAPLESS, UNLIKE THE SEQUENCES IT REPLACES. The counter is a row updated in
 * the caller's transaction, so a payment that rolls back hands its number
 * back. The price is that two bills at the same branch in the same instant
 * queue for that row; at a clinic counter that is microseconds.
 *
 * NOTHING ALREADY ISSUED IS RENUMBERED. Invoices keep the INV-00042 they were
 * printed with; only new ones take the branch series. Payments had no number
 * at all, so every existing one is given a receipt number here, in the order
 * it was taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One series per branch per document: what its numbers start with.
         *
         * The prefix is the branch's to edit. Unique across EVERY series, not
         * only within a type, because receipts and refunds share one column —
         * two series with one prefix could mint the same number.
         */
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('document_type', 12);
            // Room for a 30-character branch code and the document part; an
            // edited prefix is held to far less by the settings screen.
            $table->string('prefix', 40);
            $table->timestamps();

            $table->unique(['location_id', 'document_type']);
        });

        DB::statement(
            'ALTER TABLE number_series ADD CONSTRAINT number_series_rules_check CHECK ('.
            "document_type IN ('invoice', 'receipt', 'refund') AND prefix <> '')"
        );

        DB::statement('CREATE UNIQUE INDEX number_series_prefix_unique ON number_series (upper(prefix))');

        // How far each series has got in each financial year.
        Schema::create('number_series_counters', function (Blueprint $table) {
            $table->foreignId('number_series_id')->constrained('number_series')->cascadeOnDelete();
            $table->string('financial_year', 5);
            $table->unsignedBigInteger('last_number');

            $table->primary(['number_series_id', 'financial_year']);
        });

        DB::unprepared(<<<'SQL'
-- 1 April to 31 March, written the way Indian registers write it: 26-27.
CREATE OR REPLACE FUNCTION hms_financial_year(at timestamp) RETURNS text AS $fn$
DECLARE
    y int := extract(year FROM at)::int - CASE WHEN extract(month FROM at) < 4 THEN 1 ELSE 0 END;
BEGIN
    RETURN lpad((y % 100)::text, 2, '0') || '-' || lpad(((y + 1) % 100)::text, 2, '0');
END;
$fn$ LANGUAGE plpgsql IMMUTABLE;

-- What a branch's series starts as before anybody edits it: its code, then
-- the document. The organisation's own invoice prefix stays the invoice part,
-- so a clinic that billed as CLINIC-00042 now bills as GGN/CLINIC/26-27/...
--
-- A default that another series already holds, or that numbers were once
-- issued under (a prefix somebody moved away from, a deleted branch whose
-- code came back), would mint numbers that already exist and the branch's
-- first bill would fail to save. It takes the branch's id as well, then.
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

-- The next number in a branch's series for the year `at` falls in.
CREATE OR REPLACE FUNCTION hms_series_number(p_location bigint, p_type text, p_at timestamp) RETURNS text AS $fn$
DECLARE
    series_id bigint;
    series_prefix text;
    fy text := hms_financial_year(p_at);
    n bigint;
BEGIN
    INSERT INTO number_series (location_id, document_type, prefix, created_at, updated_at)
    VALUES (p_location, p_type, hms_series_default_prefix(p_location, p_type), now(), now())
    ON CONFLICT (location_id, document_type) DO NOTHING;

    SELECT id, prefix INTO series_id, series_prefix
    FROM number_series
    WHERE location_id = p_location AND document_type = p_type;

    INSERT INTO number_series_counters AS c (number_series_id, financial_year, last_number)
    VALUES (series_id, fy, 1)
    ON CONFLICT (number_series_id, financial_year) DO UPDATE SET last_number = c.last_number + 1
    RETURNING c.last_number INTO n;

    RETURN series_prefix || '/' || fy || '/' || lpad(n::text, greatest(5, length(n::text)), '0');
END;
$fn$ LANGUAGE plpgsql VOLATILE;

CREATE OR REPLACE FUNCTION invoices_number() RETURNS trigger AS $fn$
BEGIN
    IF NEW.invoice_number IS NULL THEN
        NEW.invoice_number := hms_series_number(NEW.location_id, 'invoice', NEW.invoice_date);
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;

-- A payment has no branch of its own; it is taken at the invoice's.
CREATE OR REPLACE FUNCTION invoice_payments_number() RETURNS trigger AS $fn$
BEGIN
    IF NEW.receipt_number IS NULL THEN
        NEW.receipt_number := hms_series_number(
            (SELECT location_id FROM invoices WHERE id = NEW.invoice_id),
            CASE WHEN NEW.is_refund THEN 'refund' ELSE 'receipt' END,
            NEW.paid_at
        );
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;
SQL);

        /*
         * Invoices: the trigger takes over from the column default. The
         * default has to go — Postgres fills it in before the trigger runs,
         * so the trigger would find a number already taken from the old
         * organisation-wide sequence.
         */
        DB::statement('ALTER TABLE invoices ALTER COLUMN invoice_number DROP DEFAULT');

        // Prefix (40) + year (5) + at least five digits and the separators.
        DB::statement('ALTER TABLE invoices ALTER COLUMN invoice_number TYPE varchar(64)');
        DB::statement(
            'CREATE TRIGGER invoices_number BEFORE INSERT ON invoices '.
            'FOR EACH ROW EXECUTE FUNCTION invoices_number()'
        );

        Schema::table('invoice_payments', function (Blueprint $table) {
            // Nullable only until the backfill below has numbered every row.
            $table->string('receipt_number', 64)->nullable();
        });

        // Every payment already taken, numbered in the order it was taken.
        DB::unprepared(<<<'SQL'
DO $do$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT p.id, p.is_refund, p.paid_at, i.location_id
        FROM invoice_payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE p.receipt_number IS NULL
        ORDER BY p.paid_at, p.id
    LOOP
        UPDATE invoice_payments
        SET receipt_number = hms_series_number(
            r.location_id,
            CASE WHEN r.is_refund THEN 'refund' ELSE 'receipt' END,
            r.paid_at
        )
        WHERE id = r.id;
    END LOOP;
END;
$do$;
SQL);

        DB::statement('ALTER TABLE invoice_payments ALTER COLUMN receipt_number SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX invoice_payments_receipt_number_unique ON invoice_payments (receipt_number)');
        DB::statement(
            'CREATE TRIGGER invoice_payments_number BEFORE INSERT ON invoice_payments '.
            'FOR EACH ROW EXECUTE FUNCTION invoice_payments_number()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS invoice_payments_number ON invoice_payments');
        DB::statement('DROP INDEX IF EXISTS invoice_payments_receipt_number_unique');

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('receipt_number');
        });

        DB::statement('DROP TRIGGER IF EXISTS invoices_number ON invoices');
        DB::statement('ALTER TABLE invoices ALTER COLUMN invoice_number SET DEFAULT hms_invoice_number()');
        // The column stays wide: numbers issued under the branch series may
        // not fit back into 32, and they are financial records.

        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS invoice_payments_number();
DROP FUNCTION IF EXISTS invoices_number();
DROP FUNCTION IF EXISTS hms_series_number(bigint, text, timestamp);
DROP FUNCTION IF EXISTS hms_series_default_prefix(bigint, text);
DROP FUNCTION IF EXISTS hms_financial_year(timestamp);
SQL);

        Schema::dropIfExists('number_series_counters');
        Schema::dropIfExists('number_series');
    }
};
