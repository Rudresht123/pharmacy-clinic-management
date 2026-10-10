<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Services\Clinic\ClinicEvent;
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
 *      a provider for WhatsApp, switched on for App
 *      notifications                                         (communication_channels)
 *   3. the clinic's rule for this event on that channel is on (automation_rules)
 *   4. the rule names a template that is active AND approved  (message_templates)
 *   5. the patient has an address on that channel — an email,
 *      a phone, an app login                                 (customers, users)
 *
 * Any one off and that channel stays silent; the others are unaffected.
 *
 * Steps 4 and 5 and the delivery itself are ChannelSender's — shared with
 * campaigns, so a rule and a campaign can never disagree about what may be
 * sent. That is also where a branch's own wording replaces the organisation's.
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

    public function __construct(
        private readonly ChannelSender $channels,
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
     * `$onlyChannel` narrows it to one channel's rule — a reminder, whose
     * rules on different channels may each carry their own lead time.
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
        ?string $onlyChannel = null,
    ): array {
        // 1 + 2: the module runs here, and which channels are connected.
        $communication = $this->channels->communication($organization, $locationId);

        if (! $communication['enabled']) {
            return [];
        }

        // 3: the clinic's own switch, per channel.
        $rules = AutomationRule::query()
            ->with('template')
            ->where('event_key', $ruleKey)
            ->where('is_enabled', true)
            ->when($onlyChannel !== null, fn ($query) => $query->where('channel', $onlyChannel))
            ->orderBy('sort_order')
            ->get();

        $queued = [];

        foreach ($rules as $rule) {
            $channel = (string) $rule->channel;

            if (! in_array($channel, ChannelSender::DELIVERABLE, true) || ! ($communication['channels'][$channel] ?? false)) {
                continue;
            }

            // 4 + 5: an approved template for this channel, an address on it —
            // and the branch's own wording where it has one.
            if ($rule->template === null) {
                continue;
            }

            $log = $this->channels->queue(
                $organization,
                $channel,
                $customer,
                $rule->template,
                $variables,
                $locationId,
                data: ['type' => $ruleKey],
            );

            if ($log !== null) {
                $queued[] = $channel;
            }
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

        return [
            'customer' => $visit->customer,
            'variables' => $this->appointmentVariables($visit, $organization),
        ];
    }

    /**
     * An appointment as a template's variables — shared with the reminders,
     * so a confirmation and a reminder can never spell the date differently.
     *
     * @return array<string, string|int|float|null>
     */
    public function appointmentVariables(Appointment $visit, Organization $organization): array
    {
        $visit->loadMissing(['customer', 'doctor', 'location']);

        $date = $visit->appointment_date?->format('d M Y');
        $time = $visit->slot_at ? date('g:i A', strtotime((string) $visit->slot_at)) : null;

        return [
            'patient_name' => $visit->customer?->name,
            'phone' => $visit->customer?->phone,
            'doctor_name' => $visit->doctor?->name,
            'date' => $date,
            'time' => $time,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'token_no' => $visit->token_no,
            'clinic_name' => $organization->organization_name,
            'branch_name' => $visit->location?->name,
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
                'phone' => $invoice->customer->phone,
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
