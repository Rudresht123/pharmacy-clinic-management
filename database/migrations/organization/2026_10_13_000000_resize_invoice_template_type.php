<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make an invoice fit on one sheet.
 *
 * A tax invoice carries seven columns — number, item, HSN/SAC, quantity,
 * rate, tax and amount. The templates were seeded at the prose default of
 * 11pt, which on A5 runs a six-line bill onto a second page that carries the
 * totals and nothing else. Handing a patient two sheets for one visit is the
 * kind of thing a counter gets asked about.
 *
 * ONLY WHERE NOBODY HAS CHOSEN A SIZE. A version still sitting at exactly 11
 * is one seeded from the old default; anything else is a branch that has
 * been into the editor and set what it wanted, and that is not this
 * migration's to overrule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
UPDATE document_template_versions AS v
SET config = jsonb_set(v.config, '{layout,font_size}', '9'::jsonb, true)
FROM document_templates AS t
WHERE v.document_template_id = t.id
  AND t.document_type IN ('clinic_invoice', 'pharmacy_invoice')
  AND (v.config -> 'layout' ->> 'font_size') = '11'
SQL);
    }

    public function down(): void
    {
        // Putting 11 back would reintroduce the two-page bill.
    }
};
