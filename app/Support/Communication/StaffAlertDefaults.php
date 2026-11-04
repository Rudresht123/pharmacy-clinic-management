<?php

namespace App\Support\Communication;

use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;

/**
 * The clinic telling its own people — the "Tell staff" rules every clinic
 * starts with, and their wording.
 *
 * Seeded SWITCHED ON, unlike every patient rule: they replace alerts that
 * already went out without a switch — the doctor's own on a booking or a
 * cancellation (DoctorNotifier), the desk's on a reschedule request — so a
 * clinic loses nothing on the day they arrive, and can now turn each one off,
 * or copy somebody else in (RuleRecipients).
 *
 * Email and App notifications only. A WhatsApp template has to be approved by
 * the provider for patients, and staff are reached on the app and by email.
 *
 * Written for the reader: "Rahul Tiwari is booked with Dr. Anjali", not "your
 * appointment is confirmed". The subjects are the event's own name, as the
 * doctor's alert always said it.
 */
final class StaffAlertDefaults
{
    /** Event => what the email says. */
    private const EMAIL = [
        'booked' => [
            'name' => 'Staff: appointment booked (email)',
            'subject' => 'Appointment booked',
            'body' => '<p>{{patient_name}} has been booked with {{doctor_name}}.</p>'
                .'<p>Date: {{date}}<br>Time: {{time}}<br>Branch: {{branch_name}}<br>Token: {{token_no}}</p>',
        ],
        'cancelled' => [
            'name' => 'Staff: appointment cancelled (email)',
            'subject' => 'Appointment cancelled',
            'body' => '<p>The appointment of {{patient_name}} with {{doctor_name}} on {{date}} at {{time}} has been cancelled.</p>'
                .'<p>Branch: {{branch_name}}</p>',
        ],
        'requested' => [
            'name' => 'Staff: reschedule request (email)',
            'subject' => 'Reschedule request',
            'body' => '<p>{{patient_name}} asks to move their appointment with {{doctor_name}}.</p>'
                .'<p>Now: {{old_date}}, {{old_time}}<br>Asked for: {{date}}, {{time}}<br>Reason: {{reason}}</p>'
                .'<p>Answer it from Reschedule requests.</p>',
        ],
        'moved' => [
            'name' => 'Staff: appointment moved (email)',
            'subject' => 'Appointment rescheduled',
            'body' => '<p>The appointment of {{patient_name}} with {{doctor_name}} has moved.</p>'
                .'<p>Was: {{old_date}}, {{old_time}}<br>Now: {{date}}, {{time}}<br>Branch: {{branch_name}}</p>',
        ],
    ];

    /** Event => what the App notification says. */
    private const PUSH = [
        'booked' => [
            'name' => 'Staff: appointment booked',
            'subject' => 'Appointment booked',
            'body' => '{{patient_name}} is booked with {{doctor_name}} on {{date}} at {{time}}, {{branch_name}}.',
        ],
        'cancelled' => [
            'name' => 'Staff: appointment cancelled',
            'subject' => 'Appointment cancelled',
            'body' => '{{patient_name}} with {{doctor_name}} on {{date}} at {{time}} has been cancelled.',
        ],
        'requested' => [
            'name' => 'Staff: reschedule request',
            'subject' => 'Reschedule request',
            'body' => '{{patient_name}} asks to move {{old_date}}, {{old_time}} with {{doctor_name}} to {{date}}, {{time}}.',
        ],
        'moved' => [
            'name' => 'Staff: appointment moved',
            'subject' => 'Appointment rescheduled',
            'body' => '{{patient_name}} with {{doctor_name}} has moved from {{old_date}}, {{old_time}} to {{date}} at {{time}}.',
        ],
    ];

    /**
     * Every staff template, both channels.
     *
     * @return list<array{key: string, channel: string, name: string, subject: string, body: string, icon: string, tone: string}>
     */
    public static function templates(): array
    {
        $looks = [
            'booked' => ['ti ti-calendar-plus', 'sky'],
            'cancelled' => ['ti ti-calendar-x', 'rose'],
            'requested' => ['ti ti-calendar-question', 'violet'],
            'moved' => ['ti ti-calendar-event', 'emerald'],
        ];

        $templates = [];

        foreach ([CommunicationChannel::EMAIL => self::EMAIL, CommunicationChannel::PUSH => self::PUSH] as $channel => $wording) {
            foreach ($wording as $key => $template) {
                $templates[] = [
                    'key' => $key,
                    'channel' => $channel,
                    ...$template,
                    'icon' => $looks[$key][0],
                    'tone' => $looks[$key][1],
                ];
            }
        }

        return $templates;
    }

    /**
     * The rules, the same on both channels. Who each one starts with is what
     * was told before there was a choice; `reschedule_notify` is the clinic's
     * old Appointment Settings answer for who hears about a request.
     *
     * @return list<array{event_key: string, template: string, title: string, description: string, icon: string, recipients: array<string, list<array<string, mixed>>>}>
     */
    public static function rules(string $rescheduleNotify = 'desk'): array
    {
        $doctor = [RuleRecipients::TO => [['type' => RuleRecipients::DOCTOR]]];

        $requested = match ($rescheduleNotify) {
            'doctor' => [['type' => RuleRecipients::DOCTOR]],
            // The manager answers these too, and Reception no longer includes them.
            'both' => [['type' => RuleRecipients::FRONT_DESK], ['type' => RuleRecipients::BRANCH_MANAGER], ['type' => RuleRecipients::DOCTOR]],
            default => [['type' => RuleRecipients::FRONT_DESK], ['type' => RuleRecipients::BRANCH_MANAGER]],
        };

        return [
            [
                'event_key' => AutomationRule::APPOINTMENT_CONFIRMED,
                'template' => 'booked',
                'title' => 'Tell staff: new booking',
                'description' => 'Let the clinic\'s own people know as soon as a visit is booked',
                'icon' => 'ti ti-calendar-plus',
                'recipients' => $doctor,
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_CANCELLED,
                'template' => 'cancelled',
                'title' => 'Tell staff: cancellation',
                'description' => 'When a booking is called off, at the desk or from the patient app',
                'icon' => 'ti ti-calendar-x',
                'recipients' => $doctor,
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_RESCHEDULE_REQUESTED,
                'template' => 'requested',
                'title' => 'Tell staff: reschedule request',
                'description' => 'When a patient asks from the app to move a booking',
                'icon' => 'ti ti-calendar-question',
                'recipients' => [RuleRecipients::TO => $requested],
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_RESCHEDULED,
                'template' => 'moved',
                'title' => 'Tell staff: appointment moved',
                'description' => 'When a reschedule is agreed and the visit moves to its new time',
                'icon' => 'ti ti-calendar-event',
                'recipients' => $doctor,
            ],
        ];
    }
}
