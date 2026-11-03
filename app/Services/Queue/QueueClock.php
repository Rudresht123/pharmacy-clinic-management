<?php

namespace App\Services\Queue;

use App\Services\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

/**
 * What time it is at this clinic.
 *
 * Tokens reset at the clinic's own midnight, not the server's, so "today" for a
 * queue is read in the organization's timezone. Nothing else about the queue
 * needs to know: a new day simply names a new counter row.
 */
final class QueueClock
{
    public static function now(): Carbon
    {
        return now(self::timezone());
    }

    /** Today's date at this clinic, as `queue_date` and `appointment_date` hold it. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    public static function timezone(): string
    {
        return CurrentTenant::organization()?->timezone ?: (string) config('app.timezone');
    }
}
