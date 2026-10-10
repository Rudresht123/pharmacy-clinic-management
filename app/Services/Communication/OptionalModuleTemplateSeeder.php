<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\MessageTemplate;
use App\Services\Modules\ModuleAccess;
use App\Services\Tenancy\TenantConnectionService;

/**
 * Default templates for events that only exist when an optional module does.
 *
 * SEPARATE FROM EmailDefaults/PushDefaults, and seeded from a SERVICE rather
 * than a migration, for the same reason DefaultTemplateSeeder is: deciding
 * whether `lab.report_ready` applies needs this organisation's module
 * entitlements, which live in the MASTER database — a tenant migration
 * cannot read them, since the migrator repoints `database.default` at the
 * tenant while it runs.
 *
 * Keyed by module so a later module's defaults (pharmacy, say) are another
 * array entry here, not a new class.
 *
 * IDEMPOTENT by (channel, name) / (channel, event_key) — an organisation
 * that already has a row keeps it; this only adds what is missing, and never
 * edits or replaces, the same contract DefaultTemplateSeeder keeps for
 * document templates.
 */
class OptionalModuleTemplateSeeder
{
    public function __construct(
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * @return list<string> the event keys actually created
     */
    public function seed(Organization $organization): array
    {
        $connection = TenantConnectionService::CONNECTION;
        $created = [];

        foreach ($this->catalog() as $moduleKey => $entries) {
            if (! $this->modules->has($organization, $moduleKey)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($this->seedOne($connection, $entry)) {
                    $created[] = $entry['event_key'];
                }
            }
        }

        return $created;
    }

    /**
     * @param  array{channel: string, name: string, category: string, subject: ?string, body: string, icon: string, tone: string, event_key: string, title: string, description: string}  $entry
     */
    private function seedOne(string $connection, array $entry): bool
    {
        $existingRule = AutomationRule::on($connection)
            ->where('channel', $entry['channel'])
            ->where('event_key', $entry['event_key'])
            ->exists();

        if ($existingRule) {
            return false;
        }

        $template = MessageTemplate::on($connection)
            ->where('channel', $entry['channel'])
            ->whereRaw('lower(name) = ?', [mb_strtolower($entry['name'])])
            ->first();

        $template ??= MessageTemplate::on($connection)->create([
            'channel' => $entry['channel'],
            'name' => $entry['name'],
            'category' => $entry['category'],
            'subject' => $entry['subject'],
            'content' => $entry['body'],
            'status' => MessageTemplate::APPROVED,
            'icon' => $entry['icon'],
            'tone' => $entry['tone'],
            'is_active' => true,
        ]);

        AutomationRule::on($connection)->create([
            'channel' => $entry['channel'],
            'event_key' => $entry['event_key'],
            'title' => $entry['title'],
            'description' => $entry['description'],
            'icon' => $entry['icon'],
            'message_template_id' => $template->id,
            'is_enabled' => false,
        ]);

        return true;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function catalog(): array
    {
        return [
            'laboratory' => [
                [
                    'channel' => 'email',
                    'name' => 'Lab report ready (email)',
                    'category' => 'Laboratory',
                    'subject' => 'Your lab report is ready — {{clinic_name}}',
                    'body' => '<p>Hello {{patient_name}},</p>'
                        .'<p>Your lab report from {{date}} is ready. Please contact {{clinic_name}} for the results.</p>',
                    'icon' => 'ti ti-flask',
                    'tone' => 'emerald',
                    'event_key' => AutomationRule::LAB_REPORT_READY,
                    'title' => 'Send lab report ready notice',
                    'description' => 'Tell the patient once their lab order is signed off',
                ],
            ],
        ];
    }
}
