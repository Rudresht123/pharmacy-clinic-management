<?php

namespace App\Support\Communication;

use App\Models\Tenant\AutomationRule;

/**
 * The platform's own email templates and rules — the GLOBAL level every
 * clinic starts from, the email counterpart of PushDefaults.
 *
 * Seeded into each organisation's library (message_templates, channel
 * `email`) when its database is created, so the Email screen opens on a
 * working set to reword rather than a blank grid. The rules arrive SWITCHED
 * OFF, same as every other channel's defaults: nothing reaches a patient's
 * inbox until the clinic turns a rule on, with the channel connected and the
 * template approved.
 *
 * Content is simple HTML (`<p>`/`<br>` only) rather than plain text — email
 * is sent through `Mailer::html()` (see EmailManager::deliver()), so a body
 * with no markup at all would read as one unbroken line.
 */
final class EmailDefaults
{
    /**
     * @return list<array{key: string, name: string, category: string, subject: string, body: string, icon: string, tone: string}>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'confirmed',
                'name' => 'Appointment confirmed (email)',
                'category' => 'Appointment',
                'subject' => 'Appointment confirmed — {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>Your appointment with {{doctor_name}} has been confirmed.</p>'
                    .'<p>Date: {{date}}<br>Time: {{time}}<br>Clinic: {{clinic_name}}</p>'
                    .'<p>See you then.</p>',
                'icon' => 'ti ti-calendar-check',
                'tone' => 'sky',
            ],
            [
                'key' => 'reminder',
                'name' => 'Appointment reminder (email)',
                'category' => 'Reminder',
                'subject' => 'Reminder: your appointment at {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>A reminder of your upcoming appointment with {{doctor_name}}.</p>'
                    .'<p>Date: {{date}}<br>Time: {{time}}<br>Clinic: {{clinic_name}}</p>',
                'icon' => 'ti ti-bell',
                'tone' => 'violet',
            ],
            [
                'key' => 'cancelled',
                'name' => 'Appointment cancelled (email)',
                'category' => 'Appointment',
                'subject' => 'Appointment cancelled — {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>Your appointment with {{doctor_name}} on {{date}} has been cancelled.</p>'
                    .'<p>Please get in touch if you would like to book another time.</p>',
                'icon' => 'ti ti-circle-x',
                'tone' => 'rose',
            ],
            [
                'key' => 'payment',
                'name' => 'Payment received (email)',
                'category' => 'Payments',
                'subject' => 'Payment received — {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>We have received ₹{{amount}} for {{service}}.</p>'
                    .'<p>Thank you.</p>',
                'icon' => 'ti ti-receipt-2',
                'tone' => 'emerald',
            ],
            [
                'key' => 'registered',
                'name' => 'Welcome — patient registered (email)',
                'category' => 'General',
                'subject' => 'Welcome to {{clinic_name}}',
                'body' => '<p>Hello {{patient_name}},</p>'
                    .'<p>Your record has been created at {{clinic_name}}. We look forward to seeing you.</p>',
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
                'description' => 'Email the patient as soon as they are booked',
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
                'title' => 'Send welcome email',
                'description' => 'Welcome a patient once their record is created',
                'icon' => 'ti ti-user-plus',
                'lead_minutes' => null,
            ],
        ];
    }
}
