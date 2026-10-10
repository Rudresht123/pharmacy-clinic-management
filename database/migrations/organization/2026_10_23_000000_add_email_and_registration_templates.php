<?php

use App\Support\Communication\EmailDefaults;
use App\Support\Communication\PushDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email gets the same starting library push already has, and a new event —
 * `patient.registered` — gets a default on both channels.
 *
 * Every event here already fires: `appointment.confirmed`/`cancelled` and
 * `payment.received` on their own ClinicEvent, the two reminders on the
 * existing reminder clock. Only the DEFAULT TEMPLATES were missing for email
 * — the push channel got its library in 2026_10_20_000300_add_push_channel;
 * this is that migration's email counterpart, same idempotent shape.
 *
 * No channel/event_key CHECK constraint to widen: `email` was already an
 * allowed channel from the first communication migration, and `event_key`
 * carries no CHECK at all (see 2026_10_01_000000_create_communication_tables).
 *
 * Runs for every new organisation via the existing `migrate_tenant`
 * provisioning step, and backfills existing ones via the existing
 * `tenants:migrate` command — no new mechanism needed for content that isn't
 * gated on an optional module (see OptionalModuleTemplateSeeder for the one
 * that is).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $emailIds = [];

        foreach (EmailDefaults::templates() as $order => $template) {
            $existing = DB::table('message_templates')
                ->where('channel', 'email')
                ->whereRaw('lower(name) = ?', [mb_strtolower($template['name'])])
                ->whereNull('deleted_at')
                ->value('id');

            $emailIds[$template['key']] = $existing ?? DB::table('message_templates')->insertGetId([
                'channel' => 'email',
                'name' => $template['name'],
                'category' => $template['category'],
                'subject' => $template['subject'],
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

        foreach (EmailDefaults::rules() as $order => $rule) {
            DB::table('automation_rules')->updateOrInsert(
                ['channel' => 'email', 'event_key' => $rule['event_key']],
                [
                    'title' => $rule['title'],
                    'description' => $rule['description'],
                    'icon' => $rule['icon'],
                    'message_template_id' => $emailIds[$rule['template']],
                    'is_enabled' => false,
                    'lead_minutes' => $rule['lead_minutes'],
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        /*
         * The new push "registered" template, added to PushDefaults since
         * this migration runs; a push channel already seeded (every tenant,
         * by 2026_10_20_000300) only needs the one new row, found the same
         * idempotent way as every template lookup above.
         */
        $registered = PushDefaults::templates()[count(PushDefaults::templates()) - 1];

        $pushId = DB::table('message_templates')
            ->where('channel', 'push')
            ->whereRaw('lower(name) = ?', [mb_strtolower($registered['name'])])
            ->whereNull('deleted_at')
            ->value('id');

        if ($pushId === null) {
            $pushId = DB::table('message_templates')->insertGetId([
                'channel' => 'push',
                'name' => $registered['name'],
                'category' => $registered['category'],
                'subject' => $registered['title'],
                'content' => $registered['body'],
                'status' => 'approved',
                'icon' => $registered['icon'],
                'tone' => $registered['tone'],
                'is_active' => true,
                'sort_order' => count(PushDefaults::templates()) - 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pushRule = collect(PushDefaults::rules())->firstWhere('template', 'registered');

        DB::table('automation_rules')->updateOrInsert(
            ['channel' => 'push', 'event_key' => $pushRule['event_key']],
            [
                'title' => $pushRule['title'],
                'description' => $pushRule['description'],
                'icon' => $pushRule['icon'],
                'message_template_id' => $pushId,
                'is_enabled' => false,
                'lead_minutes' => $pushRule['lead_minutes'],
                'sort_order' => count(PushDefaults::rules()) - 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('automation_rules')->where('channel', 'email')->delete();
        DB::table('automation_rules')->where('channel', 'push')->where('event_key', 'patient.registered')->delete();
        DB::table('message_logs')->where('channel', 'email')->delete();
        DB::table('message_templates')->where('channel', 'email')->delete();

        $registered = PushDefaults::templates()[count(PushDefaults::templates()) - 1];

        DB::table('message_templates')
            ->where('channel', 'push')
            ->whereRaw('lower(name) = ?', [mb_strtolower($registered['name'])])
            ->delete();
    }
};
