<?php

namespace App\Services\Portal;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\PatientNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a patient has been told about their own care, and telling them more.
 *
 * Mirrors PatientAppointments: reads scoped to one patient, and the writes
 * that raise a notification sit alongside them rather than in whatever
 * controller happened to trigger one, so every screen that raises one says
 * it the same way.
 */
class PatientNotifications
{
    public function unreadCount(Customer $patient): int
    {
        return $this->of($patient)->unread()->count();
    }

    /** Newest first. */
    public function recent(Customer $patient, int $limit = 20): Collection
    {
        return $this->of($patient)->orderByDesc('created_at')->limit($limit)->get();
    }

    public function appointmentBooked(Appointment $appointment): void
    {
        $this->write(
            $appointment,
            'appointment_booked',
            'Appointment confirmed',
            sprintf(
                'Your appointment with %s is confirmed for %s.',
                $appointment->doctor?->name ?? 'your doctor',
                $appointment->appointment_date?->format('d M Y') ?? 'the booked date',
            ),
        );
    }

    public function appointmentCancelled(Appointment $appointment): void
    {
        $this->write(
            $appointment,
            'appointment_cancelled',
            'Appointment cancelled',
            sprintf(
                'Your appointment with %s on %s has been cancelled.',
                $appointment->doctor?->name ?? 'your doctor',
                $appointment->appointment_date?->format('d M Y') ?? 'the booked date',
            ),
        );
    }

    /** The clinic said yes: the visit is at the new time now. */
    public function rescheduleApproved(Appointment $appointment): void
    {
        $this->write(
            $appointment,
            'appointment_rescheduled',
            'Appointment rescheduled',
            sprintf(
                'Your appointment with %s is now on %s%s.',
                $appointment->doctor?->name ?? 'your doctor',
                $appointment->appointment_date?->format('d M Y') ?? 'the new date',
                $appointment->slot_at ? ' at '.date('g:i A', strtotime((string) $appointment->slot_at)) : '',
            ),
        );
    }

    /** The clinic said no: the visit stays where it was. */
    public function rescheduleRejected(Appointment $appointment, ?string $note): void
    {
        $this->write(
            $appointment,
            'appointment_reschedule_rejected',
            'Reschedule not possible',
            trim(sprintf(
                'Your appointment with %s stays on %s%s. %s',
                $appointment->doctor?->name ?? 'your doctor',
                $appointment->appointment_date?->format('d M Y') ?? 'its date',
                $appointment->slot_at ? ' at '.date('g:i A', strtotime((string) $appointment->slot_at)) : '',
                $note ? 'The clinic says: '.$note : '',
            )),
        );
    }

    private function write(Appointment $appointment, string $type, string $title, string $body): void
    {
        /*
         * The in-app bell only. Whether the patient's PHONE is woken is the
         * App notifications channel's decision — its "Send appointment
         * confirmation" rule, its template, its switch — exactly as for email
         * and WhatsApp (App\Services\Communication\AutomationSender).
         */
        PatientNotification::create([
            'customer_id' => $appointment->customer_id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'appointment_id' => $appointment->id,
        ]);
    }

    private function of(Customer $patient): Builder
    {
        return PatientNotification::query()->where('customer_id', $patient->id);
    }
}
