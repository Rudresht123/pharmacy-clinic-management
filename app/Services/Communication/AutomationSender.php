<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\MessageTemplate;
use App\Services\Clinic\ClinicEvent;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\Modules\FeatureResolver;
use App\Services\Permissions\Permission;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\Clinic\ClinicEvents;

/**
 * Makes the communication screen's automation rules actually send.
 *
 * Those rules — "Send appointment confirmation", "Send cancellation notice",
 * "Send payment receipt" — could be switched on, given a template and saved,
 * and then nothing anywhere ever read them. This is what reads them: called by
 * ClinicEventDispatcher after commit, beside the document automation, so a
 * booking that rolled back never emails anybody.
 *
 * SETTING-BASED, ALL THE WAY DOWN. A message is queued on a channel only when
 * every one of these is on, checked in this order:
 *
 *   1. the communication module runs at the event's branch   (FeatureResolver)
 *   2. that channel is connected — an SMTP account for email,
 *      a provider for WhatsApp                               (communication_channels)
 *   3. the clinic's rule for this event on that channel is on (automation_rules)
 *   4. the rule names a template that is active AND approved  (message_templates)
 *   5. the patient has an address on that channel             (customers)
 *
 * Any one off and that channel stays silent; the others are unaffected.
 *
 * Delivery itself is the existing EmailManager / WhatsAppManager: the message
 * is queued through them, so it lands in the same delivery log, retries the
 * same way, and goes out from the clinic's own account.
 *
 * SMS has rules on the screen but no provider in this codebase yet, so an SMS
 * rule is skipped rather than pretending to send.
 */
class AutomationSender
{
    /**
     * Which clinic event fires which automation rule. Only what is wired.
     *
     * Reminders (`appointment.reminder_day` / `_hour`) are not here: they fire
     * on a clock, not on an event.
     */
    public const RULES = [
        ClinicEvents::APPOINTMENT_BOOKED => AutomationRule::APPOINTMENT_CONFIRMED,
        ClinicEvents::APPOINTMENT_CANCELLED => AutomationRule::APPOINTMENT_CANCELLED,
        ClinicEvents::PAYMENT_RECEIVED => AutomationRule::PAYMENT_RECEIVED,
    ];

    /** The channels something can actually be handed to today. */
    private const DELIVERABLE = [CommunicationChannel::EMAIL, CommunicationChannel::WHATSAPP];

    public function __construct(
        private readonly Permission $permission,
        private readonly FeatureResolver $features,
        private readonly EmailManager $email,
        private readonly WhatsAppManager $whatsapp,
    ) {}

    /**
     * React to a clinic event.
     *
     * @return list<string> the channels a message was queued on
     */
    public function handle(ClinicEvent $event): array
    {
        $ruleKey = self::RULES[$event->key] ?? null;

        if ($ruleKey === null) {
            return [];
        }

        $organization = Organization::query()->where('database_name', $event->database)->first();

        if ($organization === null) {
            return [];
        }

        $context = match ($event->key) {
            ClinicEvents::APPOINTMENT_BOOKED,
            ClinicEvents::APPOINTMENT_CANCELLED => $this->appointment($event, $organization),
            ClinicEvents::PAYMENT_RECEIVED => $this->payment($event, $organization),
            default => null,
        };

        if ($context === null) {
            return [];
        }

        return $this->send($organization, $ruleKey, $context['customer'], $event->locationId, $context['variables']);
    }

