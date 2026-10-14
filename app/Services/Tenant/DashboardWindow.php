<?php

namespace App\Services\Tenant;

use Illuminate\Support\Carbon;

/**
 * The span of days the organization dashboard is about.
 *
 * Every period figure on the screen — revenue, purchases, appointments, the
 * visit chart — is read over this window and compared against the one just
 * before it, of the same length. Built once per request so a card and the
 * chart beside it can never disagree about which days "this period" means.
 *
 * Defaults to the current calendar month, which is what the screen opens on.
 */
final class DashboardWindow
{
    public readonly Carbon $from;

    public readonly Carbon $to;

    public function __construct(?Carbon $from = null, ?Carbon $to = null)
    {
        $this->from = ($from ?? Carbon::today()->startOfMonth())->copy()->startOfDay();
        $this->to = ($to ?? Carbon::today()->endOfMonth())->copy()->endOfDay();
    }

    /** How many days the window covers, both ends included. */
    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    /**
     * The window to compare against.
     *
     * A whole calendar month is compared with the whole month before it —
     * October against September, not October against the 31 days ending on
     * 30 September, which would start on the last day of August. Anything
     * else is compared with the same number of days immediately before it.
     */
    public function previous(): self
    {
        if ($this->isCalendarMonth()) {
            $month = $this->from->copy()->subMonthNoOverflow();

            return new self($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
        }

        $to = $this->from->copy()->subDay();

        return new self($to->copy()->subDays($this->days() - 1), $to);
    }

    /** Whether the window is exactly one calendar month. */
    public function isCalendarMonth(): bool
    {
        return $this->from->day === 1
            && $this->to->isSameDay($this->from->copy()->endOfMonth());
    }

    /**
     * What the comparison is called on screen — "vs last month" when it is
     * one, rather than a phrase that makes somebody work it out.
     */
    public function comparisonLabel(): string
    {
        return $this->isCalendarMonth() ? 'vs last month' : 'vs previous period';
    }

    /** @return array{from: string, to: string, compare: string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'compare' => $this->comparisonLabel(),
        ];
    }
}
