<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far a provisioning step has got, not only whether it has finished.
 *
 * Provisioning now runs on a queue, and the administrator who created the
 * organization watches it happen. "Building the tables" alone is a step that
 * can take minutes on a schema of a hundred migrations; "45 of 92 —
 * create_invoices_table" is the difference between waiting and wondering
 * whether anything is happening at all.
 *
 * Additive and nullable: every existing row is a step that already finished,
 * and a step with nothing to count (creating the database, sending the
 * email) simply leaves them empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_provision_events', function (Blueprint $table) {
            $table->unsignedInteger('progress_current')->nullable()->after('status');
            $table->unsignedInteger('progress_total')->nullable()->after('progress_current');
            // What the step is doing right now — the migration being run.
            $table->string('detail', 191)->nullable()->after('progress_total');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_provision_events', function (Blueprint $table) {
            $table->dropColumn(['progress_current', 'progress_total', 'detail']);
        });
    }
};
