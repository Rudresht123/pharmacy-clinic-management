<?php

use App\Models\Tenant\ExportRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One file the Export Center is making, or made, for one person.
 *
 * A whole register is too long for one web request — the browser gave up
 * before a large Excel or PDF was written ("Cannot reach the server"). So the
 * file is written by a queued job (App\Jobs\RunExport) while the person keeps
 * working, and this row is what the screen watches: how far it has got, then
 * where the file is.
 *
 * The filters are kept exactly as they were asked for, and the job asks
 * ExportAccess again when it runs, so a role changed in between is obeyed.
 *
 * The FILE is short-lived (a day — it holds patient records); the ROW stays,
 * a record of who exported what and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        Schema::create('export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Which export, in which format — "billing.invoices", "xlsx".
            $table->string('dataset', 80);
            $table->string('format', 8);

            // As the tray names it: "Billing — Invoices", "invoices".
            $table->string('title', 191);
            $table->string('noun', 60);

            // The filters, columns, order and rows ticked, as asked.
            $table->jsonb('filters');

            $table->string('status', 12)->default(ExportRun::QUEUED);
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);

            $table->string('file_name', 191)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // The file is deleted after this; the row stays.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            // Cleared from the person's tray; the row stays.
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement(
            'ALTER TABLE export_runs ADD CONSTRAINT export_runs_rules_check CHECK ('.
            'status IN ('.$in(ExportRun::STATUSES).') '.
            'AND format IN ('.$in(ExportRun::FORMATS).') '.
            'AND total_rows >= 0 AND processed_rows >= 0 '.
            "AND (status <> 'completed' OR (file_path IS NOT NULL AND finished_at IS NOT NULL)))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('export_runs');
    }
};
