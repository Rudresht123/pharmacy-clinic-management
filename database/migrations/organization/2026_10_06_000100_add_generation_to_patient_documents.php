<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a patient document came from.
 *
 * `patient_documents` already holds everything a clinic uploads — a scan, a
 * referral letter, an insurance card. A PDF this software prints belongs in
 * the same place: it hangs off the same patient, files under the same
 * categories, obeys the same clinical/administrative split and is fetched
 * through the same authorised endpoint. A second table would be the same
 * privacy rules written twice.
 *
 * Three columns are all it needs. `source` distinguishes them, and the two
 * template columns say what a generated one was made from — which is what
 * makes a printed prescription still readable as the document it was, rather
 * than as whatever the branch's template says today.
 *
 * Existing rows are all uploads, so the default backfills correctly and
 * nothing has to be rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->string('source', 20)->default('uploaded')->after('category');

            /*
             * Which template produced it, and — the one that matters — which
             * VERSION.
             *
             * nullOnDelete on the template, because a template deleted years
             * later must not take the record of what it printed with it. The
             * version is restricted instead: a published version is what
             * somebody's document was made from, and deleting it would make
             * that document unreadable as itself.
             */
            $table->foreignId('document_template_id')
                ->nullable()
                ->after('source')
                ->constrained('document_templates')
                ->nullOnDelete();

            $table->foreignId('document_template_version_id')
                ->nullable()
                ->after('document_template_id')
                ->constrained('document_template_versions')
                ->restrictOnDelete();

            /* The number printed on it — RX-00412, INV-00981 — so a document
               can be found by what somebody is holding. */
            $table->string('document_number', 60)->nullable()->after('title');

            $table->index('source');
            $table->index('document_number');
        });
    }

    public function down(): void
    {
        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropIndex(['document_number']);
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('document_template_version_id');
            $table->dropConstrainedForeignId('document_template_id');
            $table->dropColumn(['source', 'document_number']);
        });
    }
};
