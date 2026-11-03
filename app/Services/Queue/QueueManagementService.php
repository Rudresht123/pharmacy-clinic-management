<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorQueueSetting;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\QueueEvent;
use App\Models\Tenant\User;
use App\Services\Opd\AvailabilityService;
use App\Services\Opd\VisitWorkflow;
use App\Services\Opd\WorkflowConflict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Running one doctor's queue.
 *
 * WHAT IS DECIDED HERE, and what is not. This chooses WHO is next, and handles
 * what belongs to the queue as a whole: pausing, priority, moving somebody to
 * another doctor. Every change to a visit's state still goes through
 * VisitWorkflow, which remains the one place that writes `queue_status`.
 *
 * ONE DOCTOR AT A TIME, AND ONLY ONE. "Call next" holds that doctor's settings
 * row while it decides, so two clicks on the same queue take turns and the
 * second finds somebody already called. Dr. Sharma's row has nothing to do with
 * Dr. Verma's, so the two can call at the same instant without either waiting.
 */
class QueueManagementService
{
    public function __construct(
        private readonly VisitWorkflow $workflow,
        private readonly QueueSettings $settings,
        private readonly QueueTokenService $tokens,
        private readonly QueueJournal $journal,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * Call whoever is next for this doctor.
     *
     * Refused while the queue is paused, or while somebody is already called or
     * in the room: the doctor deals with who is at the door first.
     *
     * AN EMERGENCY OVERRIDES ALL OF THAT. Whoever marked them an emergency has
     * made a clinical decision and given a reason, and the software should not
     * be the thing standing between that patient and the room.
     */
    public function callNext(int $doctorId, int $locationId, ?User $actor = null): Appointment
    {
        return (new Appointment)->getConnection()->transaction(function () use ($doctorId, $locationId, $actor) {
            $setting = $this->lockQueue($doctorId, $locationId);

            $today = QueueClock::today();

            $live = Appointment::query()
                ->where('doctor_id', $doctorId)
                ->where('location_id', $locationId)
                ->where('appointment_date', $today)
                ->inTheBuilding()
                ->whereIn('queue_status', [
                    Appointment::QUEUE_WAITING,
                    Appointment::QUEUE_CALLED,
                    Appointment::QUEUE_WITH_DOCTOR,
                ])
                ->get();

            $next = QueueOrder::first($live->where('queue_status', Appointment::QUEUE_WAITING));

            if ($next === null) {
                throw WorkflowConflict::because('Nobody is waiting for this doctor.');
            }

            $override = $next->queue_priority === Appointment::PRIORITY_EMERGENCY;

            if (! $override) {
                $this->assertRoomIsReady($setting, $live);
            }

            return $this->workflow->call($next, $actor, override: $override);
        });
    }

    /** Stop calling. Everybody keeps their place; nobody new is called. */
    public function pause(int $doctorId, int $locationId, ?string $reason, ?Carbon $until, ?User $actor = null): DoctorQueueSetting
    {
        return (new Appointment)->getConnection()->transaction(function () use ($doctorId, $locationId, $reason, $until, $actor) {
            $setting = $this->lockQueue($doctorId, $locationId);

            if ($setting->isPaused(QueueClock::now())) {
                throw WorkflowConflict::because('This queue is already paused.');
            }

            $setting->forceFill([
                'paused_at' => now(),
                'pause_until' => $until,
                'pause_reason' => $reason,
                'paused_by' => $actor?->id,
            ])->save();

            $this->journal->queue($doctorId, $locationId, QueueEvent::PAUSED, [
                'reason' => $reason,
                'until' => $until?->toIso8601String(),
            ], $actor);

            return $setting;
        });
    }

    public function resume(int $doctorId, int $locationId, ?User $actor = null): DoctorQueueSetting
    {
        return (new Appointment)->getConnection()->transaction(function () use ($doctorId, $locationId, $actor) {
            $setting = $this->lockQueue($doctorId, $locationId);

            if (! $setting->isPaused(QueueClock::now())) {
                throw WorkflowConflict::because('This queue is not paused.');
            }

            $setting->forceFill([
                'paused_at' => null,
                'pause_until' => null,
                'pause_reason' => null,
                'paused_by' => null,
            ])->save();

            $this->journal->queue($doctorId, $locationId, QueueEvent::RESUMED, [], $actor);

            return $setting;
        });
    }

    /**
     * Change how soon somebody should be seen.
     *
     * The reason is mandatory and the change is written down, because this moves
     * one patient ahead of others: anybody asking why has to be able to find out.
     *
     * `allow_priority` off refuses Urgent, and deliberately not Emergency. A
     * doctor who prefers a strict order has not decided that a collapsed patient
     * should wait their turn.
     */
    public function setPriority(Appointment $visit, string $priority, string $reason, ?User $actor = null): Appointment
    {
        if (! in_array($priority, Appointment::PRIORITIES, true)) {
            throw WorkflowConflict::because('That is not a priority this queue knows.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw WorkflowConflict::because('Say why the priority is changing. The reason is kept with the queue\'s history.');
        }

        return (new Appointment)->getConnection()->transaction(function () use ($visit, $priority, $reason, $actor) {
            $locked = Appointment::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($locked->queue_status === null || $locked->isWithDoctor()) {
                throw WorkflowConflict::because('Only somebody still waiting to be seen can have their priority changed.');
            }

            $previous = $locked->queue_priority ?: Appointment::PRIORITY_NORMAL;

            if ($previous === $priority) {
                throw WorkflowConflict::because("{$locked->tokenLabel()} is already {$priority}.");
            }

            $setting = $this->settings->forDoctor((int) $locked->doctor_id, (int) $locked->location_id);

            if ($priority === Appointment::PRIORITY_URGENT && ! $setting->allow_priority) {
                throw WorkflowConflict::because('This doctor\'s queue does not take urgent patients. Mark them an emergency if that is what this is.');
            }

            $locked->forceFill([
                'queue_priority' => $priority,
                'priority_reason' => $reason,
            ])->save();

            $this->journal->visit(
                $locked,
                QueueEvent::PRIORITY_CHANGED,
                $locked->queue_status,
                $locked->queue_status,
                ['from' => $previous, 'to' => $priority, 'reason' => $reason],
                $actor,
            );

            return $locked;
        });
    }

    /**
     * Move somebody to another doctor at the same branch.
     *
     * They take a new token in the new doctor's run: A012 means Dr. Sharma's
     * twelfth patient, and a patient holding it in Dr. Verma's queue would be
     * lost on a television that says B. Their arrival time goes with them, so
     * moving does not send them to the back of the line for no fault of theirs.
     *
     * A BOOKED TIME DOES NOT GO WITH THEM. It was a promise about the first
     * doctor's diary; the second doctor may have somebody at that same time, and
     * the slot's unique index would refuse it. It is kept in the journal instead.
     *
     * A bill already drawn is not rewritten: the money is the desk's to settle,
     * so the caller is told about it rather than having it quietly change.
     *
     * @return array{visit: Appointment, billed: bool}
     */
    public function reassign(Appointment $visit, int $doctorId, string $reason, ?User $actor = null): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw WorkflowConflict::because('Say why this patient is moving. The reason is kept with the queue\'s history.');
        }

