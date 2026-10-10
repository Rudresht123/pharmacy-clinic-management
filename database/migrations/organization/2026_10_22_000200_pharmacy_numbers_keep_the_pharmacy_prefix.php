<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A pharmacy that chose its own bill prefix keeps it.
 *
 * Pharmacy settings have always let an organisation say what its bills are
 * called — BILL rather than INV. When the pharmacy moved to branch-wise,
 * year-wise numbers, every branch's series defaulted to PH and that choice
 * stopped meaning anything. This reads it again: a pharmacy that bills as
 * BILL now bills as GGN/BILL/26-27/00001.
 *
 * Only a prefix somebody actually chose. The shipped default, INV, would
 * collide with the clinic's own invoice series (GGN/INV/...), so a pharmacy
 * still on it — or on whatever the clinic's invoices use — gets PH as before.
 *
 * Only a series that has not started yet is affected: this is the default a
 * branch's FIRST pharmacy bill takes. A series already in use keeps the
 * prefix it was created with, and anything already issued keeps its number.
 *
 * Every other line of the function is as the previous migration left it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hms_series_default_prefix(p_location bigint, p_type text) RETURNS text AS $fn$
DECLARE
    branch text;
    kind text;
    clinic text;
    pharmacy text;
    candidate text;
BEGIN
    SELECT upper(coalesce(nullif(trim(code), ''), 'B' || id)) INTO branch FROM locations WHERE id = p_location;

    clinic := coalesce(
        nullif(upper(rtrim(trim((SELECT invoice_prefix FROM billing_settings LIMIT 1)), '-/')), ''),
        'INV'
    );

    pharmacy := nullif(upper(rtrim(trim((SELECT invoice_prefix FROM pharmacy_settings LIMIT 1)), '-/')), '');

    kind := CASE p_type
        WHEN 'invoice' THEN clinic
        WHEN 'receipt' THEN 'RCP'
        WHEN 'refund' THEN 'RFD'
        WHEN 'pharmacy_sale' THEN CASE
            WHEN pharmacy IS NULL OR pharmacy IN ('INV', clinic, 'RCP', 'RFD', 'PHR') THEN 'PH'
            ELSE pharmacy
        END
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
SQL);
    }

    public function down(): void
    {
        // Back to the previous migration's version: every pharmacy series starts as PH.
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
SQL);
    }
};
