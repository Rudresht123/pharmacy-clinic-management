<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a printed document looks like, and every version it has ever looked
 * like.
 *
 * TWO TABLES, and the split is the whole design.
 *
 * `document_templates` is the identity — "Gurgaon's prescription" — and it is
 * what a branch edits. `document_template_versions` is what it actually LOOKED
 * LIKE at a point in time, and a generated PDF points at one of those rows
 * forever. A prescription printed in September must still carry September's
 * logo and September's footer when it is read back next year, whatever the
 * branch has changed since.
 *
 * Which is also why a version stores the WHOLE config rather than a diff: a
 * document read back in five years has to be reconstructable from one row,
 * without replaying a chain.
 *
 * INHERITANCE NEEDS NO THIRD TABLE. `location_id` nullable says it:
 *
 *   location_id IS NULL   the organization's default, used by every branch
 *                         that has not customised
 *   location_id = 7       branch 7's override, used by branch 7 alone
 *
 * There is deliberately NO `organization_id`. Every tenant table in this
 * system omits it, because the database IS the organization — a column here
 * would be a value nothing derives and nothing enforces, and the first person
 * to write `where('organization_id', …)` would believe they had scoped a query
 * that was already scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();

            /* Null = the organization's own default. See the header. */
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnDelete();

            /* A key from App\Support\Documents\DocumentTypes — a varchar, not
               a foreign key, for the same reason capabilities are: the
               registry is code, and a type retired from it must stop working
               rather than block a migration. */
            $table->string('document_type', 40);

            $table->string('name');
            $table->string('description', 500)->nullable();

            /*
             * draft    being written; cannot generate anything
             * active   the one used for new documents
             * archived kept because old documents point at its versions
             */
            $table->string('status', 20)->default('draft');

            /* Which version is used for new documents. Null while a template
               has never been published — a draft with no published version is
               a real state, and the normal one on the day it is created. */
            $table->unsignedBigInteger('active_version_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['document_type', 'status']);
        });

        /*
         * One template per type per place — as two partial indexes.
         *
         * Postgres treats NULLs as distinct in a unique index, so a plain
         * unique on (location_id, document_type) would accept two
         * organization defaults for the same document type. Splitting on
         * whether the branch is set says what was meant, on every version.
         *
         * Both exclude soft-deleted rows, so a removed template does not
         * block writing a new one.
         */
        DB::statement(
            'CREATE UNIQUE INDEX dt_unique_at_branch ON document_templates '
            .'(location_id, document_type) WHERE location_id IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX dt_unique_organization ON document_templates '
            .'(document_type) WHERE location_id IS NULL AND deleted_at IS NULL'
        );

        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();

            /* 1, 2, 3 within a template. What a reader sees as "V2". */
            $table->unsignedInteger('version');

            /*
             * The whole thing — header, body, footer, layout — as one
             * document. Not a column per setting: what a template can carry
             * grows with the renderer, and a migration per new field is how a
             * design like this ossifies.
             */
            $table->jsonb('config');

            /*
             * Paths into `config` that a branch may not change, written by
             * whoever holds `documents.template_org`.
             *
             * Lives on the ORGANIZATION default's version. A branch override
             * is validated against it on save, server-side — the UI badge is
             * a courtesy, never the enforcement.
             */
            $table->jsonb('locked_fields')->nullable();

            /* Null while a draft. A version is immutable once published: it is
               what somebody's printed prescription was made from. */
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['document_template_id', 'version']);
        });

        // Added after both tables exist, because it points forwards.
        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreign('active_version_id')
                ->references('id')
                ->on('document_template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropForeign(['active_version_id']);
        });

        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
    }
};
