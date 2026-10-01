<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which documents a clinic wants made on its own, and the key that stops one
 * being made twice.
 *
 *   payment_received      → clinic_receipt   (all branches)
 *   refund_processed      → clinic_refund    (Gurgaon only)
 *
 * A ROW IS A YES. There is no "enabled" column: a clinic that stops wanting a
 * receipt printed on every payment removes the rule, and what is on the
 * screen is exactly what runs. Delivery (WhatsApp, email) is not here either —
 * a switch for a send that nothing performs yet would be a switch connected
 * to nothing, and its columns arrive with the code that reads them.
 *
 * A NULL BRANCH IS EVERY BRANCH. Unique per event, document and branch, with
 * the null folded to 0 so "every branch" can be said only once — a plain
 * unique index would treat two nulls as different and allow the same
 * organisation-wide rule twice.
 *
 * THE IDEMPOTENCY KEY is on the document, not the rule: it is what makes a
 * replayed event, a retried request or two overlapping rules file one receipt
 * rather than two. Unique across removed documents too, so a receipt somebody
 * deleted with a reason is not quietly printed again by the next replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_rules', function (Blueprint $table) {
            $table->id();
            // Keys from ClinicEvents and DocumentTypes — code-authoritative, so
            // no foreign key, and validated where a rule is saved.
            $table->string('event_key', 64);
            $table->string('document_type', 64);
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('event_key');
        });

        DB::statement(
            'CREATE UNIQUE INDEX document_rules_unique '
            .'ON document_rules (event_key, document_type, COALESCE(location_id, 0))'
        );

        Schema::table('patient_documents', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX patient_documents_idempotency_key_unique '
            .'ON patient_documents (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS patient_documents_idempotency_key_unique');

        Schema::table('patient_documents', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });

        Schema::dropIfExists('document_rules');
    }
};
