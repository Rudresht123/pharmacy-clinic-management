<?php

namespace App\Services\Queue;

use App\Events\Realtime\Queue\LabQueueUpdated;
use App\Events\Realtime\Queue\QueueConsultationCompleted;
use App\Events\Realtime\Queue\QueueConsultationStarted;
use App\Events\Realtime\Queue\QueuePatientCalled;
use App\Events\Realtime\Queue\QueuePatientCheckedIn;
use App\Events\Realtime\Queue\QueuePatientSkipped;
use App\Events\Realtime\Queue\QueuePaused;
use App\Events\Realtime\Queue\QueuePriorityChanged;
use App\Events\Realtime\Queue\QueueResumed;
use App\Events\Realtime\Queue\QueueUpdated;
use App\Models\Tenant\QueueEvent;
use App\Services\Clinic\ClinicEvent;
use App\Services\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a doctor's channel what just changed, once it is safely on disk.
 *
 * AFTER COMMIT, and it NEVER THROWS: the same two rules as
 * ClinicEventDispatcher, for the same reasons. A check-in that rolled back must
 * not appear on a television, and a Reverb that is down must not read to a
 * receptionist as "the check-in failed". The screens re-read on a timer, so a
 * lost message costs seconds, never accuracy.
 *
 * The tenant is checked again at sending time, because the connection may have
 * been pointed elsewhere between raising this and running it. Broadcasting one
 * clinic's card down another clinic's channel is the failure this architecture
 * exists to make impossible.
 */
class QueueBroadcaster
{
    private const EVENTS = [
        QueueEvent::CHECKED_IN => QueuePatientCheckedIn::class,
        QueueEvent::CALLED => QueuePatientCalled::class,
        QueueEvent::RECALLED => QueuePatientCalled::class,
        QueueEvent::SERVING => QueueConsultationStarted::class,
        QueueEvent::COMPLETED => QueueConsultationCompleted::class,
        QueueEvent::SKIPPED => QueuePatientSkipped::class,
        QueueEvent::PRIORITY_CHANGED => QueuePriorityChanged::class,
        QueueEvent::PAUSED => QueuePaused::class,
        QueueEvent::RESUMED => QueueResumed::class,
    ];

    public function __construct(
        private readonly QueueBoard $board,
        private readonly QueueModule $module,
    ) {}

    /**
     * Send when the surrounding transaction commits, or now if there is none.
     *
     * @param  array<string, mixed>|null  $announce  what a television should say out loud
     */
    public function afterCommit(
        string $eventType,
        int $doctorId,
        int $locationId,
        string $date,
        ?string $token = null,
        ?array $announce = null,
    ): void {
        $database = ClinicEvent::currentDatabase();

        (new QueueEvent)->getConnection()->afterCommit(function () use ($database, $eventType, $doctorId, $locationId, $date, $token, $announce) {
            if (ClinicEvent::currentDatabase() !== $database) {
                Log::warning('queue-broadcast.tenant-mismatch', [
                    'event' => $eventType,
                    'database' => $database,
                    'connected_to' => ClinicEvent::currentDatabase(),
                ]);

                return;
            }

            try {
                $this->send($eventType, $doctorId, $locationId, $date, $token, $announce);
            } catch (Throwable $e) {
                report($e);

                Log::error('queue-broadcast.failed', [
                    'event' => $eventType,
                    'doctor' => $doctorId,
                    'branch' => $locationId,
                    'reason' => $e->getMessage(),
                ]);
            }
        });
    }

    /** The laboratory's screen, under the same two rules. */
    public function labChanged(int $locationId): void
    {
        if (! $this->module->runsAt($locationId)) {
            return;
        }

        $database = ClinicEvent::currentDatabase();

        (new QueueEvent)->getConnection()->afterCommit(function () use ($database, $locationId) {
            if (ClinicEvent::currentDatabase() !== $database) {
                return;
            }

            try {
                $organization = CurrentTenant::organization();

                if ($organization !== null) {
                    event(new LabQueueUpdated(
                        $organization->uuid,
                        $locationId,
                        app(LabQueue::class)->state($locationId, QueueClock::today()),
                    ));
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private function send(string $eventType, int $doctorId, int $locationId, string $date, ?string $token, ?array $announce): void
    {
        // The televisions show today. Moving a visit on some other day's list
        // has nothing to tell them.
        if ($date !== QueueClock::today()) {
            return;
        }

        $organization = CurrentTenant::organization();

        if ($organization === null) {
            return;
        }

        $class = self::EVENTS[$eventType] ?? QueueUpdated::class;

        event(new $class($organization->uuid, $locationId, $doctorId, [
            'doctor_id' => $doctorId,
            'location_id' => $locationId,
            'token' => $token,
            'change' => $eventType,
            'card' => $this->board->card($doctorId, $locationId, $date),
            'announce' => $announce,
            'at' => now()->toIso8601String(),
        ]));
    }
}
