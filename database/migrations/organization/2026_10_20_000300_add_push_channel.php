<?php

use App\Support\Communication\PushDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * App notifications — push to the patient app — as a channel beside WhatsApp,
 * email and SMS.
 *
 * Everything the other channels have, it now has too: a template library,
 * automation rules, campaigns and the delivery log. Those tables CHECK the
 * channel against a list, so the list is rebuilt with `push` in it — Postgres
 * has no "add a value to a check", which is why the communication migrations
 * rebuild rather than alter.
 *
 * Then the GLOBAL level: the platform's default templates and rules
 * (App\Support\Communication\PushDefaults) go into this organisation's
 * library, so the screen opens on a working set to reword. The rules arrive
 * SWITCHED OFF and the channel DISCONNECTED: nothing reaches a phone until the
 * clinic decides it should.
 */
return new class extends Migration
{
    private const CHANNELS = ['whatsapp', 'email', 'sms', 'push'];

    private const OLD_CHANNELS = ['whatsapp', 'email', 'sms'];

    private const TEMPLATE_STATUSES = ['draft', 'pending', 'approved', 'rejected'];

    private const DELIVERY_STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed'];

    private const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'completed', 'paused', 'failed', 'cancelled'];

    public function up(): void
    {
        $this->constraints(self::CHANNELS);

        $now = now();

        DB::table('communication_channels')->updateOrInsert(
            ['channel' => 'push'],
            ['display_name' => 'App notifications', 'is_connected' => false, 'created_at' => $now, 'updated_at' => $now],
        );

        $ids = [];

        foreach (PushDefaults::templates() as $order => $template) {
            $existing = DB::table('message_templates')
                ->where('channel', 'push')
                ->whereRaw('lower(name) = ?', [mb_strtolower($template['name'])])
                ->whereNull('deleted_at')
                ->value('id');

            $ids[$template['key']] = $existing ?? DB::table('message_templates')->insertGetId([
                'channel' => 'push',
                'name' => $template['name'],
                'category' => $template['category'],
                'subject' => $template['title'],
                'content' => $template['body'],
                'status' => 'approved',
                'icon' => $template['icon'],
                'tone' => $template['tone'],
                'is_active' => true,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (PushDefaults::rules() as $order => $rule) {
            DB::table('automation_rules')->updateOrInsert(
                ['channel' => 'push', 'event_key' => $rule['event_key']],
                [
                    'title' => $rule['title'],
                    'description' => $rule['description'],
                    'icon' => $rule['icon'],
                    'message_template_id' => $ids[$rule['template']],
                    'is_enabled' => false,
                    'lead_minutes' => $rule['lead_minutes'],
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('automation_rules')->where('channel', 'push')->delete();
        DB::table('message_logs')->where('channel', 'push')->delete();
        DB::table('message_templates')->where('channel', 'push')->delete();
        DB::table('communication_channels')->where('channel', 'push')->delete();

        $this->constraints(self::OLD_CHANNELS);
    }

    /** @param  list<string>  $channels */
    private function constraints(array $channels): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        DB::statement('ALTER TABLE communication_channels DROP CONSTRAINT communication_channels_channel_check');
        DB::statement(
            'ALTER TABLE communication_channels ADD CONSTRAINT communication_channels_channel_check '.
            'CHECK (channel IN ('.$in($channels).'))'
        );

        DB::statement('ALTER TABLE message_templates DROP CONSTRAINT message_templates_rules_check');
        DB::statement(
            'ALTER TABLE message_templates ADD CONSTRAINT message_templates_rules_check CHECK ('.
            'channel IN ('.$in($channels).') AND '.
            'status IN ('.$in(self::TEMPLATE_STATUSES).'))'
        );

        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT automation_rules_channel_check');
        DB::statement(
            'ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_channel_check '.
            'CHECK (channel IN ('.$in($channels).'))'
        );

        DB::statement('ALTER TABLE message_logs DROP CONSTRAINT message_logs_rules_check');
        DB::statement(
            'ALTER TABLE message_logs ADD CONSTRAINT message_logs_rules_check CHECK ('.
            'channel IN ('.$in($channels).') AND '.
            'status IN ('.$in(self::DELIVERY_STATUSES).'))'
        );

        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT campaigns_rules_check');
        DB::statement(
            'ALTER TABLE campaigns ADD CONSTRAINT campaigns_rules_check CHECK ('.
            'channel IN ('.$in($channels).') AND '.
            'status IN ('.$in(self::CAMPAIGN_STATUSES).') AND '.
            "(status <> 'scheduled' OR scheduled_at IS NOT NULL))"
        );
    }
};
