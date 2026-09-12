<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the pharmacy's documents: RX-00001, DS-00001, GRN-00001…
 *
 * A Postgres sequence per document type, and one function that turns the
 * next value into the printed number. The tables that carry these numbers
 * (later phases) use the function as their column DEFAULT, so the number is
 * taken by the database inside the INSERT — two counters saving at once can
 * never draw the same one, which "max + 1" in PHP cannot promise.
 *
 *   RX  → prescription_number_seq
 *   DS  → dispensing_number_seq
 *   GRN → stock_inward_number_seq
 *   ADJ → stock_adjustment_number_seq
 *   TRF → stock_transfer_number_seq
 *
 * Sequences are not rolled back with a transaction, so a failed save leaves a
 * gap in the numbering. That is the price of never issuing a number twice,
 * and it is the normal behaviour of every document register that uses one.
 *
 * Five digits at least, and never truncated: RX-99999 is followed by
 * RX-100000, not by RX-00000.
 */
return new class extends Migration
{
    private const SEQUENCES = [
        'prescription_number_seq',
        'dispensing_number_seq',
        'stock_inward_number_seq',
        'stock_adjustment_number_seq',
        'stock_transfer_number_seq',
    ];

    public function up(): void
    {
        foreach (self::SEQUENCES as $sequence) {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} AS bigint START WITH 1 MINVALUE 1 NO CYCLE");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hms_document_number(prefix text, seq regclass) RETURNS text AS $fn$
DECLARE
    v bigint;
BEGIN
    v := nextval(seq);

    RETURN prefix || '-' || lpad(v::text, greatest(5, length(v::text)), '0');
END;
$fn$ LANGUAGE plpgsql VOLATILE;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS hms_document_number(text, regclass)');

        foreach (self::SEQUENCES as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }
    }
};
