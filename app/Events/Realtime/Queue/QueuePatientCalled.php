<?php

namespace App\Events\Realtime\Queue;

/** A token was called, or called again. Televisions announce this one. */
class QueuePatientCalled extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.patient_called';
    }
}
