<?php

namespace App\Services\Pharmacy\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The span a report is read over.
 *
 * Money has a period; a shelf does not. Sales, purchases, profit and GST all
 * answer "between these two dates"; stock and expiry answer "right now", and
 * ignore this entirely — which is why the screen hides the dates for those two
 * rather than printing a range the figures never used.
 *
 * Both ends are inclusive and read as whole days. A bill rung up at 9pm on the
 * last day of the range belongs to that range, and a window that stopped at
 * midnight would quietly drop a counter's busiest hour.
 */
final class ReportWindow
{
    /** The span a report opens on before anybody chooses one. */
    public const DEFAULT_DAYS = 30;

    /**
     * The longest span that will be answered.
     *
     * Not a performance figure so much as an honesty one: a report over ten
     * years is a query nobody waits for, and silently returning half of it
     * would be worse than shortening the window where the reader can see it.
     */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    /** Both dates are already validated as dates by the controller. */
    public static function fromRequest(Request $request): self
    {
        $to = ($request->date('to') ?? Carbon::today())->copy()->startOfDay();
        $from = ($request->date('from') ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1))
            ->copy()
            ->startOfDay();

        // Somebody who typed the two dates the wrong way round meant the span
        // between them, not an empty one.
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return new self($from, $to);
    }

    public function startsAt(): Carbon
    {
        return $this->from->copy()->startOfDay();
    }

    public function endsAt(): Carbon
    {
        return $this->to->copy()->endOfDay();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** Whole days, both ends counted — a one-day report is one day, not zero. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'days' => $this->days(),
        ];
    }

    /**
     * The chart's points, folded to a step the span can actually be read at.
     *
     * Built from the window rather than from the rows that came back: a day
     * that took nothing is a quiet day and belongs on the chart, where leaving
     * it out draws a month busier than it was.
     *
     * Past about six weeks the days fold into weeks, and past seven months
     * into months. Three hundred ticks along an axis is a smear, and the
     * question a long report is opened with — is this going up — is one a
     * weekly line answers better than a daily one anyway.
     *
     * @param  array<string, float>  $byDay  takings, spend or anything else, keyed Y-m-d
     * @return list<array{date: string, label: string, title: string, value: float}>
     */
    public function series(array $byDay): array
    {
        $step = match (true) {
            $this->days() <= 45 => 'day',
            $this->days() <= 210 => 'week',
            default => 'month',
        };

        $points = [];
        $cursor = $this->startsAt();
        $end = $this->endsAt();

        while ($cursor->lte($end)) {
            $start = match ($step) {
                'week' => $cursor->copy()->startOfWeek(),
                'month' => $cursor->copy()->startOfMonth(),
                default => $cursor->copy(),
            };

            // A week or a month that the window starts partway through is
            // named by the day the report actually begins, not by a date it
            // never covered.
            if ($start->lt($this->from)) {
                $start = $this->from->copy();
            }

            $key = $start->toDateString();

            $points[$key] ??= [
                'date' => $key,
                'label' => match ($step) {
                    'month' => $start->format('M'),
                    'week' => $start->format('j M'),
                    default => $start->format('j'),
                },
                'title' => match ($step) {
                    'month' => $start->format('F Y'),
                    'week' => 'Week of '.$start->format('j M'),
                    default => $start->format('j M Y'),
                },
                'value' => 0.0,
            ];

            $points[$key]['value'] += (float) ($byDay[$cursor->toDateString()] ?? 0);

            $cursor->addDay();
        }

        return array_values($points);
    }

    /**
     * The handful of days that took the most, named as a reader would say them.
     *
     * Always by day, whatever step the chart settled on: "the best day" is a
     * day, and folding it into a week would answer a different question.
     *
     * @param  array<string, float>  $byDay
     * @return list<array{label: string, value: float}>
     */
    public function best(array $byDay, int $limit = 6): array
    {
        arsort($byDay);

        $best = [];

        foreach (array_slice($byDay, 0, $limit, true) as $date => $value) {
            if ((float) $value <= 0) {
                continue;
            }

            $best[] = [
                'label' => Carbon::parse($date)->format('D j M'),
                'value' => (float) $value,
            ];
        }

        return $best;
    }
}
