<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes across the rest of the communication tables.
 *
 * `message_templates`, `campaigns` and `audience_segments` already had them.
 * Three more need them, for three different reasons:
 *
 *   `automation_rules` is clinic-editable, so a retired rule should be
 *   recoverable like any other record — with who removed it and why.
 *
 *   `message_logs` is the evidence that answers "we never heard from the
 *   clinic". Clearing a log has to hide it, never destroy it, or the one
 *   question the module exists for becomes unanswerable.
 *
 *   `campaign_recipients` hangs off a campaign that soft-deletes. Its cascade
 *   therefore never fires, so without a `deleted_at` of its own the children
 *   of a removed campaign would stay visible after the parent went.
 *
 * `communication_channels` deliberately gets none. There is one fixed row per
 * channel, written by the migration and read by `forChannel()`; deleting it is
 * meaningless — disconnecting is `is_connected = false` — and a soft-deleted
 * row would simply make `firstOrCreate` write a duplicate.
 *
 * Both unique constraints are rebuilt as partial indexes at the same time.
 * A plain unique over a soft-deleting table is a trap: remove a rule and the
 * clinic can never create that event again, because the deleted row still
 * holds the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |------------------------------------------------------------------
        | Rules — removable, so they carry who and why
        |------------------------------------------------------------------
        */
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();
        });

        // The old constraint would outlive the row it guards.
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_channel_event_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX automation_rules_event_unique ON automation_rules (channel, event_key) '.
            'WHERE deleted_at IS NULL'
        );

        /*
        |------------------------------------------------------------------
        | The log — hidden, never destroyed
        |------------------------------------------------------------------
        |
        | No `deleted_by` or reason: nobody removes a delivery record one at a
        | time with an explanation. What this protects against is a bulk clear
        | taking the evidence with it.
        */
        Schema::table('message_logs', function (Blueprint $table) {
            $table->softDeletes();
        });

        /*
        |------------------------------------------------------------------
        | Recipients — so they go when their campaign does
        |------------------------------------------------------------------
        */
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT IF EXISTS campaign_recipients_campaign_id_customer_id_unique');
        DB::statement(
            'CREATE UNIQUE INDEX campaign_recipients_unique ON campaign_recipients (campaign_id, customer_id) '.
            'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS campaign_recipients_unique');

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->unique(['campaign_id', 'customer_id']);
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        DB::statement('DROP INDEX IF EXISTS automation_rules_event_unique');

        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deletion_reason');
            $table->dropSoftDeletes();
            $table->unique(['channel', 'event_key']);
        });
    }
};
