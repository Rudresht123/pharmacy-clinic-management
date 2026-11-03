<?php

namespace App\Events\Realtime\Queue;

/** Somebody's priority changed, so the order may have. */
class QueuePriorityChanged extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.priority_changed';
    }
}
