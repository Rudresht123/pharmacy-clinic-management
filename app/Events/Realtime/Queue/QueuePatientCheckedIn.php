<?php

namespace App\Events\Realtime\Queue;

/** A patient joined this doctor's queue. */
class QueuePatientCheckedIn extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.patient_checked_in';
    }
}
