<?php

namespace App\Services\Portal;

use App\Jobs\SendPushNotification;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\PatientNotification;
use App\Models\Tenant\User;
use App\Services\Tenancy\CurrentTenant;
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

        $this->push($appointment, $type, $title, $body);
    }

    /**
     * The same words, to the patient's own phones.
     *
     * The in-app notification above is the record; this only wakes the phone
     * to it. Queued after commit, so a booking that rolled back never buzzes
     * anybody, and addressed to the patient's own logins in THIS organization
     * — the job looks their devices up by organization and user, never by
     * anything the request said.
     *
     * A patient with no app login, or no registered phone, simply gets
     * nothing pushed; the in-app notification is still there.
     */
    private function push(Appointment $appointment, string $type, string $title, string $body): void
    {
        $organization = CurrentTenant::organization();

        if ($organization === null || $appointment->customer_id === null) {
            return;
        }

        $logins = User::query()
            ->where('userable_type', Customer::class)
            ->where('userable_id', $appointment->customer_id)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($logins === []) {
            return;
        }

        SendPushNotification::dispatch(
            $organization->getKey(),
            $logins,
            $title,
            $body,
            ['type' => $type, 'appointment_id' => $appointment->id],
        )->afterCommit();
    }

    private function of(Customer $patient): Builder
    {
        return PatientNotification::query()->where('customer_id', $patient->id);
    }
}
