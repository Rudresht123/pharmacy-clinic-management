<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AppointmentRescheduleRequest;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DoctorQueueSetting;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Models\Tenant\LabOrder;
use App\Services\Clinic\ClinicEvent;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\Eloquent\Model;

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
 *
 * WHO. Each rule names its people (RecipientResolver): the patient, and
 * whoever the clinic copies in — the doctor, whoever booked it, the branch
 * manager, the desk, a role, a named member of staff, an address.
 *
 * STAFF RULES skip steps 1 and 2. "Tell staff: new booking" is the clinic
 * running itself — the doctor finding out who is coming — not messaging a
 * patient, so it does not wait on the communication module or a channel
 * connected for patients, any more than the doctor's own alert ever did
 * (DoctorNotifier). Its own switch, an approved template and somebody
 * reachable still decide.
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
        ClinicEvents::PATIENT_CHECKED_IN => AutomationRule::CHECKED_IN,
        ClinicEvents::TOKEN_CALLED => AutomationRule::TOKEN_CALLED,
        ClinicEvents::CONSULTATION_COMPLETED => AutomationRule::CONSULTATION_COMPLETED,
        ClinicEvents::RESCHEDULE_REQUESTED => AutomationRule::APPOINTMENT_RESCHEDULE_REQUESTED,
        ClinicEvents::APPOINTMENT_RESCHEDULED => AutomationRule::APPOINTMENT_RESCHEDULED,
        ClinicEvents::RESCHEDULE_REJECTED => AutomationRule::APPOINTMENT_RESCHEDULE_REJECTED,
        ClinicEvents::PAYMENT_RECEIVED => AutomationRule::PAYMENT_RECEIVED,
        ClinicEvents::LAB_REPORT_READY => AutomationRule::LAB_REPORT_READY,
        ClinicEvents::PATIENT_REGISTERED => AutomationRule::PATIENT_REGISTERED,
    ];

    public function __construct(
        private readonly ChannelSender $channels,
        private readonly RecipientResolver $recipients,
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
            ClinicEvents::PATIENT_CHECKED_IN,
            ClinicEvents::CONSULTATION_COMPLETED,
            ClinicEvents::APPOINTMENT_CANCELLED => $this->appointment($event, $organization),
            ClinicEvents::TOKEN_CALLED => $this->tokenCalled($event, $organization),
            ClinicEvents::RESCHEDULE_REQUESTED,
            ClinicEvents::APPOINTMENT_RESCHEDULED,
            ClinicEvents::RESCHEDULE_REJECTED => $this->reschedule($event, $organization),
            ClinicEvents::PAYMENT_RECEIVED => $this->payment($event, $organization),
            ClinicEvents::LAB_REPORT_READY => $this->labReport($event, $organization),
            ClinicEvents::PATIENT_REGISTERED => $this->registration($event, $organization),
            default => null,
        };

        if ($context === null) {
            return [];
        }

        return $this->send(
            $organization,
            $ruleKey,
            $context['customer'],
            $event->locationId,
            $context['variables'],
            subject: $context['subject'],
            event: $event->key,
        );
    }

    /**
     * Queue whatever this organization has switched on for one rule — the
     * patient's message and the staff alert, to everybody each one names.
     *
     * `$onlyChannel` narrows it to one channel's rule — a reminder, whose
     * rules on different channels may each carry their own lead time.
     * `$subject` is the record the people are found on: its doctor, who made
     * it. Without it only the patient and the people named outright are told.
     *
     * A push says what it is about in `type`: the rule's key on a patient's
     * phone, which the patient app routes by, and the clinic event's on staff
     * phones — `appointment_booked`, as the doctor's own alert always said it
     * (DoctorNotifier), which the staff app routes by.
     *
     * @param  array<string, string|int|float|null>  $variables
     * @return list<string> the channels a patient message was queued on
     */
    public function send(
        Organization $organization,
        string $ruleKey,
        Customer $customer,
        ?int $locationId,
        array $variables,
        ?string $onlyChannel = null,
        ?Model $subject = null,
        ?string $event = null,
    ): array {
        // 1 + 2: the module runs here, and which channels are connected.
        $communication = $this->channels->communication($organization, $locationId);

        // 3: the clinic's own switch, per channel and audience.
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

            if (! in_array($channel, ChannelSender::DELIVERABLE, true)) {
                continue;
            }

            // Patient messaging is the communication module's; a staff alert
            // is the clinic's own (see the class comment).
            if (! $rule->isForStaff() && (! $communication['enabled'] || ! ($communication['channels'][$channel] ?? false))) {
                continue;
            }

            // 4 + 5: an approved template for this channel, somebody to
            // reach on it — and the branch's own wording where it has one.
            if ($rule->template === null) {
                continue;
            }

            // Each phone in the type its own app routes by, whichever rule named it.
            $logs = $this->channels->queueTo(
                $organization,
                $channel,
                $this->recipients->resolve($organization, $rule, $subject, $customer, $locationId),
                $customer,
                $rule->template,
                $variables,
                $locationId,
                data: ['type' => $ruleKey] + $this->references($subject),
                staffData: ['type' => $event ?? $ruleKey] + $this->references($subject),
            );

            if ($logs !== [] && ! $rule->isForStaff()) {
                $queued[] = $channel;
            }
        }

        return $queued;
    }

    /**
     * Which records a push is about, for the app to open — ids only.
     *
     * @return array<string, int>
     */
    private function references(?Model $subject): array
    {
        return match (true) {
            $subject instanceof Appointment => ['appointment_id' => (int) $subject->getKey()],
            $subject instanceof AppointmentRescheduleRequest => [
                'appointment_id' => (int) $subject->appointment_id,
                'request_id' => (int) $subject->getKey(),
            ],
            $subject !== null => ['subject_id' => (int) $subject->getKey()],
            default => [],
        };
    }

    /**
     * A booking or a cancellation, as the templates' variables.
     *
     * Two spellings of the date and time on purpose: templates in the wild use
     * both `{{date}}` and `{{appointment_date}}`, and a variable a template
     * names but is not given is left showing its braces.
     *
     * @return array{customer: Customer, subject: Model, variables: array<string, string|int|float|null>}|null
     */
    private function appointment(ClinicEvent $event, Organization $organization): ?array
    {
        // The id means nothing unless it is an appointment's: never look up
        // a different kind of record that happens to share the number.
        if ($event->subjectType !== (new Appointment)->getMorphClass()) {
            return null;
        }

        $visit = Appointment::query()->with(['customer', 'doctor.user', 'location'])->find($event->subjectId);

        if ($visit?->customer === null) {
            return null;
        }

        return [
            'customer' => $visit->customer,
            'subject' => $visit,
            'variables' => $this->appointmentVariables($visit, $organization),
        ];
    }

    /**
     * A token called: the appointment's variables, and which room to walk to.
     *
     * Worded so the sentence still reads when no room has been set.
     *
     * @return array{customer: Customer, subject: Model, variables: array<string, string|int|float|null>}|null
     */
    private function tokenCalled(ClinicEvent $event, Organization $organization): ?array
    {
        $context = $this->appointment($event, $organization);

        if ($context === null) {
            return null;
        }

        $room = DoctorQueueSetting::query()
            ->where('doctor_id', $context['subject']->doctor_id)
            ->where('location_id', $context['subject']->location_id)
            ->value('room_no');

        $context['variables']['room'] = $room ? "Room {$room}" : 'the consulting room';

        return $context;
    }

    /**
     * A reschedule request asked, agreed or refused.
     *
     * `{{date}}`/`{{time}}` are where the visit is going — the time asked for
     * while it waits, the new time once agreed, the time it keeps when
     * refused — and `{{old_date}}`/`{{old_time}}` where it was.
     *
     * @return array{customer: Customer, subject: Model, variables: array<string, string|int|float|null>}|null
     */
    private function reschedule(ClinicEvent $event, Organization $organization): ?array
    {
        if ($event->subjectType !== (new AppointmentRescheduleRequest)->getMorphClass()) {
            return null;
        }

        $request = AppointmentRescheduleRequest::query()
            ->with(['customer', 'doctor.user', 'appointment.location'])
            ->find($event->subjectId);

        if ($request?->customer === null || $request->appointment === null) {
            return null;
        }

        $variables = $this->appointmentVariables($request->appointment, $organization);
        $time = fn (mixed $slot) => $slot ? date('g:i A', strtotime((string) $slot)) : null;

        if ($event->key === ClinicEvents::RESCHEDULE_REQUESTED) {
            $variables['date'] = $variables['appointment_date'] = $request->requested_date?->format('d M Y');
            $variables['time'] = $variables['appointment_time'] = $time($request->requested_slot_at);
        }

        return [
            'customer' => $request->customer,
            'subject' => $request,
            'variables' => $variables + [
                'old_date' => $request->from_date?->format('d M Y'),
                'old_time' => $time($request->from_slot_at),
                'reason' => $request->reason ?: 'none given',
                'clinic_note' => (string) $request->decision_note,
            ],
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
            'token_no' => $visit->tokenLabel(),
            'clinic_name' => $organization->organization_name,
            'branch_name' => $visit->location?->name,
        ];
    }

    /**
     * Money taken against an invoice. The payment is named on the event —
     * two instalments against one bill are two receipts.
     *
     * @return array{customer: Customer, subject: Model, variables: array<string, string|int|float|null>}|null
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
            'subject' => $invoice,
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

    /**
     * A finished lab order, as a template's variables.
     *
     * LabOrder names its patient relation `patient()`, not `customer()` — the
     * same spelling Prescription uses and everything else here does not.
     *
     * @return array{customer: Customer, subject: Model, variables: array<string, string|int|float|null>}|null
     */
    private function labReport(ClinicEvent $event, Organization $organization): ?array
    {
        if ($event->subjectType !== (new LabOrder)->getMorphClass()) {
            return null;
        }

        $order = LabOrder::query()->with(['patient', 'doctor', 'location'])->find($event->subjectId);

        if ($order?->patient === null) {
            return null;
        }

        return [
            'customer' => $order->patient,
            'subject' => $order,
            'variables' => [
                'patient_name' => $order->patient->name,
                'phone' => $order->patient->phone,
                'doctor_name' => $order->doctor?->name,
                'date' => $order->order_date?->format('d M Y'),
                'clinic_name' => $organization->organization_name,
                'branch_name' => $order->location?->name,
            ],
        ];
    }

    /**
     * A new patient's record, as a template's variables.
     *
     * The one event whose subject IS the customer rather than something that
     * belongs to one — everywhere else here reads `$record->customer`; this
     * reads the record itself.
     *
     * @return array{customer: Customer, subject: Model, variables: array<string, string|int|float|null>}|null
     */
    private function registration(ClinicEvent $event, Organization $organization): ?array
    {
        if ($event->subjectType !== (new Customer)->getMorphClass()) {
            return null;
        }

        $patient = Customer::query()->find($event->subjectId);

        if ($patient === null) {
            return null;
        }

        return [
            'customer' => $patient,
            'subject' => $patient,
            'variables' => [
                'patient_name' => $patient->name,
                'phone' => $patient->phone,
                'clinic_name' => $organization->organization_name,
            ],
        ];
    }
}