        return (new Appointment)->getConnection()->transaction(function () use ($visit, $doctorId, $reason, $actor) {
            $locked = Appointment::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($locked->queue_status === null || $locked->isWithDoctor()) {
                throw WorkflowConflict::because('Only somebody still waiting to be seen can be moved to another doctor.');
            }

            if ((int) $locked->doctor_id === $doctorId) {
                throw WorkflowConflict::because('This patient is already with that doctor.');
            }

            $doctor = Doctor::query()->active()->find($doctorId);

            $posted = $doctor?->postings()
                ->where('locations.id', $locked->location_id)
                ->where('doctor_locations.is_active', true)
                ->exists();

            if (! $posted) {
                throw WorkflowConflict::because('That doctor is not working at this branch.');
            }

            $date = $locked->appointment_date->toDateString();
            $token = $this->tokens->next($doctorId, (int) $locked->location_id, $date);

            $from = [
                'doctor_id' => (int) $locked->doctor_id,
                'token' => $locked->tokenLabel(),
                'slot_at' => $locked->slot_at ? substr((string) $locked->slot_at, 0, 5) : null,
                'status' => $locked->queue_status,
            ];

            $sessions = $this->availability->sessionsFor($doctor, Carbon::parse($date), (int) $locked->location_id);

            $locked->forceFill([
                'doctor_id' => $doctorId,
                'doctor_schedule_id' => $sessions[0]['schedule_id'] ?? null,
                'slot_at' => null,
                'token_prefix' => $token['prefix'],
                'token_no' => $token['number'],
                'queue_status' => Appointment::QUEUE_WAITING,
                'called_at' => null,
                'called_by' => null,
                'skipped_at' => null,
                'recalled_at' => null,
            ])->save();

            $this->journal->visit(
                $locked,
                QueueEvent::REASSIGNED,
                $from['status'],
                Appointment::QUEUE_WAITING,
                ['from' => $from, 'to' => ['doctor_id' => $doctorId, 'token' => $locked->tokenLabel()], 'reason' => $reason],
                $actor,
            );

            // The doctor they left has lost a patient, and their card should say so.
            $this->journal->announceChange($from['doctor_id'], (int) $locked->location_id, $date);

            return [
                'visit' => $locked,
                'billed' => Invoice::query()->liveForVisit($locked->id)->exists(),
            ];
        });
    }

    /**
     * A consultation just finished, and this doctor's queue calls by itself.
     *
     * Runs after that consultation has committed: failing to find somebody to
     * call must never undo a doctor's finished write-up. Nobody waiting, a pause
     * or a patient already at the door simply means there is nothing to do.
     */
    public function autoCallAfter(Appointment $visit): void
    {
        if ($visit->isVideo()
            || $visit->appointment_date?->toDateString() !== QueueClock::today()
            || ! app(QueueModule::class)->runsAt((int) $visit->location_id)) {
            return;
        }

        $setting = $this->settings->forDoctor((int) $visit->doctor_id, (int) $visit->location_id);

        if (! $setting->auto_call_enabled) {
            return;
        }

        try {
            $this->callNext((int) $visit->doctor_id, (int) $visit->location_id);
        } catch (WorkflowConflict) {
            // Nobody to call, or not yet. The doctor can always press the button.
        }
    }

    /**
     * Take the doctor's queue lock.
     *
     * Their settings row, made first if this is the first time anybody asked:
     * SELECT FOR UPDATE cannot lock a row that is not there.
     */
    private function lockQueue(int $doctorId, int $locationId): DoctorQueueSetting
    {
        $this->settings->forDoctor($doctorId, $locationId);

        return DoctorQueueSetting::query()
            ->where('doctor_id', $doctorId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param  Collection<int, Appointment>  $live */
    private function assertRoomIsReady(DoctorQueueSetting $setting, $live): void
    {
        if ($setting->isPaused(QueueClock::now())) {
            throw WorkflowConflict::because('This queue is paused. Resume it to call the next patient.');
        }

        $inRoom = $live->firstWhere('queue_status', Appointment::QUEUE_WITH_DOCTOR);

        if ($inRoom !== null) {
            throw WorkflowConflict::because("Token {$inRoom->tokenLabel()} is still with the doctor. Complete that consultation first.");
        }

        $called = $live->firstWhere('queue_status', Appointment::QUEUE_CALLED);

        if ($called !== null) {
            throw WorkflowConflict::because("Token {$called->tokenLabel()} has been called and has not come in. Start, recall or skip them first.");
        }
    }
}
