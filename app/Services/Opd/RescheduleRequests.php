<?php

namespace App\Services\Opd;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AppointmentRescheduleRequest;
use App\Models\Tenant\AppointmentSetting;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\Permissions\Permission;
use App\Services\Portal\BookableDoctors;
use App\Services\Portal\PatientNotifications;
use App\Services\Push\PushManager;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * WHO HEARS ABOUT A NEW REQUEST is the organization's choice
 * (AppointmentSetting::reschedule_notify): the people at the visit's branch
 * who can answer it, the doctor whose day it changes, or both — each by app
 * notification and email. Telling anybody happens after the save and never
 * fails it; the desk's list is the record either way.
 */
class RescheduleRequests
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly BookableDoctors $doctors,
        private readonly PatientNotifications $patientNotifications,
        private readonly PushManager $push,
        private readonly EmailManager $email,
        private readonly Permission $permission,
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
            ->where('customer_id', $patient->getKey())
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

        $this->tellTheClinic($organization, $request->load(['customer', 'doctor.user']));

        return $request;
    }

    /** Take back a request the clinic has not answered yet. */
    public function withdraw(Customer $patient, int $appointmentId): AppointmentRescheduleRequest
    {
        $request = AppointmentRescheduleRequest::query()
            ->where('customer_id', $patient->getKey())
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
        $this->tellThePatient($organization, $visit, 'appointment.rescheduled', 'Appointment rescheduled', sprintf(
            'Your appointment with %s is now on %s.',
            $visit->doctor?->name ?? 'your doctor',
            $this->when($visit->appointment_date, $visit->slot_at),
        ));

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
            $this->tellThePatient($organization, $visit, 'appointment.reschedule_rejected', 'Reschedule not possible', sprintf(
                'Your appointment with %s stays on %s.',
                $visit->doctor?->name ?? 'your doctor',
                $this->when($visit->appointment_date, $visit->slot_at),
            ));
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
     * The people the organization chose to hear about a new request.
     *
     * "The desk" is everybody posted to the visit's branch who could answer
     * it — whoever holds `appointments.reschedule` there — except doctors and
     * patients, who are told as themselves or not at all.
     */
    private function tellTheClinic(Organization $organization, AppointmentRescheduleRequest $request): void
    {
        try {
            $settings = AppointmentSetting::current();
            $patient = $request->customer?->name ?? 'A patient';
            $title = 'Reschedule request';
            $body = sprintf(
                '%s asks to move %s with %s to %s.',
                $patient,
                $this->when($request->from_date, $request->from_slot_at),
                $request->doctor?->name ?? 'their doctor',
                $this->when($request->requested_date, $request->requested_slot_at),
            ).($request->reason ? ' Reason: '.$request->reason : '');

            $people = [];

            if ($settings->notifiesDesk()) {
                foreach ($this->desk($organization, (int) $request->location_id) as $user) {
                    $people[$user->id] = $user;
                }
            }

            $doctorLogin = $request->doctor?->user;

            if ($settings->notifiesDoctor() && $doctorLogin instanceof User && $doctorLogin->is_active) {
                $people[$doctorLogin->id] = $doctorLogin;
            }

            if ($people !== []) {
                $this->push->queueToUsers(
                    $organization,
                    array_keys($people),
                    'Clinic staff',
                    $title,
                    $body,
                    ['type' => 'appointment.reschedule_requested', 'request_id' => (int) $request->id, 'appointment_id' => (int) $request->appointment_id],
                );
            }

            $addresses = [];

            foreach ($people as $user) {
                if (filled($user->email)) {
                    $addresses[strtolower((string) $user->email)] = $user->name;
                }
            }

            // A doctor without a login of their own may still have an address on file.
            if ($settings->notifiesDoctor() && $doctorLogin === null && filled($request->doctor?->email)) {
                $addresses[strtolower((string) $request->doctor->email)] = $request->doctor->name;
            }

            foreach ($addresses as $address => $name) {
                $this->email->forOrganization($organization)->queue(
                    EmailMessage::make(to: $address, subject: $title, body: $body),
                    customerId: null,
                    extra: ['recipient_name' => $name],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Reschedule request saved, but the clinic was not told', [
                'request' => $request->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everybody at [$locationId] who could answer a request.
     *
     * @return list<User>
     */
    private function desk(Organization $organization, int $locationId): array
    {
        $users = User::query()
            ->whereIn('id', BranchMembership::query()->where('location_id', $locationId)->select('user_id'))
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('userable_type')
                ->orWhereNotIn('userable_type', [Doctor::class, Customer::class]))
            ->get();

        return $users
            ->filter(fn (User $user) => $this->permission->allows($organization, $user, 'appointments.reschedule', $locationId))
            ->values()
            ->all();
    }

    private function tellThePatient(Organization $organization, Appointment $visit, string $type, string $title, string $body): void
    {
        try {
            $patient = $visit->customer()->first();

            if ($patient !== null) {
                $this->push->queue($organization, $patient, $title, $body, [
                    'type' => $type,
                    'appointment_id' => (int) $visit->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Reschedule answered, but the patient was not pushed', [
                'appointment' => $visit->getKey(),
                'error' => $e->getMessage(),
            ]);
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
