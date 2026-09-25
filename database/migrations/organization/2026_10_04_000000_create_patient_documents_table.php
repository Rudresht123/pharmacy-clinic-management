<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scans, reports and letters kept against a patient.
 *
 * The bytes live in `files`, which already knows how to store and purge them;
 * this table is what the file MEANS — whose record it belongs to, which visit
 * it came out of, what kind of document it is and therefore who may open it.
 *
 * Sensitivity is NOT a column. It is derived from the category by
 * App\Support\Documents\DocumentCategories, so re-classifying a category
 * applies to the files already uploaded rather than only to the next ones —
 * and so nobody can lower a document's sensitivity by editing a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             * The visit it came out of, where there was one.
             *
             * Nullable because a desk attaches an ID proof to a person, not to
             * an appointment, and because a patient's old reports predate
             * every visit this clinic has a record of.
             */
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            $table->foreignId('file_id')->constrained('files');

            /* Where it was taken in. Provenance, not a fence: the patient
               record itself is shared by every branch in the organization. */
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('category', 40);
            $table->string('title');
            $table->text('notes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * Soft, with a reason. A medical document that turned out to be
             * filed against the wrong patient has to stop being readable
             * immediately AND has to remain answerable for afterwards —
             * "who removed this, and why" is the first question asked.
             */
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            // The two lists this table exists to answer.
            $table->index(['customer_id', 'created_at']);
            $table->index('appointment_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
