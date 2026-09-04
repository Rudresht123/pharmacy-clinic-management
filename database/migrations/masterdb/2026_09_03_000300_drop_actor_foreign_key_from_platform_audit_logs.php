<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only table cannot carry a referential action that rewrites it.
 *
 * `platform_user_id` was declared ON DELETE SET NULL. Deleting an
 * administrator therefore made Postgres issue an UPDATE against
 * `platform_audit_logs` — which the table's own append-only trigger refuses,
 * so the delete failed outright with "platform_audit_logs is append-only".
 * The two guarantees were in direct conflict and the audit table won, which
 * meant an administrator with any history could never actually be removed.
 *
 * The constraint goes rather than the trigger. Immutability is the property
 * the table exists for; the foreign key was only ever a convenience for
 * joining to a live account. `actor_name` is the durable record of who acted
 * — that is why it was added — and the id is now a plain reference that
 * simply stops resolving once the account is gone, which the relation
 * already reports as null.
 *
 * The column and its index stay: joining to a live administrator is still
 * the common case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['platform_user_id']);
        });
    }

    public function down(): void
    {
        /*
         * Restoring the constraint re-creates the deadlock above, so any row
         * pointing at an account that has since been deleted is cleared
         * first — otherwise the constraint cannot be added at all.
         */
        DB::statement(<<<'SQL'
UPDATE platform_audit_logs SET platform_user_id = NULL
WHERE platform_user_id IS NOT NULL
  AND platform_user_id NOT IN (SELECT id FROM platform_users)
SQL);

        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->foreign('platform_user_id')
                ->references('id')
                ->on('platform_users')
                ->nullOnDelete();
        });
    }
};
