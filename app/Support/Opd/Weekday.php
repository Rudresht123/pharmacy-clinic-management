<?php

namespace App\Support\Opd;

use Illuminate\Support\Carbon;

/**
 * The weekday numbering the OPD schedule is stored in.
 *
 * Monday is 0, Sunday is 6.
 *
 * This is the only place that number is derived from a date. Postgres offers
 * two different weekday functions and they disagree — DOW makes Sunday 0,
 * ISODOW makes Monday 1 — so a second implementation somewhere else is an
 * off-by-one waiting to happen in slot generation, where being one day out
 * silently books patients on the wrong day.
 *
 * Not to be confused with App\Repositories\Tenant\CustomerRepository's
 * byWeekday(), which groups by Postgres DOW to draw a chart and stores
 * nothing. That is presentation; this is a stored value.
 */
class Weekday
{
    public const MONDAY = 0;

    public const SUNDAY = 6;

    /**
     * The SQL expression turning a date column into this numbering.
     *
     * ISODOW gives Monday 1 … Sunday 7, so subtracting one lands exactly on
     * Monday 0 … Sunday 6 with no lookup table.
     */
    public static function sqlFrom(string $column): string
    {
        return "(EXTRACT(ISODOW FROM {$column})::int - 1)";
    }

    /** The stored weekday for a given date. */
    public static function of(Carbon $date): int
    {
        // Carbon's dayOfWeek is Sunday 0 … Saturday 6.
        return ($date->dayOfWeek + 6) % 7;
    }

    /**
     * Short labels, in stored order.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    }

    public static function label(int $weekday): string
    {
        return self::labels()[$weekday] ?? (string) $weekday;
    }

    /**
     * Full names, for a screen that has room for them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    }

    /** @return list<int> */
    public static function all(): array
    {
        return range(self::MONDAY, self::SUNDAY);
    }
}
