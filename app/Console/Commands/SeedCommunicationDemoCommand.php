<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A connected WhatsApp account with a month of traffic behind it, in one tenant.
 *
 * For looking at the screens with something on them. Everything it writes is
 * real rows through the real models, so the dashboard's figures are counted
 * the same way they will be in production — which is the point: a demo whose
 * numbers come from somewhere else proves nothing about the screen.
 *
 * Safe to run twice: templates and rules are matched on their natural keys,
 * and the log is cleared of its own demo rows before being rebuilt.
 */
class SeedCommunicationDemoCommand extends Command
{
    protected $signature = 'communication:demo
        {--org= : One organization, by uuid; defaults to the demo clinic}
        {--channel=whatsapp : Which channel to fill}';

    protected $description = 'Fill one tenant with a connected channel, templates, rules and a month of delivery history';

    /** Name, category, type, icon, tone, body. */
    private const TEMPLATES = [
        ['Appointment confirmation', 'Appointment', 'Utility', 'ti ti-calendar-check', 'sky', 'Hi {{patient_name}}, your appointment with {{doctor_name}} is confirmed on {{date}} at {{time}}. Thank you!'],
        ['Appointment reminder', 'Reminder', 'Utility', 'ti ti-bell', 'violet', 'Hi {{patient_name}}, this is a reminder for your appointment tomorrow at {{time}} with {{doctor_name}}.'],
        ['Appointment rescheduled', 'Appointment', 'Utility', 'ti ti-calendar-repeat', 'emerald', 'Hi {{patient_name}}, your appointment with {{doctor_name}} has been rescheduled to {{date}}. Please confirm.'],
        ['Appointment cancelled', 'Appointment', 'Utility', 'ti ti-circle-x', 'rose', 'Hi {{patient_name}}, your appointment on {{date}} has been cancelled. Contact us to reschedule.'],
        ['Payment confirmation', 'Payments', 'Utility', 'ti ti-file-invoice', 'sky', 'Hi {{patient_name}}, we have received your payment of ₹{{amount}} for {{service}}. Thank you!'],
        ['Follow-up reminder', 'Follow-up', 'Marketing', 'ti ti-heart', 'rose', "Hi {{patient_name}}, it's time for your follow-up visit. Book when it suits you."],
    ];

    /**
     * Event key, title, description, icon, lead minutes, on by default, and
     * the template it sends BY NAME.
     *
     * By name rather than by position: two rules legitimately send the same
     * template — the day-before and hour-before reminders are the same words
     * at different times — so the two lists never line up index for index, and
     * pairing them that way silently wires a reminder to a reschedule notice.
     */
    private const RULES = [
        [AutomationRule::APPOINTMENT_CONFIRMED, 'Send appointment confirmation', 'Confirm as soon as a patient books', 'ti ti-calendar-check', null, true, 'Appointment confirmation'],
        [AutomationRule::APPOINTMENT_REMINDER_DAY, 'Send 24-hour reminder', 'One reminder, the day before the appointment', 'ti ti-bell', 1440, true, 'Appointment reminder'],
        [AutomationRule::APPOINTMENT_REMINDER_HOUR, 'Send 1-hour reminder', 'A last nudge while there is still time to travel', 'ti ti-clock-hour-4', 60, true, 'Appointment reminder'],
        [AutomationRule::APPOINTMENT_CANCELLED, 'Send cancellation notice', 'Tell the patient when an appointment is called off', 'ti ti-circle-x', null, true, 'Appointment cancelled'],
        [AutomationRule::PAYMENT_RECEIVED, 'Send payment receipt', 'Confirm the amount once a payment goes through', 'ti ti-receipt-2', null, false, 'Payment confirmation'],
    ];

    public function handle(TenantConnectionService $tenants): int
    {
        $organization = $this->organization();

        if ($organization === null) {
            $this->error('No organization to seed. Pass --org=<uuid>.');

            return self::FAILURE;
        }

        $channel = $this->option('channel');

        $tenants->connect($organization->database_name);

        try {
            $this->connect($channel);
            $templates = $this->templates($channel);
            $this->rules($channel, $templates);
            $sent = $this->history($channel, $templates);

            $this->info("{$organization->organization_name}: {$channel} connected, ".
                count($templates).' template(s), '.count(self::RULES)." rule(s), {$sent} logged message(s).");
        } finally {
            $tenants->disconnect();
        }

        return self::SUCCESS;
    }

    /** A live account, so the connection card has something to show. */
    private function connect(string $channel): void
    {
        $connection = CommunicationChannel::forChannel($channel);

        $connection->fill([
            'display_name' => 'Clinic Care (Official)',
            'handle' => $channel === CommunicationChannel::EMAIL ? 'clinic@careplus.com' : '+91 98765 43210',
            'provider' => $channel === CommunicationChannel::EMAIL ? 'Google SMTP' : 'WhatsApp Business API',
            'is_connected' => true,
            'is_verified' => true,
            'webhook_configured' => true,
            'connected_at' => Carbon::now()->subDays(12),
            'settings' => ['business_name' => 'CarePlus Clinic'],
        ])->save();
    }

