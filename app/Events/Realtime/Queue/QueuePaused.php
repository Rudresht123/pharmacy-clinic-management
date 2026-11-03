<?php

namespace App\Events\Realtime\Queue;

/** The doctor's queue stopped calling. */
class QueuePaused extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.paused';
    }
}
