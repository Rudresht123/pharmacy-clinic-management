<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the clinic invoice back its charges table.
 *
 * `clinic_invoice` was added to the document registry when Billing shipped,
 * but TemplateConfig::tablesFor() was not taught about it — so the type's
 * defaults carried `body.tables => []`, and every template seeded from those
 * defaults prints a letterhead, a patient and a total with NO LINES between
 * them. A bill that does not say what it is charging for.
 *
 * The registry is fixed; this repairs the rows already written from it.
 *
 * DELIBERATELY NARROW. Only versions whose table block is empty are touched —
 * a branch that has since edited its own template, or removed the block on
 * purpose, keeps what it chose. A published version is repaired in place
 * rather than superseded: it was never a version anybody meant to publish,
 * and leaving it live would mean every clinic reprinting today's bills from
 * a template that still omits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $block = json_encode([['token' => 'invoice_items', 'title' => 'Charges']]);

        DB::statement(<<<SQL
UPDATE document_template_versions AS v
SET config = jsonb_set(v.config, '{body,tables}', '{$block}'::jsonb, true)
FROM document_templates AS t
WHERE v.document_template_id = t.id
  AND t.document_type = 'clinic_invoice'
  AND coalesce(jsonb_array_length(v.config -> 'body' -> 'tables'), 0) = 0
SQL);
    }

    public function down(): void
    {
        // Putting the block back to empty would reintroduce the bug, and
        // nothing downstream depends on it being absent.
    }
};
