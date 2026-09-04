<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that keep a platform audit row readable on its own.
 *
 * `platform_user_id` is nulled when an administrator is deleted, which is
 * correct for the foreign key and useless for an audit trail: "somebody
 * suspended this organization" answers nothing. The name is copied at write
 * time so the row survives the account.
 *
 * `entity_label` does the same for the subject — an organization that has
 * since been renamed, or removed, still says what it was called when the
 * action was taken.
 *
 * Adding a column is DDL and does not trip the table's append-only trigger,
 * which fires per row on UPDATE and DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->string('actor_name', 191)->nullable()->after('platform_user_id');
            $table->string('entity_label', 191)->nullable()->after('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_name', 'entity_label']);
        });
    }
};
