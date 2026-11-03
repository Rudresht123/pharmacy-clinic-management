<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\DoctorQueueSetting;
use App\Models\Tenant\QueueEvent;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;

/**
 * Writes down what happened to a queue, and tells the screens.
 *
 * Both from one call, inside the transaction that made the change, so the
 * record and the announcement cannot disagree: whatever a television shows has
 * a row here saying who did it. VisitWorkflow and BookingService call this at
 * every move, which is why the old screens (the desk's queue, a doctor's "My
 * queue") keep the televisions right without knowing they exist.
 *
 * A video visit is written down and not announced: it has a token and moves
 * through the same steps, but nobody is sitting in the waiting room for it.
 *
 * A clinic without the Smart Queue gets neither: there is no screen to read the
 * journal and no television to tell.
 */
class QueueJournal
{
    public function __construct(
        private readonly QueueBroadcaster $broadcaster,
        private readonly QueueModule $module,
    ) {}

    /**
     * Something happened to one patient's place in the queue.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function visit(
        Appointment $visit,
        string $eventType,
        ?string $oldStatus,
        ?string $newStatus,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        if (! $this->module->runsAt((int) $visit->location_id)) {
            return;
        }

        $date = $visit->appointment_date?->toDateString();

        QueueEvent::query()->create([
            'appointment_id' => $visit->id,
            'location_id' => $visit->location_id,
            'doctor_id' => $visit->doctor_id,
            'queue_date' => $date,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'performed_by' => $this->actorId($actor),
            'metadata' => ['token' => $visit->tokenLabel()] + $metadata,
        ]);

        if ($visit->isVideo()) {
            return;
        }

        $this->broadcaster->afterCommit(
            $eventType,
            (int) $visit->doctor_id,
            (int) $visit->location_id,
            $date,
            $visit->tokenLabel(),
            in_array($eventType, [QueueEvent::CALLED, QueueEvent::RECALLED], true)
                ? $this->announcement($visit, $eventType)
                : null,
        );
    }

    /**
     * Something happened to a doctor's whole queue: paused, resumed, renamed.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function queue(
        int $doctorId,
        int $locationId,
        string $eventType,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $date = QueueClock::today();

        QueueEvent::query()->create([
            'location_id' => $locationId,
            'doctor_id' => $doctorId,
            'queue_date' => $date,
            'event_type' => $eventType,
            'performed_by' => $this->actorId($actor),
            'metadata' => $metadata ?: null,
        ]);

        $this->broadcaster->afterCommit($eventType, $doctorId, $locationId, $date);
    }

    /** Say it again on a channel, for a doctor who just gained or lost a patient. */
    public function announceChange(int $doctorId, int $locationId, string $date): void
    {
        $this->broadcaster->afterCommit(QueueEvent::REASSIGNED, $doctorId, $locationId, $date);
    }

    /**
     * What the television reads out and flashes: the token, who is being called,
     * and where to go. Only the patient being called is ever named.
     *
     * @return array<string, mixed>
     */
    private function announcement(Appointment $visit, string $eventType): array
    {
        return [
            'token' => $visit->tokenLabel(),
            'room' => DoctorQueueSetting::query()
                ->where('location_id', $visit->location_id)
                ->where('doctor_id', $visit->doctor_id)
                ->value('room_no'),
            'doctor_name' => $visit->doctor()->value('name'),
            'patient_name' => $visit->customer()->value('name'),
            'recalled' => $eventType === QueueEvent::RECALLED,
        ];
    }

    private function actorId(?User $actor): ?int
    {
        $actor ??= Auth::guard('web')->user();

        return $actor instanceof User ? $actor->id : null;
    }
}