    /** @return array<int, MessageTemplate> */
    private function templates(string $channel): array
    {
        $written = [];

        foreach (self::TEMPLATES as $order => [$name, $category, $type, $icon, $tone, $body]) {
            $written[] = MessageTemplate::query()->updateOrCreate(
                ['channel' => $channel, 'name' => $name],
                [
                    'category' => $category,
                    'template_type' => $type,
                    'content' => $body,
                    'subject' => $channel === CommunicationChannel::EMAIL ? $name : null,
                    'status' => MessageTemplate::APPROVED,
                    'icon' => $icon,
                    'tone' => $tone,
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );
        }

        return $written;
    }

    /** @param  array<int, MessageTemplate>  $templates */
    private function rules(string $channel, array $templates): void
    {
        $byName = collect($templates)->keyBy('name');

        foreach (self::RULES as $order => [$key, $title, $description, $icon, $lead, $enabled, $sends]) {
            AutomationRule::query()->updateOrCreate(
                ['channel' => $channel, 'event_key' => $key],
                [
                    'title' => $title,
                    'description' => $description,
                    'icon' => $icon,
                    'lead_minutes' => $lead,
                    'message_template_id' => $byName->get($sends)?->id,
                    'is_enabled' => $enabled,
                    'sort_order' => $order,
                ],
            );
        }
    }

    /**
     * A month of traffic, weighted the way a real month looks.
     *
     * Mostly delivered, most of those read, a few failed — so the dashboard's
     * rates land somewhere believable rather than at 100%.
     *
     * @param  array<int, MessageTemplate>  $templates
     */
    /**
     * Why a message failed, per channel.
     *
     * Channel-specific because the reporting screen groups by this, and one
     * shared string would have the email report explaining that a patient's
     * NUMBER was not on WhatsApp.
     *
     * @var array<string, list<string>>
     */
    private const FAILURES = [
        CommunicationChannel::WHATSAPP => [
            'Number not on WhatsApp',
            'Template not approved by WhatsApp',
            'Outside the 24-hour session window',
        ],

        CommunicationChannel::EMAIL => [
            'Mailbox does not exist',
            'Mailbox full',
            'Rejected as spam by the receiving server',
        ],

        CommunicationChannel::SMS => [
            'Number unreachable',
            'Carrier rejected the message',
        ],
    ];

    private function history(string $channel, array $templates): int
    {
        // Only this command's own rows, so a real send is never deleted.
        MessageLog::query()->forChannel($channel)->whereNull('campaign_id')->delete();

        $customers = Customer::query()->active()->limit(40)->get();

        if ($customers->isEmpty()) {
            $this->warn('No patients in this tenant, so no delivery history was written.');

            return 0;
        }

        $written = 0;

        /*
         * Sixty days, not thirty.
         *
         * The dashboard compares the window it reports against the window
         * before it, so history that stops at the edge of the window leaves
         * nothing to compare against and the change reads as "no previous
         * period" forever.
         */
        for ($day = 59; $day >= 0; $day--) {
            // A clinic sends more on a weekday than at the weekend.
            $when = Carbon::now()->subDays($day);
            $volume = $when->isWeekend() ? random_int(4, 9) : random_int(12, 26);

            for ($i = 0; $i < $volume; $i++) {
                $customer = $customers->random();
                $template = $templates[array_rand($templates)];

                $roll = random_int(1, 100);

                $status = match (true) {
                    $roll <= 5 => MessageLog::FAILED,
                    $roll <= 26 => MessageLog::DELIVERED,
                    default => MessageLog::READ,
                };

                $at = $when->copy()->setTime(random_int(9, 19), random_int(0, 59));

                $log = new MessageLog([
                    'channel' => $channel,
                    'customer_id' => $customer->id,
                    'message_template_id' => $template->id,
                    'recipient_name' => $customer->name,
                    'recipient' => $channel === CommunicationChannel::EMAIL
                        ? ($customer->email ?: 'patient@example.com')
                        : ($customer->phone ?: '+910000000000'),
                    'template_name' => $template->name,
                    'subject' => $template->subject,
                    'status' => $status,
                    'failure_reason' => $status === MessageLog::FAILED
                        ? self::FAILURES[$channel][$i % count(self::FAILURES[$channel])]
                        : null,
                    'sent_at' => $at,
                    'delivered_at' => $status === MessageLog::FAILED ? null : $at->copy()->addSeconds(random_int(2, 40)),
                    'read_at' => $status === MessageLog::READ ? $at->copy()->addMinutes(random_int(1, 180)) : null,
                ]);

                /*
                 * `created_at` is not fillable, so mass assignment cannot set
                 * it and Eloquent would stamp every row with now() — which
                 * puts a month of history in one minute, leaves the log in no
                 * order at all (it sorts on this column) and gives the
                 * period-on-period comparison nothing to compare against.
                 */
                $log->timestamps = false;
                $log->created_at = $at;
                $log->updated_at = $at;
                $log->save();

                $written++;
            }
        }

        return $written;
    }

    private function organization(): ?Organization
    {
        if ($uuid = $this->option('org')) {
            return Organization::query()->where('uuid', $uuid)->first();
        }

        return Organization::query()
            ->whereNotNull('database_name')
            ->where('subdomain', 'demo')
            ->first()
            ?? Organization::query()->whereNotNull('database_name')->orderBy('id')->first();
    }
}
