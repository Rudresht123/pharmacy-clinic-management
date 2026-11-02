<?php

use App\Support\Communication\RescheduleDefaults;
use App\Support\Communication\StaffAlertDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a rule tells — To, CC and BCC — and rules that tell the clinic's own
 * people rather than the patient.
 *
 * `recipients` names places on the record (the visit's doctor, whoever booked
 * it, the branch manager, the desk), roles, people and addresses
 * (App\Support\Communication\RuleRecipients). Every rule so far went to the
 * patient alone, and is written down as exactly that.
 *
 * `audience` lets an event have a patient rule AND a staff rule on the same
 * channel, each in its own words, so the unique key gains it. Templates carry
 * it too, so staff wording is never offered to a campaign or a patient rule.
 *
 * Seeds what used to be sent without any switch, now as switchable rules:
 * the doctor's alert on a booking and a cancellation (DoctorNotifier), the
 * desk's on a reschedule request — to whoever this clinic's Appointment
 * Settings named — and the patient's push when a request is answered. One
 * new alert: the doctor hears when a visit moves.
 *
 * Idempotent in the shape of 2026_10_23_000000_add_email_and_registration_
 * templates: runs for every new organisation on provisioning, and for the
 * existing ones through `tenants:migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->string('audience', 10)->default('patient');
            $table->jsonb('recipients')->nullable();
        });

        DB::statement("ALTER TABLE automation_rules ADD CONSTRAINT automation_rules_audience_check CHECK (audience IN ('patient', 'staff'))");
        DB::statement('DROP INDEX IF EXISTS automation_rules_event_unique');
        DB::statement(
            'CREATE UNIQUE INDEX automation_rules_event_unique ON automation_rules (channel, event_key, audience) '.
            'WHERE deleted_at IS NULL'
        );

        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('audience', 10)->default('patient');
        });

        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_audience_check CHECK (audience IN ('patient', 'staff'))");

        // What every rule so far did, written down.
        DB::table('automation_rules')
            ->whereNull('recipients')
            ->update(['recipients' => json_encode(['to' => [['type' => 'patient']]])]);

        $now = now();

        $staff = $this->templates(StaffAlertDefaults::templates(), 'staff', 'Staff alert', $now);
        $patient = $this->templates(RescheduleDefaults::templates(), 'patient', 'Appointment', $now);

        $notify = DB::table('appointment_settings')->value('reschedule_notify') ?? 'desk';

        foreach (['email', 'push'] as $channel) {
            foreach (StaffAlertDefaults::rules($notify) as $order => $rule) {
                $this->rule($channel, 'staff', $rule, $staff[$channel][$rule['template']], true, 100 + $order, $now);
            }

            foreach (RescheduleDefaults::rules() as $order => $rule) {
                // The push was always sent; the email is the clinic's to switch on.
                $this->rule($channel, 'patient', $rule + [
                    'recipients' => ['to' => [['type' => 'patient']]],
                ], $patient[$channel][$rule['template']], $channel === 'push', 50 + $order, $now);
            }
        }
    }

    public function down(): void
    {
        $events = ['appointment.reschedule_requested', 'appointment.rescheduled', 'appointment.reschedule_rejected'];

        DB::table('automation_rules')->where('audience', 'staff')->delete();
        DB::table('automation_rules')->whereIn('event_key', $events)->delete();

        $names = array_map(
            fn (array $template) => mb_strtolower($template['name']),
            [...StaffAlertDefaults::templates(), ...RescheduleDefaults::templates()],
        );

        DB::table('message_templates')
            ->whereRaw('lower(name) IN ('.implode(', ', array_fill(0, count($names), '?')).')', $names)
            ->whereNotExists(fn ($query) => $query->from('automation_rules')->whereColumn('automation_rules.message_template_id', 'message_templates.id'))
            ->delete();

        DB::statement('DROP INDEX IF EXISTS automation_rules_event_unique');
        DB::statement('ALTER TABLE message_templates DROP CONSTRAINT IF EXISTS message_templates_audience_check');
        DB::statement('ALTER TABLE automation_rules DROP CONSTRAINT IF EXISTS automation_rules_audience_check');

        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn('audience'));
        Schema::table('automation_rules', fn (Blueprint $table) => $table->dropColumn(['audience', 'recipients']));

        DB::statement(
            'CREATE UNIQUE INDEX automation_rules_event_unique ON automation_rules (channel, event_key) '.
            'WHERE deleted_at IS NULL'
        );
    }

    /**
     * The templates, found by name where the clinic already has one —
     * reworded, perhaps, but theirs — and written where not.
     *
     * @param  list<array{key: string, channel: string, name: string, subject: string, body: string, icon: string, tone: string}>  $templates
     * @return array<string, array<string, int>> channel => key => id
     */
    private function templates(array $templates, string $audience, string $category, mixed $now): array
    {
        $ids = [];

        foreach ($templates as $order => $template) {
            $existing = DB::table('message_templates')
                ->where('channel', $template['channel'])
                ->whereRaw('lower(name) = ?', [mb_strtolower($template['name'])])
                ->whereNull('deleted_at')
                ->value('id');

            $ids[$template['channel']][$template['key']] = $existing ?? DB::table('message_templates')->insertGetId([
                'channel' => $template['channel'],
                'name' => $template['name'],
                'category' => $category,
                'subject' => $template['subject'],
                'content' => $template['body'],
                'status' => 'approved',
                'icon' => $template['icon'],
                'tone' => $template['tone'],
                'is_active' => true,
                'audience' => $audience,
                'sort_order' => 100 + $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @param  array{event_key: string, title: string, description: string, icon: string, recipients: array<string, mixed>}  $rule */
    private function rule(string $channel, string $audience, array $rule, int $templateId, bool $enabled, int $order, mixed $now): void
    {
        DB::table('automation_rules')->updateOrInsert(
            ['channel' => $channel, 'event_key' => $rule['event_key'], 'audience' => $audience],
            [
                'title' => $rule['title'],
                'description' => $rule['description'],
                'icon' => $rule['icon'],
                'message_template_id' => $templateId,
                'recipients' => json_encode($rule['recipients']),
                'is_enabled' => $enabled,
                'lead_minutes' => null,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
};
