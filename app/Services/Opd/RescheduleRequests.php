<?php

namespace App\Services\Opd;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AppointmentRescheduleRequest;
use App\Models\Tenant\Customer;
use App\Models\Tenant\User;
use App\Services\Clinic\ClinicEvent;
use App\Services\Clinic\ClinicEventDispatcher;
use App\Services\Portal\BookableDoctors;
use App\Services\Portal\PatientNotifications;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Clinic\ClinicEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A patient asking to move a booking, and the desk answering.
 *
 * The patient picks a time the doctor really has free — the same check a
 * fresh booking passes — and the appointment stays where it is until somebody
 * at the clinic says yes. Yes moves it (to that time, or to another the desk
 * agreed with the patient when the first has gone); no leaves it alone. Either
 * way the patient is told.
 *
 * WHO HEARS is the clinic's automation rules, like any other clinic event: a
 * new request is ClinicEvents::RESCHEDULE_REQUESTED ("Tell staff: reschedule
 * request" — the desk, the doctor, whoever the clinic named), an answer is
 * APPOINTMENT_RESCHEDULED or RESCHEDULE_REJECTED (the patient's own message,
 * and the doctor when a visit moves). Raised after commit and never failing
 * the save; the desk's list is the record either way. Only the in-app bell is
 * written here, as it is for a booking.
 */
class RescheduleRequests
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly BookableDoctors $doctors,
        private readonly PatientNotifications $patientNotifications,
        private readonly ClinicEventDispatcher $events,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The patient
    |--------------------------------------------------------------------------
    */

    /**
     * Ask to move one of their own bookings to [$data]'s date and slot.
     * Asking again while a request waits replaces it.
     *
     * @param  array{appointment_date: string, slot_at: string, location_id?: int|null, reason?: string|null}  $data
     */
    public function request(Organization $organization, Customer $patient, int $appointmentId, array $data): AppointmentRescheduleRequest
    {
        $visit = Appointment::query()
            ->whereIn('customer_id', $patient->familyIds())
            ->whereKey($appointmentId)
            ->with('doctor')
            ->firstOrFail();

        $this->assertMovable($visit);

        $date = Carbon::parse($data['appointment_date'])->startOfDay();
        $slot = substr($data['slot_at'], 0, 5);
        $locationId = (int) ($data['location_id'] ?? $visit->location_id);

        if ($visit->appointment_date->isSameDay($date)
            && substr((string) $visit->slot_at, 0, 5) === $slot
            && (int) $visit->location_id === $locationId) {
            throw new RuntimeException('That is the time you already have.');
        }

        if (! $this->doctors->offers($organization, $visit->doctor, $date, $locationId, $slot)) {
            throw new RuntimeException('That time is not free with this doctor. Please pick another.');
        }

        $request = DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($visit, $date, $slot, $locationId, $data) {
            AppointmentRescheduleRequest::query()
                ->where('appointment_id', $visit->id)
                ->pending()
                ->update(['status' => AppointmentRescheduleRequest::WITHDRAWN, 'decided_at' => now()]);

            return AppointmentRescheduleRequest::query()->create([
                'appointment_id' => $visit->id,
                'customer_id' => $visit->customer_id,
                'doctor_id' => $visit->doctor_id,
                'location_id' => $visit->location_id,
                'from_date' => $visit->appointment_date->toDateString(),
                'from_slot_at' => $visit->slot_at,
                'requested_date' => $date->toDateString(),
                'requested_slot_at' => $slot,
                'requested_location_id' => $locationId,
                'reason' => filled($data['reason'] ?? null) ? mb_substr(trim((string) $data['reason']), 0, 191) : null,
                'status' => AppointmentRescheduleRequest::PENDING,
            ]);
        });

        $this->events->dispatch(ClinicEvent::for(ClinicEvents::RESCHEDULE_REQUESTED, $request));

        return $request;
    }

    /** Take back a request the clinic has not answered yet. */
    public function withdraw(Customer $patient, int $appointmentId): AppointmentRescheduleRequest
    {
        $request = AppointmentRescheduleRequest::query()
            ->whereIn('customer_id', $patient->familyIds())
            ->where('appointment_id', $appointmentId)
            ->pending()
            ->first();

        if ($request === null) {
            throw new RuntimeException('There is no reschedule request waiting for this appointment.');
        }

        $request->forceFill(['status' => AppointmentRescheduleRequest::WITHDRAWN, 'decided_at' => now()])->save();

        return $request;
    }

    /*
    |--------------------------------------------------------------------------
    | The desk
    |--------------------------------------------------------------------------
    */

    /**
     * Requests at [$branches] (null: every branch), newest first.
     *
     * @param  list<int>|null  $branches
     * @return Collection<int, AppointmentRescheduleRequest>
     */
    public function list(?array $branches, ?string $status = AppointmentRescheduleRequest::PENDING): Collection
    {
        return $this->atBranches(AppointmentRescheduleRequest::query(), $branches)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->with(['customer', 'doctor', 'location', 'requestedLocation', 'appointment', 'decider'])
            ->latest('id')
            ->limit(200)
            ->get();
    }

    /** @param  list<int>|null  $branches */
    public function pendingCount(?array $branches): int
    {
        return $this->atBranches(AppointmentRescheduleRequest::query(), $branches)->pending()->count();
    }

    /**
     * Say yes — to the time asked for, or to [$date]/[$slot]/[$locationId]
     * agreed with the patient instead.
     */
    public function approve(
        Organization $organization,
        User $actor,
        AppointmentRescheduleRequest $request,
        ?string $date = null,
        ?string $slot = null,
        ?int $locationId = null,
        ?string $note = null,
    ): AppointmentRescheduleRequest {
        $this->assertOpen($request);

        $visit = $request->appointment()->firstOrFail();
        $this->assertMovable($visit);

        $target = [
            'date' => Carbon::parse($date ?? $request->requested_date)->startOfDay(),
            'slot' => substr($slot ?? (string) $request->requested_slot_at, 0, 5),
            'location' => $locationId ?? (int) $request->requested_location_id,
        ];

        DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($visit, $request, $actor, $target, $note) {
            $this->booking->moveTo($visit, $target['date'], $target['location'], $target['slot']);

            $request->forceFill([
                'status' => AppointmentRescheduleRequest::APPROVED,
                'approved_date' => $target['date']->toDateString(),
                'approved_slot_at' => $target['slot'],
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $this->note($note),
            ])->save();
        });

        $visit->load('doctor');
        $this->patientNotifications->rescheduleApproved($visit);
        $this->events->dispatch(ClinicEvent::for(ClinicEvents::APPOINTMENT_RESCHEDULED, $request));

        return $request->refresh();
    }

    /** Say no. The visit stays where it was. */
    public function reject(Organization $organization, User $actor, AppointmentRescheduleRequest $request, ?string $note = null): AppointmentRescheduleRequest
    {
        $this->assertOpen($request);

        $request->forceFill([
            'status' => AppointmentRescheduleRequest::REJECTED,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
            'decision_note' => $this->note($note),
        ])->save();

        $visit = $request->appointment()->with('doctor')->first();

        if ($visit !== null) {
            $this->patientNotifications->rescheduleRejected($visit, $request->decision_note);
            $this->events->dispatch(ClinicEvent::for(ClinicEvents::RESCHEDULE_REJECTED, $request));
        }

        return $request->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Inside
    |--------------------------------------------------------------------------
    */

    private function assertMovable(Appointment $visit): void
    {
        if ($visit->status !== Appointment::STATUS_BOOKED) {
            throw new RuntimeException(match ($visit->status) {
                Appointment::STATUS_CANCELLED => 'This appointment has been cancelled.',
                Appointment::STATUS_NO_SHOW => 'This appointment has already passed.',
                default => 'This visit has already started, so it can no longer be moved.',
            });
        }

        if ($visit->appointment_date->lt(today())) {
            throw new RuntimeException('This appointment has already passed.');
        }
    }

    private function assertOpen(AppointmentRescheduleRequest $request): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException(match ($request->status) {
                AppointmentRescheduleRequest::WITHDRAWN => 'The patient has withdrawn this request.',
                default => 'This request has already been answered.',
            });
        }
    }

    /**
     * @param  Builder<AppointmentRescheduleRequest>  $query
     * @param  list<int>|null  $branches
     * @return Builder<AppointmentRescheduleRequest>
     */
    private function atBranches(Builder $query, ?array $branches): Builder
    {
        return $branches === null ? $query : $query->whereIn('location_id', $branches);
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    /** "Tue 14 Oct, 10:30 AM". */
    private function when(mixed $date, mixed $slot): string
    {
        $day = $date instanceof Carbon ? $date : Carbon::parse((string) $date);

        return $day->format('D j M').($slot ? ', '.date('g:i A', strtotime((string) $slot)) : '');
    }
}
