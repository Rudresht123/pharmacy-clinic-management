<?php

namespace App\Services\Queue;

use App\Models\Tenant\Appointment;
use Illuminate\Support\Collection;

/**
 * Who is next, inside one doctor's queue.
 *
 * Emergency first, always and whatever the wait: that is the override. Everybody
 * else is ordered by when they count as having arrived, and an urgent patient
 * counts as having arrived half an hour earlier than they did.
 *
 * THAT HEAD START IS THE FAIRNESS RULE. A scheme of "all urgent before all
 * normal" lets a steady trickle of urgent cases keep somebody waiting all day.
 * Here a normal patient who has already waited longer than the head start stays
 * ahead of an urgent patient who has just walked in, so nobody can be passed
 * indefinitely. It also means the order between two people never changes on its
 * own while they sit there, so a list on a screen does not reshuffle by itself.
 *
 * Booked patients get nothing for having booked: a promised slot is shown so a
 * person can choose to call out of turn, as the queue has always said.
 */
final class QueueOrder
{
    public const URGENT_HEAD_START_MINUTES = 30;

    /**
     * @param  Collection<int, Appointment>  $waiting
     * @return Collection<int, Appointment>
     */
    public static function sort(Collection $waiting): Collection
    {
        return $waiting
            ->sort(function (Appointment $a, Appointment $b) {
                return [self::rank($a), self::countsFrom($a), $a->token_no ?? PHP_INT_MAX]
                    <=> [self::rank($b), self::countsFrom($b), $b->token_no ?? PHP_INT_MAX];
            })
            ->values();
    }

    /** @param  Collection<int, Appointment>  $waiting */
    public static function first(Collection $waiting): ?Appointment
    {
        return self::sort($waiting)->first();
    }

    private static function rank(Appointment $appointment): int
    {
        return $appointment->queue_priority === Appointment::PRIORITY_EMERGENCY ? 0 : 1;
    }

    /** The moment they count as having arrived, as a timestamp. */
    private static function countsFrom(Appointment $appointment): int
    {
        $arrived = $appointment->checked_in_at?->getTimestamp() ?? PHP_INT_MAX;

        return $appointment->queue_priority === Appointment::PRIORITY_URGENT
            ? $arrived - (self::URGENT_HEAD_START_MINUTES * 60)
            : $arrived;
    }
}
