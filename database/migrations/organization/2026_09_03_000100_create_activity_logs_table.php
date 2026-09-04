<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What changed inside this organization, field by field.
 *
 * The tenant's own log, in the tenant's own database. A customer's phone
 * number changing is the organization's business and its data — putting it
 * in the master database would pool every tenant's record-level history in
 * one place, which is the one thing the physical-database-per-tenant model
 * exists to prevent.
 *
 * Its platform counterpart is `platform_audit_logs` in the master database,
 * with the same shape and the same append-only guarantee, for the actions a
 * super administrator takes.
 *
 * Append-only is enforced by a trigger rather than by convention, because
 * convention is not a control: a log that the application can quietly edit
 * is not evidence of anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            /*
             * Who did it.
             *
             * Deliberately NOT a foreign key. An append-only table cannot
             * carry a referential action that rewrites it: ON DELETE SET NULL
             * makes Postgres issue an UPDATE here, which this table's own
             * trigger refuses — so deleting a user with any history would
             * fail outright. Immutability is the property this table exists
             * for, so the constraint is the part that goes.
             *
             * The id is a convenience for joining to a live account and
             * simply stops resolving once that account is gone. `actor_name`
             * is the durable record: "somebody changed the price" is not an
             * audit trail.
             */
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_name', 191)->nullable();

            /*
             * Which side of the software acted. A platform administrator
             * working inside a tenant database and the organization's own
             * owner are different people with the same id space, and the
             * reader has to be able to tell them apart.
             */
            $table->string('actor_type', 20)->default('tenant');

            $table->string('event', 20);

            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id')->nullable();

            /*
             * What the record was called at the time. Read from the model, so
             * a deleted customer's history still says whose it was.
             */
            $table->string('entity_label', 191)->nullable();

            // Only the fields that actually changed, never the whole row.
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // One record's own timeline — the commonest read by far.
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
            $table->index('user_id');
        });

        DB::statement(
            'ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_event_check '.
            "CHECK (event IN ('created', 'updated', 'deleted', 'restored'))"
        );

        DB::statement(
            'ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_actor_type_check '.
            "CHECK (actor_type IN ('tenant', 'platform', 'system'))"
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION activity_logs_append_only() RETURNS trigger AS $trigger$
BEGIN
    RAISE EXCEPTION 'activity_logs is append-only: % is not permitted', TG_OP;
END;
$trigger$ LANGUAGE plpgsql;

CREATE TRIGGER activity_logs_no_update_or_delete
BEFORE UPDATE OR DELETE ON activity_logs
FOR EACH ROW EXECUTE FUNCTION activity_logs_append_only();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_no_update_or_delete ON activity_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS activity_logs_append_only()');

        Schema::dropIfExists('activity_logs');
    }
};
