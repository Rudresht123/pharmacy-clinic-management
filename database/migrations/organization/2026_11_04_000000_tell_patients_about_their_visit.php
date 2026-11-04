<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tell the patient's phone when they are checked in, called and finished.
 *
 * Ordinary rules and templates, on the App notifications screen beside every other:
 * the clinic can reword each one and switch it off. They start switched on because
 * this is what a clinic now expects a patient to hear. Nothing reaches a phone until
 * the clinic has also connected the channel, like every other patient message.
 *
 * Token called already had a rule and a template, switched off. It joins these.
 *
 * The DB facade rather than models: a migration has to keep replaying against the
 * schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** @var list<array<string, string>> */
    private const STEPS = [
        [
            'event_key' => 'queue.checked_in',
            'name' => 'You are checked in',
            'subject' => 'You are checked in',
            'content' => '{{patient_name}} is checked in for {{doctor_name}}. Your token is {{token_no}}. We will let you know when it is your turn.',
            'title' => 'Tell the patient they are checked in',
            'description' => 'A push to the patient\'s phone when the desk checks them in',
            'icon' => 'ti ti-clipboard-check',
            'tone' => 'sky',
        ],
        [
            'event_key' => 'queue.consultation_completed',
            'name' => 'Your consultation is complete',
            'subject' => 'Consultation complete',
            'content' => 'The consultation for {{patient_name}} with {{doctor_name}} is complete. Thank you for visiting {{clinic_name}}.',
            'title' => 'Tell the patient their consultation is complete',
            'description' => 'A push to the patient\'s phone when the doctor completes the consultation',
            'icon' => 'ti ti-circle-check',
            'tone' => 'emerald',
        ],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('automation_rules')
            ->where('channel', 'push')
            ->where('event_key', 'queue.token_called')
            ->where('audience', 'patient')
            ->update(['is_enabled' => true, 'updated_at' => $now]);

        foreach (self::STEPS as $step) {
            $templateId = DB::table('message_templates')
                ->where('channel', 'push')
                ->whereRaw('lower(name) = ?', [mb_strtolower($step['name'])])
                ->whereNull('deleted_at')
                ->value('id');

            $templateId ??= DB::table('message_templates')->insertGetId([
                'channel' => 'push',
                'audience' => 'patient',
                'name' => $step['name'],
                'category' => 'Appointment',
                'subject' => $step['subject'],
                'content' => $step['content'],
                'status' => 'approved',
                'icon' => $step['icon'],
                'tone' => $step['tone'],
                'is_active' => true,
                'sort_order' => (int) DB::table('message_templates')->where('channel', 'push')->max('sort_order') + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('automation_rules')->updateOrInsert(
                ['channel' => 'push', 'event_key' => $step['event_key'], 'audience' => 'patient'],
                [
                    'title' => $step['title'],
                    'description' => $step['description'],
                    'icon' => $step['icon'],
                    'message_template_id' => $templateId,
                    'is_enabled' => true,
                    'lead_minutes' => null,
                    'sort_order' => (int) DB::table('automation_rules')->where('channel', 'push')->max('sort_order') + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('automation_rules')
            ->where('channel', 'push')
            ->whereIn('event_key', array_column(self::STEPS, 'event_key'))
            ->delete();

        DB::table('message_templates')
            ->where('channel', 'push')
            ->whereIn('name', array_column(self::STEPS, 'name'))
            ->delete();

        DB::table('automation_rules')
            ->where('channel', 'push')
            ->where('event_key', 'queue.token_called')
            ->update(['is_enabled' => false]);
    }
};
