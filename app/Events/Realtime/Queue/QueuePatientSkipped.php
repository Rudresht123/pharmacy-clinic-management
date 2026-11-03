<?php

namespace App\Events\Realtime\Queue;

/** A token was set aside. */
class QueuePatientSkipped extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.patient_skipped';
    }
}
