<?php

namespace App\Events\Realtime;

use App\Broadcasting\BranchPharmacyChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A doctor finished with a patient — told to that branch's pharmacy counter.
 *
 * Sent NOW rather than queued, deliberately. It is raised from inside the
 * after-commit clinic event dispatcher, which already swallows every failure,
 * so a slow or absent Reverb can never fail the consultation. A queued
 * broadcast would instead be a job that has to find its way back to the right
 * tenant database — the one failure this architecture exists to make
 * impossible — for an event whose whole value is arriving immediately.
 *
 * PLAIN VALUES ONLY. No models, so nothing is re-fetched on the way out on
 * whatever connection happens to be current. The row is OpdHandoff::row(),
 * the same shape the counter's list endpoint returns, so the panel inserts a
 * pushed patient exactly as it would a fetched one.
 *
 * Who hears it is decided by BranchPharmacyChannel, and whether it is sent at
 * all by RealtimeBroadcaster, which checks the branch runs both the OPD and
 * the pharmacy first.
 */
class ConsultationCompleted implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $patient  OpdHandoff::row()
     */
    public function __construct(
        public readonly string $organizationUuid,
        public readonly int $locationId,
        public readonly array $patient,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(BranchPharmacyChannel::name($this->organizationUuid, $this->locationId));
    }

    public function broadcastAs(): string
    {
        return 'consultation.completed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->patient;
    }
}
