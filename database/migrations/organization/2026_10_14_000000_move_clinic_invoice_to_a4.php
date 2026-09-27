<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Print the clinic invoice on A4.
 *
 * It was seeded as A5, which is the right size for a prescription slip and
 * the wrong one for a tax document: seven columns and a heading per
 * department mean a six-line bill already runs onto a second sheet carrying
 * the payment panel and nothing else. The registry now says A4; this moves
 * the rows already written from the old value.
 *
 * ONLY WHERE NOBODY HAS CHOSEN. A version still sitting at exactly the old
 * seeded pair — A5 with the old margins — is one nobody has been into. A
 * branch that has opened the editor and picked its own paper keeps it.
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
  AND t.document_type = 'clinic_invoice'
  AND (v.config -> 'layout' ->> 'paper') = 'a5'
SQL);
    }

    public function down(): void
    {
        // Going back to A5 would reintroduce the two-sheet bill.
    }
};
