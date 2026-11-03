<?php

namespace App\Events\Realtime\Queue;

/** Anything else that changes the card: a reassignment, a cancellation, a new room. */
class QueueUpdated extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
