<?php

namespace App\Support\Communication;

use App\Models\Tenant\AutomationRule;

/**
 * The platform's own App-notification templates and rules — the GLOBAL level
 * every clinic starts from.
 *
 * Seeded into each organisation's library (message_templates, channel `push`)
 * when its database is created, so a clinic opens the App notifications screen
 * to a working set it can reword — the ORGANISATION level — and, per branch,
 * reword again (message_template_variants — the BRANCH level).
 *
 * The rules arrive SWITCHED OFF. Notifying patients is the clinic's decision,
 * the same as email and WhatsApp; nothing reaches a phone until somebody turns
 * a rule on, with the channel connected and the template approved.
 *
 * Variables are the ones the automation fills (see AutomationSender and
 * MessageVariables) — `{{date}}`, `{{time}}`, `{{doctor_name}}` and the rest.
 */
final class PushDefaults
{
    /**
     * @return list<array{key: string, name: string, category: string, title: string, body: string, icon: string, tone: string}>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'confirmed',
                'name' => 'Appointment confirmed',
                'category' => 'Appointment',
                'title' => 'Appointment confirmed',
                'body' => 'Hi {{patient_name}}, your appointment with {{doctor_name}} is confirmed for {{date}} at {{time}}.',
                'icon' => 'ti ti-calendar-check',
                'tone' => 'sky',
            ],
            [
                'key' => 'reminder',
                'name' => 'Appointment reminder',
                'category' => 'Reminder',
                'title' => 'Appointment reminder',
                'body' => 'Hi {{patient_name}}, a reminder of your appointment with {{doctor_name}} on {{date}} at {{time}}.',
                'icon' => 'ti ti-bell',
                'tone' => 'violet',
            ],
            [
                'key' => 'cancelled',
                'name' => 'Appointment cancelled',
                'category' => 'Appointment',
                'title' => 'Appointment cancelled',
                'body' => 'Hi {{patient_name}}, your appointment with {{doctor_name}} on {{date}} has been cancelled.',
                'icon' => 'ti ti-circle-x',
                'tone' => 'rose',
            ],
            [
                'key' => 'payment',
                'name' => 'Payment received',
                'category' => 'Payments',
                'title' => 'Payment received',
                'body' => 'Hi {{patient_name}}, we have received ₹{{amount}} for {{service}}. Thank you!',
                'icon' => 'ti ti-receipt-2',
                'tone' => 'emerald',
            ],
            [
                'key' => 'registered',
                'name' => 'Welcome — patient registered',
                'category' => 'General',
                'title' => 'Welcome to {{clinic_name}}',
                'body' => 'Hi {{patient_name}}, your record has been created. We look forward to seeing you.',
                'icon' => 'ti ti-user-plus',
                'tone' => 'sky',
            ],
        ];
    }

    /**
     * Which template each rule sends, and when.
     *
     * @return list<array{event_key: string, template: string, title: string, description: string, icon: string, lead_minutes: int|null}>
     */
    public static function rules(): array
    {
        return [
            [
                'event_key' => AutomationRule::APPOINTMENT_CONFIRMED,
                'template' => 'confirmed',
                'title' => 'Send appointment confirmation',
                'description' => 'Tell the patient on their phone as soon as they are booked',
                'icon' => 'ti ti-calendar-check',
                'lead_minutes' => null,
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_REMINDER_DAY,
                'template' => 'reminder',
                'title' => 'Send 24-hour reminder',
                'description' => 'One reminder, the day before the appointment',
                'icon' => 'ti ti-bell',
                'lead_minutes' => 1440,
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_REMINDER_HOUR,
                'template' => 'reminder',
                'title' => 'Send 1-hour reminder',
                'description' => 'A last nudge while there is still time to travel',
                'icon' => 'ti ti-clock-hour-4',
                'lead_minutes' => 60,
            ],
            [
                'event_key' => AutomationRule::APPOINTMENT_CANCELLED,
                'template' => 'cancelled',
                'title' => 'Send cancellation notice',
                'description' => 'Tell the patient when an appointment is called off',
                'icon' => 'ti ti-circle-x',
                'lead_minutes' => null,
            ],
            [
                'event_key' => AutomationRule::PAYMENT_RECEIVED,
                'template' => 'payment',
                'title' => 'Send payment receipt',
                'description' => 'Confirm the amount once a payment goes through',
                'icon' => 'ti ti-receipt-2',
                'lead_minutes' => null,
            ],
            [
                'event_key' => AutomationRule::PATIENT_REGISTERED,
                'template' => 'registered',
                'title' => 'Send welcome notification',
                'description' => 'Welcome a patient on their phone once their record is created',
                'icon' => 'ti ti-user-plus',
                'lead_minutes' => null,
            ],
        ];
    }
}
