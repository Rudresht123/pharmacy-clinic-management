<?php

namespace App\Events\Realtime\Queue;

use App\Broadcasting\DoctorQueueChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed in ONE doctor's queue, told to that doctor's channel only.
 *
 * Sent NOW rather than queued, for the reason ConsultationCompleted gives: it is
 * raised after commit from code that swallows every failure, so a slow or absent
 * Reverb can never fail a check-in, and a queued broadcast would be a job that
 * has to find its way back to the right tenant database.
 *
 * THE PAYLOAD IS THE DOCTOR'S WHOLE CARD. A wall of televisions replaces that
 * one card from the message and asks the server nothing, however many screens
 * are listening — which is what keeps a busy morning cheap, and is also why two
 * doctors calling at the same instant cannot disturb each other's card.
 *
 * Plain values, and nothing about a patient but their token: a television is in
 * a public room. No name, no number, no reason.
 */
abstract class QueueBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  QueueBroadcaster's message
     */
    public function __construct(
        public readonly string $organizationUuid,
        public readonly int $locationId,
        public readonly int $doctorId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(DoctorQueueChannel::name($this->organizationUuid, $this->locationId, $this->doctorId));
    }

    abstract public function broadcastAs(): string;

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['event' => $this->broadcastAs()] + $this->payload;
    }
}
