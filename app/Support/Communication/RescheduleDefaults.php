<?php

namespace App\Support\Communication;

use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\CommunicationChannel;

/**
 * The patient's side of a reschedule — told when the clinic says yes, and
 * when it says no.
 *
 * Both used to be pushed without a switch (RescheduleRequests), so the App
 * notification rules arrive SWITCHED ON to keep doing that — now through the
 * App notifications channel like any patient message. The email rules arrive
 * off, as every patient email does. No WhatsApp: its wording is what the
 * provider approves, not what is seeded here.
 */
final class RescheduleDefaults
{
    /**
     * @return list<array{key: string, channel: string, name: string, subject: string, body: string, icon: string, tone: string}>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'moved',
                'channel' => CommunicationChannel::PUSH,
                'name' => 'Appointment rescheduled',
                'subject' => 'Appointment rescheduled',
                'body' => 'Hi {{patient_name}}, your appointment with {{doctor_name}} is now on {{date}} at {{time}}.',
                'icon' => 'ti ti-calendar-event',
                'tone' => 'emerald',
            ],
            [
                'key' => 'refused',
                'channel' => CommunicationChannel::PUSH,
                'name' => 'Reschedule not possible',
                'subject' => 'Reschedule not possible',
                'body' => 'Hi {{patient_name}}, your appointment with {{doctor_name}} stays on {{date}} at {{time}}. {{clinic_note}}',
                'icon' => 'ti ti-calendar-cancel',
                'tone' => 'rose',
            ],
            [
                'key' => 'moved',
                'channel' => CommunicationChannel::EMAIL,
                'name' => 'Appointment rescheduled (email)',
                'subject' => 'Appointment rescheduled — {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>Your appointment with {{doctor_name}} has moved.</p>'
                    .'<p>New date: {{date}}<br>New time: {{time}}<br>Clinic: {{clinic_name}}</p>',
                'icon' => 'ti ti-calendar-event',
                'tone' => 'emerald',
            ],
            [
                'key' => 'refused',
                'channel' => CommunicationChannel::EMAIL,
                'name' => 'Reschedule not possible (email)',
                'subject' => 'Your appointment stays as booked — {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>We could not move your appointment with {{doctor_name}}. It stays on {{date}} at {{time}}.</p>'
                    .'<p>{{clinic_note}}</p>',
                'icon' => 'ti ti-calendar-cancel',
                'tone' => 'rose',
            ],
        ];
    }

    /**
     * @return list<array{event_key: string, template: string, title: string, description: string, icon: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'event_key' => AutomationRule::APPOINTMENT_RESCHEDULED,
                'template' => 'moved',
                'title' => 'Send reschedule confirmation',
                'description' => 'Tell the patient their visit has moved, once the clinic agrees',
                'icon' => 'ti ti-calendar-event',
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_RESCHEDULE_REJECTED,
                'template' => 'refused',
                'title' => 'Send reschedule refusal',
                'description' => 'Tell the patient when the clinic cannot move their visit',
                'icon' => 'ti ti-calendar-cancel',
            ],
        ];
    }
}
