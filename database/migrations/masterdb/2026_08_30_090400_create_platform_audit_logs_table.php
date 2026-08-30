<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every platform action, append-only (Central/Platform DB reference,
 * decision #6): convention is not a control, so a `BEFORE UPDATE OR DELETE`
 * trigger raises rather than relying on the app never trying to.
 *
 * Table + trigger only in this migration — no application-side writer yet.
 * Wiring real audit writes across the app (e.g. into
 * `OrganizationRepository::changeStatus()`) is a distinct follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('platform_user_id')
                ->nullable()
                ->constrained('platform_users')
                ->nullOnDelete();

            $table->string('action', 150);
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('action');
            $table->index('platform_user_id');
            $table->index('created_at');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION platform_audit_logs_append_only() RETURNS trigger AS $trigger$
BEGIN
    RAISE EXCEPTION 'platform_audit_logs is append-only: % is not permitted', TG_OP;
END;
$trigger$ LANGUAGE plpgsql;

CREATE TRIGGER platform_audit_logs_no_update_or_delete
BEFORE UPDATE OR DELETE ON platform_audit_logs
FOR EACH ROW EXECUTE FUNCTION platform_audit_logs_append_only();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS platform_audit_logs_no_update_or_delete ON platform_audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS platform_audit_logs_append_only()');
        Schema::dropIfExists('platform_audit_logs');
    }
};
