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

    private function write(Appointment $appointment, string $type, string $title, string $body): void
    {
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
