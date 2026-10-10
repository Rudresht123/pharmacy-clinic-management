<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Services\Clinic\ClinicEvent;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\Push\PushManager;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tells the doctor themselves that something just happened under their name.
 *
 * Not AutomationSender's concern: that screen's rules, channels and approved
 * templates are all about the PATIENT's own wording and the clinic's own
 * switches, and only cover the handful of events a clinic can configure a
 * rule for. A doctor finding out their patient booked, paid, was issued a
 * prescription, or had a sale rung up at the counter is not a marketing
 * message with a template to approve — every clinic event with a doctor on
 * it reaches them, unconditionally, on whatever they actually have: an app
 * login, an email on file.
 */
class DoctorNotifier
{
    public function __construct(
        private readonly PushManager $push,
        private readonly EmailManager $email,
    ) {}

    public function handle(ClinicEvent $event): void
    {
        $subject = $this->subject($event);

        if ($subject === null || $subject->doctor === null) {
            return;
        }

        $organization = Organization::query()->where('database_name', $event->database)->first();

        if ($organization === null) {
            return;
        }

        $title = ClinicEvents::all()[$event->key]['label'] ?? $event->key;
        $body = $this->body($title, $subject);

        $this->notifyByPush($organization, $subject, $event, $title, $body);
        $this->notifyByEmail($organization, $subject, $title, $body);
    }

    /**
     * The record an event is about, with its doctor and patient loaded —
     * every subject a clinic event names carries both, by `doctor_id` and
     * `customer_id`, so this reads generically rather than once per event.
     */
    private function subject(ClinicEvent $event): ?Model
    {
        $class = $event->subjectType;

        if (! class_exists($class) || ! method_exists($class, 'doctor')) {
            return null;
        }

        // Every subject names its patient, but not always by the same
        // relation — Prescription says `patient()`, everything else `customer()`.
        $patientRelation = method_exists($class, 'customer') ? 'customer' : 'patient';

        return $class::query()->with(['doctor.user', $patientRelation])->find($event->subjectId);
    }

    /** "{Label} — {patient}, on {date}" where the subject has a date to show. */
    private function body(string $title, Model $subject): string
    {
        $patient = $subject->customer?->name ?? $subject->patient?->name ?? 'a patient';

        $date = $subject->getAttribute('appointment_date') ?? $subject->getAttribute('order_date');
        $slot = $subject->getAttribute('slot_at');

        $when = $date
            ? Carbon::parse($date)->format('d M Y').($slot ? ' at '.date('g:i A', strtotime((string) $slot)) : '')
            : null;

        return trim(sprintf('%s — %s%s', $title, $patient, $when ? ", {$when}" : '.'));
    }

    private function notifyByPush(
        Organization $organization,
        Model $subject,
        ClinicEvent $event,
        string $title,
        string $body,
    ): void {
        $login = $subject->doctor->user;

        if ($login === null || ! $login->is_active) {
            return;
        }

        $this->push->queueToUsers(
            $organization,
            [$login->id],
            $subject->doctor->name,
            $title,
            $body,
            ['type' => $event->key, 'subject_id' => $subject->getKey()],
            ['recipient_name' => $subject->doctor->name],
        );
    }

    private function notifyByEmail(Organization $organization, Model $subject, string $title, string $body): void
    {
        $address = $subject->doctor->email;

        if (blank($address)) {
            return;
        }

        $this->email->forOrganization($organization)->queue(
            EmailMessage::make(to: $address, subject: $title, body: $body),
            customerId: null,
            extra: ['recipient_name' => $subject->doctor->name],
        );
    }
}
