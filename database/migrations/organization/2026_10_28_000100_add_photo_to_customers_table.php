<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photograph of the patient.
 *
 * The same shape as `doctors.photo`: a file id in the tenant's own `files`
 * table rather than a path, so a replaced photo is purged with its row and a
 * lost file never takes the patient with it. The registers that name a
 * patient already draw a photograph frame and wait for this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('photo')->nullable()->after('name');

            // nullOnDelete: a patient whose photograph has gone is simply a patient without one.
            $table->foreign('photo')
                ->references('id')
                ->on('files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['photo']);
            $table->dropColumn('photo');
        });
    }
};
