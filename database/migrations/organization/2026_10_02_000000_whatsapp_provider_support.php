<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a channel needs before anything can actually be sent.
 *
 * The communication tables already describe WHAT a clinic sends — templates,
 * rules, a delivery log. None of them describe WHO CARRIES IT, because until
 * now nothing did: every message stopped at `queued`.
 *
 * Three additions, and deliberately no new settings table. `communication_
 * channels` is already one row per channel per clinic with a provider column;
 * a second table keyed by organization would be the same fact in two places,
 * and the older one is already what the Manage Connection screen writes to.
 *
 *   1. CREDENTIALS on the channel, encrypted. Per-tenant by construction —
 *      each clinic's token lives in that clinic's own database, so a platform
 *      operator cannot read them all from one place.
 *
 *   2. PROVIDER MAPPING for templates. `appointment_confirmation` is the
 *      clinic's name for a message; what Digiware calls it, and what Meta
 *      calls it, are two more facts that belong beside it rather than in it —
 *      a clinic migrating between providers needs both mapped at once.
 *
 *   3. PROVENANCE on the log. Which provider carried a message, what id it
 *      gave back, and what it said when it refused. Without the id no webhook
 *      can ever match a delivery receipt to the row it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |------------------------------------------------------------------
        | Credentials, encrypted, on the channel that uses them
        |------------------------------------------------------------------
        |
        | A text column rather than json: the value is a ciphertext blob, and
        | typing it as json invites a database that will try to parse it.
        | The model casts it to `encrypted:array`, so nothing reads it in the
        | clear — including anybody with a psql prompt.
        */
        Schema::table('communication_channels', function (Blueprint $table) {
            /*
             * WHICH ADAPTER, as opposed to `provider`, which is the label the
             * connection card shows ("WhatsApp Business API", "Google SMTP").
             *
             * Two different facts that read alike: one is prose a clinic may
             * edit, the other is a key the container resolves a class from.
             * Overloading the first would mean renaming a label breaks
             * sending, which is not a trade anybody would make on purpose.
             */
            $table->string('provider_key', 40)->nullable()->after('provider');

            $table->text('credentials')->nullable()->after('settings');

            // When the credentials were last proved to work, and what was
            // said if they did not. A clinic whose token was revoked last
            // Tuesday should be able to find that out here.
            $table->timestampTz('verified_at')->nullable()->after('credentials');
            $table->string('verification_error', 500)->nullable()->after('verified_at');
        });

        /*
        |------------------------------------------------------------------
        | One template, many providers
        |------------------------------------------------------------------
        |
        | `status` is the PROVIDER's opinion, which is the point: Digiware may
        | have approved a template that Meta has not, and a clinic running
        | both needs to know which one it can send today.
        */
        Schema::create('whatsapp_template_providers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_template_id')
                ->constrained('message_templates')
                ->cascadeOnDelete();

            $table->string('provider', 40);

            // What the provider calls it. Digiware and Meta use a name;
            // Twilio uses an opaque Content SID — hence a string, not a name.
            $table->string('provider_template_id', 190)->nullable();
            $table->string('provider_template_name', 190)->nullable();

            $table->string('language', 10)->default('en');
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason', 500)->nullable();

            $table->timestampTz('synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'status']);
        });

        DB::statement(
            'ALTER TABLE whatsapp_template_providers ADD CONSTRAINT whatsapp_template_providers_status_check '.
            "CHECK (status IN ('pending', 'approved', 'rejected', 'disabled'))"
        );

        // One mapping per template per provider, among the live rows — a
        // deleted mapping must not block re-creating it.
        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_template_providers_unique '.
            'ON whatsapp_template_providers (message_template_id, provider) '.
            'WHERE deleted_at IS NULL'
        );

        /*
        |------------------------------------------------------------------
        | Which provider carried it, and what it said
        |------------------------------------------------------------------
        |
        | `provider_message_id` is indexed because it is what a webhook
        | arrives holding: a delivery receipt names the provider's id and
        | nothing else, and without the index every receipt is a table scan.
        |
        | Payloads are nullable and redacted before they are written. They
        | exist so a failure can be explained, not so the clinic accumulates
        | a second copy of every message it has ever sent.
        */
        Schema::table('message_logs', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->after('channel');
            $table->string('provider_message_id', 190)->nullable()->after('provider');

            $table->string('error_code', 60)->nullable()->after('failure_reason');
            $table->unsignedSmallInteger('attempts')->default(0)->after('error_code');

            $table->json('request_payload')->nullable()->after('attempts');
            $table->json('response_payload')->nullable()->after('request_payload');

            $table->timestampTz('failed_at')->nullable()->after('read_at');
        });

        DB::statement(
            'CREATE INDEX message_logs_provider_message_id_index '.
            'ON message_logs (provider, provider_message_id) '.
            'WHERE provider_message_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS message_logs_provider_message_id_index');

        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropColumn([
                'provider', 'provider_message_id', 'error_code', 'attempts',
                'request_payload', 'response_payload', 'failed_at',
            ]);
        });

        Schema::dropIfExists('whatsapp_template_providers');

        Schema::table('communication_channels', function (Blueprint $table) {
            $table->dropColumn(['provider_key', 'credentials', 'verified_at', 'verification_error']);
        });
    }
};