    /**
     * Queue whatever this organization has switched on for one rule, to one
     * patient.
     *
     * @param  array<string, string|int|float|null>  $variables
     * @return list<string>
     */
    public function send(
        Organization $organization,
        string $ruleKey,
        Customer $customer,
        ?int $locationId,
        array $variables,
    ): array {
        // 1 + 2: the module runs here, and which channels are connected.
        $communication = $this->features->communicationFor(
            $this->permission->modulesAt($organization, $locationId),
        );

        if (! $communication['enabled']) {
            return [];
        }

        // 3: the clinic's own switch, per channel.
        $rules = AutomationRule::query()
            ->with('template')
            ->where('event_key', $ruleKey)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();

        $queued = [];

        foreach ($rules as $rule) {
            $channel = (string) $rule->channel;

            if (! in_array($channel, self::DELIVERABLE, true) || ! ($communication['channels'][$channel] ?? false)) {
                continue;
            }

            // 4: something approved to say, written for this channel.
            $template = $rule->template;

            if ($template === null
                || ! $template->is_active
                || $template->status !== MessageTemplate::APPROVED
                || $template->channel !== $channel) {
                continue;
            }

            // 5: somewhere to say it.
            $address = $channel === CommunicationChannel::EMAIL ? $customer->email : $customer->phone;

            if (blank($address)) {
                continue;
            }

            if ($channel === CommunicationChannel::EMAIL) {
                $this->email->forOrganization($organization)->queue(
                    EmailMessage::make(
                        to: (string) $address,
                        subject: (string) ($template->subject ?: $template->name),
                        body: (string) $template->content,
                        template: $template->name,
                        variables: $variables,
                    ),
                    $customer->id,
                    ['message_template_id' => $template->id, 'recipient_name' => $customer->name],
                );
            } else {
                $this->whatsapp->forOrganization($organization)->sendTemplate(
                    phone: (string) $address,
                    template: $template->name,
                    variables: $variables,
                    customerId: $customer->id,
                );
            }

            $queued[] = $channel;
        }

        return $queued;
    }

    /**
     * A booking or a cancellation, as the templates' variables.
     *
     * Two spellings of the date and time on purpose: templates in the wild use
     * both `{{date}}` and `{{appointment_date}}`, and a variable a template
     * names but is not given is left showing its braces.
     *
     * @return array{customer: Customer, variables: array<string, string|int|float|null>}|null
     */
    private function appointment(ClinicEvent $event, Organization $organization): ?array
    {
        // The id means nothing unless it is an appointment's: never look up
        // a different kind of record that happens to share the number.
        if ($event->subjectType !== (new Appointment)->getMorphClass()) {
            return null;
        }

        $visit = Appointment::query()->with(['customer', 'doctor', 'location'])->find($event->subjectId);

        if ($visit?->customer === null) {
            return null;
        }

        $date = $visit->appointment_date?->format('d M Y');
        $time = $visit->slot_at ? date('g:i A', strtotime((string) $visit->slot_at)) : null;

        return [
            'customer' => $visit->customer,
            'variables' => [
                'patient_name' => $visit->customer->name,
                'doctor_name' => $visit->doctor?->name,
                'date' => $date,
                'time' => $time,
                'appointment_date' => $date,
                'appointment_time' => $time,
                'token_no' => $visit->token_no,
                'clinic_name' => $organization->organization_name,
                'branch_name' => $visit->location?->name,
            ],
        ];
    }

    /**
     * Money taken against an invoice. The payment is named on the event —
     * two instalments against one bill are two receipts.
     *
     * @return array{customer: Customer, variables: array<string, string|int|float|null>}|null
     */
    private function payment(ClinicEvent $event, Organization $organization): ?array
    {
        if ($event->subjectType !== (new Invoice)->getMorphClass()) {
            return null;
        }

        $invoice = Invoice::query()->with(['customer', 'location', 'items'])->find($event->subjectId);
        $payment = isset($event->meta['payment_id'])
            ? InvoicePayment::query()->find($event->meta['payment_id'])
            : null;

        // A walk-in bill has no patient record, so nobody to write to.
        if ($invoice?->customer === null || $payment === null || $payment->is_refund) {
            return null;
        }

        return [
            'customer' => $invoice->customer,
            'variables' => [
                'patient_name' => $invoice->customer->name,
                'amount' => number_format((float) $payment->amount, 2),
                'payment_method' => $payment->method,
                'invoice_number' => $invoice->invoice_number,
                'service' => $invoice->items->first()?->description ?? 'your visit',
                'date' => $payment->paid_at?->format('d M Y'),
                'clinic_name' => $organization->organization_name,
                'branch_name' => $invoice->location?->name,
            ],
        ];
    }
}
