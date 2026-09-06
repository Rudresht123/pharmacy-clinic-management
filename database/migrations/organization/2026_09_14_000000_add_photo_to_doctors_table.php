<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photograph of the doctor.
 *
 * Every list that names a doctor currently draws a coloured circle with their
 * initial in it — the queue, the OPD board, the booking dialog's doctor cards.
 * That was the honest placeholder while there was nothing to show; a real
 * photograph is what a patient at a counter actually recognises, and what
 * makes a list of six names scannable rather than read.
 *
 * A file id, following the same shape as `organizations.profile_image`: the
 * bytes live on a disk and the row records where, so a doctor deleted does not
 * leave an orphaned file and a file replaced does not leave an orphaned row.
 *
 * The `files` table it points at is the TENANT's own, not the master's. An
 * organization's logo lives in master beside the organization; a doctor lives
 * in the tenant database, and a foreign key across two databases is not a
 * foreign key at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->unsignedBigInteger('photo')->nullable()->after('name');

            /*
             * nullOnDelete rather than cascade: losing the image must never
             * take the doctor with it, and a doctor whose photograph has gone
             * is simply a doctor without one.
             */
            $table->foreign('photo')
                ->references('id')
                ->on('files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropForeign(['photo']);
            $table->dropColumn('photo');
        });
    }
};
