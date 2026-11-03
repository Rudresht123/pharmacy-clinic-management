<?php

namespace App\Events\Realtime\Queue;

use App\Broadcasting\BranchLabQueueChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A lab order moved, told to that branch's laboratory screen. Tokens only. */
class LabQueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, list<string>>  $lab  LabQueue::state()
     */
    public function __construct(
        public readonly string $organizationUuid,
        public readonly int $locationId,
        public readonly array $lab,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(BranchLabQueueChannel::name($this->organizationUuid, $this->locationId));
    }

    public function broadcastAs(): string
    {
        return 'queue.lab_updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['event' => $this->broadcastAs(), 'location_id' => $this->locationId, 'lab' => $this->lab];
    }
}
