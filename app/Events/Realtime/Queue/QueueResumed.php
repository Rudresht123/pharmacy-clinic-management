<?php

namespace App\Events\Realtime\Queue;

/** The doctor's queue started calling again. */
class QueueResumed extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.resumed';
    }
}
