<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Print the clinic payment receipt and refund slip on A4.
 *
 * Both were seeded as A5. The letterhead, stamp, summary, payment panel and
 * signature boxes are fixed-size blocks, and on A5 the payment panel — the
 * whole point of a receipt — went over onto a second sheet even at 9pt. The
 * registry now says A4; this moves the rows already written, exactly as
 * 2026_10_14 did for the clinic invoice.
 *
 * ONLY WHERE NOBODY HAS CHOSEN: a version still at A5 is the seeded one. A
 * branch that picked its own paper in the editor keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
UPDATE document_template_versions AS v
SET config = jsonb_set(
        jsonb_set(v.config, '{layout,paper}', '"a4"'::jsonb, true),
        '{layout,font_size}', '10'::jsonb, true
    )
FROM document_templates AS t
WHERE v.document_template_id = t.id
  AND t.document_type IN ('clinic_receipt', 'clinic_refund')
  AND (v.config -> 'layout' ->> 'paper') = 'a5'
SQL);
    }

    public function down(): void
    {
        // Going back to A5 would reintroduce the two-sheet receipt.
    }
};
