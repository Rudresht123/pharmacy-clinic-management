<?php

namespace App\Events\Realtime\Queue;

/** The doctor has finished with the patient. */
class QueueConsultationCompleted extends QueueBroadcast
{
    public function broadcastAs(): string
    {
        return 'queue.consultation_completed';
    }
}
