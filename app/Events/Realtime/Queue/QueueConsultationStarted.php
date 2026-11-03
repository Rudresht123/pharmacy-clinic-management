<?php

namespace App\Events\Realtime\Queue;

/** The patient is in the room. */
class QueueConsultationStarted extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.consultation_started';
    }
}
