<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a clinic needs before it can message a patient at all.
 *
 * Four tables, one idea: a CHANNEL is connected, it owns TEMPLATES it is
 * allowed to send, RULES decide when they go out on their own, and every send
 * leaves a LOG entry. WhatsApp is the only channel wired up today, but nothing
 * here is WhatsApp-shaped — email and SMS differ in a subject line and a
 * provider name, and both are columns rather than tables of their own.
 *
 * Organisation-wide, not per branch. A clinic messages patients from one
 * number under one business name; which branch the appointment was at belongs
 * on the appointment, not on the connection.
 *
 * The log is the important one. "We never heard from the clinic" is the
 * question this module exists to answer, and it can only be answered from a
 * row written at the time — which is also where every figure on the dashboard
 * is counted from, rather than being a total kept in a column that drifts.
 */
return new class extends Migration
{
    /** Channels the module knows about; only WhatsApp is built. */
    private const CHANNELS = ['whatsapp', 'email', 'sms'];

    /** A template's standing with the provider. */
    private const TEMPLATE_STATUSES = ['draft', 'pending', 'approved', 'rejected'];

    /**
     * How far a message got.
     *
     * Ordered, and that order is the funnel the dashboard counts: queued
     * before sent, sent before delivered, delivered before read. `failed` is
     * the one that leaves the sequence, from wherever it got to.
     */
    private const DELIVERY_STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed'];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
        |------------------------------------------------------------------
        | The connection
        |------------------------------------------------------------------
        |
        | One row per channel, created here so every screen has something to
        | read on a clinic that has never opened the module. `settings` holds
        | what differs per provider — a webhook URL for WhatsApp, an SMTP
        | host for email — because those are configuration, not facts the
        | application ever queries across rows.
        */
        Schema::create('communication_channels', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->unique();

            // What the patient sees this arriving from.
            $table->string('display_name', 150)->nullable();
            $table->string('handle', 150)->nullable();

            // How it is wired up: "WhatsApp Business API", "Google SMTP".
            $table->string('provider', 100)->nullable();

            $table->boolean('is_connected')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('webhook_configured')->default(false);
            $table->timestampTz('connected_at')->nullable();

            $table->json('settings')->nullable();

            $table->timestamps();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_channels ADD CONSTRAINT communication_channels_channel_check '.
            'CHECK (channel IN ('.$in(self::CHANNELS).'))'
        );

        /*
        |------------------------------------------------------------------
        | The wording
        |------------------------------------------------------------------
        |
        | WhatsApp will not carry a business-initiated message without a
        | template it has approved, so `status` is the provider's answer and
        | not ours — which is why a rejected template is kept rather than
        | deleted. `icon` and `tone` are stored because the screen lists
        | templates a clinic wrote, and a row has to be able to look like
        | itself without the client keeping a map of names to pictures.
        */
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('name', 150);
            $table->string('category', 60);

            // The provider's billing class: Utility, Marketing, Transactional.
            $table->string('template_type', 40)->nullable();

            // Email has one; WhatsApp does not.
            $table->string('subject', 255)->nullable();
            $table->text('content');

            $table->string('status', 20)->default('draft');
            $table->string('icon', 60)->nullable();
            $table->string('tone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['channel', 'sort_order']);
        });

        DB::statement(
            'ALTER TABLE message_templates ADD CONSTRAINT message_templates_rules_check CHECK ('.
            'channel IN ('.$in(self::CHANNELS).') AND '.
            'status IN ('.$in(self::TEMPLATE_STATUSES).'))'
        );

        // Unique per channel among the live ones, so a deleted name frees up.
        DB::statement(
            'CREATE UNIQUE INDEX message_templates_name_unique ON message_templates (channel, lower(name)) '.
            'WHERE deleted_at IS NULL'
        );

        /*
        |------------------------------------------------------------------
        | What sends itself
        |------------------------------------------------------------------
        |
        | `event_key` is the clinic event that fires the rule, and it is what
        | the application looks a rule up by — the title is wording somebody
        | may edit. A rule points at the template it sends, and that link is
        | restricted rather than cascading: deleting a template that is still
        | automated should fail loudly, not silently stop the reminders.
        */
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('event_key', 60);
            $table->string('title', 150);
            $table->string('description', 500)->nullable();
            $table->string('icon', 60)->nullable();

            $table->foreignId('message_template_id')->nullable()
                ->constrained('message_templates')->restrictOnDelete();

            $table->boolean('is_enabled')->default(false);

            // Minutes before the event, for the rules that lead it.
            $table->integer('lead_minutes')->nullable();

            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['channel', 'event_key']);
            $table->index(['channel', 'sort_order']);
        });

        DB::statement(
            'ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_channel_check '.
            'CHECK (channel IN ('.$in(self::CHANNELS).'))'
        );

        /*
        |------------------------------------------------------------------
        | Every send
        |------------------------------------------------------------------
        |
        | `recipient` is copied rather than read back through the patient: a
        | number that has since been corrected must not rewrite the history of
        | where a message actually went. Same reason `template_name` is
        | stored beside the id — the log has to stay readable after a template
        | is renamed or removed.
        |
        | The patient link is nullable and nulls on delete, because a test
        | message belongs to nobody and a removed patient must not take the
        | delivery history with them.
        */
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('message_template_id')->nullable()
                ->constrained('message_templates')->nullOnDelete();

            // Copies, kept true to the moment of sending.
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient', 150);
            $table->string('template_name', 150)->nullable();
            $table->string('subject', 255)->nullable();

            $table->string('status', 20)->default('queued');
            $table->string('failure_reason', 500)->nullable();

            // A test proves the connection; it is not clinic traffic, and the
            // dashboard's rates would be wrong if it were counted as such.
            $table->boolean('is_test')->default(false);

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The dashboard counts by channel over a window, and the log
            // lists newest first — both are this index.
            $table->index(['channel', 'created_at']);
            $table->index(['channel', 'status']);
        });

        DB::statement(
            'ALTER TABLE message_logs ADD CONSTRAINT message_logs_rules_check CHECK ('.
            'channel IN ('.$in(self::CHANNELS).') AND '.
            'status IN ('.$in(self::DELIVERY_STATUSES).'))'
        );

        $this->seedChannels();
    }

    /**
     * A row per channel, so nothing has to cope with their absence.
     *
     * Disconnected on purpose: a clinic that has not set WhatsApp up should
     * see that it has not, rather than see an account that looks live.
     *
     * Public so it can be tested on its own, and safe to run twice.
     */
    public function seedChannels(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        foreach (self::CHANNELS as $channel) {
            DB::table('communication_channels')->updateOrInsert(
                ['channel' => $channel],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('communication_channels');
    }
};
