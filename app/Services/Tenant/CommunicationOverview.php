<?php

namespace App\Services\Tenant;

use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use Illuminate\Support\Carbon;

/**
 * What one channel's screen is made of, worked out rather than stored.
 *
 * Both halves of this are derived on purpose:
 *
 *   The FIGURES are counted from the log over a window. A totals column
 *   updated on every send drifts the first time a job retries, and there is no
 *   way to notice — a count of rows cannot.
 *
 *   The SETUP CHECKLIST is read from the same state the rest of the
 *   application uses. A step that stores whether it is done can say "yes"
 *   about a number that has since been disconnected; asking the connection
 *   itself cannot be wrong.
 */
class CommunicationOverview
{
    /** The window the dashboard's figures cover, unless asked otherwise. */
    public const DEFAULT_DAYS = 30;

    /**
     * Sent, delivered, read, failed — each one a subset of the one before it.
     *
     * Rates are given against what they are a proportion OF, which is not
     * always the total: a read rate is a share of what was delivered, because
     * a message that never arrived was never a candidate for being read.
     *
     * @return array<string, mixed>
     */
    public function figures(string $channel, int $days = self::DEFAULT_DAYS): array
    {
        $since = Carbon::now()->subDays($days);

        $counts = MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = (int) $counts->sum();
        $failed = (int) $counts->get(MessageLog::FAILED, 0);
        $read = (int) $counts->get(MessageLog::READ, 0);

        // Read implies delivered, so it is counted in both.
        $delivered = (int) $counts->get(MessageLog::DELIVERED, 0) + $read;

        $previous = MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->whereBetween('created_at', [Carbon::now()->subDays($days * 2), $since])
            ->count();

        return [
            'days' => $days,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,

            'delivery_rate' => $this->rate($delivered, $sent),
            'read_rate' => $this->rate($read, $delivered),
            'failure_rate' => $this->rate($failed, $sent),

            // Null rather than zero when there is nothing to compare against:
            // "no change" and "no previous month" are different answers.
            'change' => $previous > 0 ? (int) round((($sent - $previous) / $previous) * 100) : null,
        ];
    }

    /**
     * The charts behind the figures.
     *
     * Everything here is COUNTED FROM THE DELIVERY LOG. Nothing is estimated
     * and nothing is padded — a reporting screen that invents a shape is worse
     * than one that shows a flat line, because a clinic acts on it.
     *
     * @return array<string, mixed>
     */
    public function analytics(string $channel, int $days = self::DEFAULT_DAYS): array
    {
        return [
            'daily' => $this->daily($channel, $days),
            'statuses' => $this->statuses($channel, $days),
            'templates' => $this->topTemplates($channel, $days),
            'failures' => $this->failureReasons($channel, $days),
            'hours' => $this->byHour($channel, $days),
        ];
    }

    /**
     * One row per day, including the days nothing was sent.
     *
     * THE GAPS MATTER. Plotting only the days that have rows draws a line
     * straight over a quiet weekend and turns an outage into a gentle slope —
     * the shape a clinic reads to decide whether messaging is working.
     *
     * @return list<array<string, mixed>>
     */
    private function daily(string $channel, int $days): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        $rows = MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');

        $series = [];

        for ($cursor = $since->copy(); $cursor->lte(Carbon::now()); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $counts = $rows->get($key, collect())->pluck('total', 'status');

            $read = (int) $counts->get(MessageLog::READ, 0);

            $series[] = [
                'date' => $key,

                /*
                 * The day number alone, with the month only where it CHANGES.
                 * "25 Aug 26 Aug 27 Aug" repeats the month thirty times and
                 * turns the axis into a smear; the month is the one thing that
                 * is obvious from context anyway. The full date rides along in
                 * `date` for the tooltip.
                 */
                'label' => $cursor->day === 1 || $cursor->eq($since)
                    ? $cursor->format('j M')
                    : $cursor->format('j'),

                // The month markers must survive the axis thinning, or the
                // chart stops saying which month any of it was.
                'major' => $cursor->day === 1 || $cursor->eq($since),
                'sent' => (int) $counts->sum(),
                // Read implies delivered, so it is counted in both.
                'delivered' => (int) $counts->get(MessageLog::DELIVERED, 0) + $read,
                'failed' => (int) $counts->get(MessageLog::FAILED, 0),
            ];
        }

        return $series;
    }

    /**
     * Where everything ended up, as the funnel rather than as five equals.
     *
     * @return list<array{label: string, value: int, muted?: bool}>
     */
    private function statuses(string $channel, int $days): array
    {
        $counts = MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [
            MessageLog::READ => $channel === CommunicationChannel::EMAIL ? 'Opened' : 'Read',
            MessageLog::DELIVERED => 'Delivered',
            MessageLog::SENT => 'Sent, not yet confirmed',
            MessageLog::QUEUED => 'Queued',
            MessageLog::FAILED => 'Failed',
        ];

        $slices = [];

        foreach ($labels as $status => $label) {
            $value = (int) $counts->get($status, 0);

            if ($value === 0) {
                continue;
            }

            $slices[] = [
                'label' => $label,
                'value' => $value,

                // Queued is an absence of an outcome, not an outcome. Giving
                // it a colour of its own would read as a fifth result.
                'muted' => $status === MessageLog::QUEUED,
            ];
        }

        return $slices;
    }

    /**
     * Which templates the clinic actually leans on.
     *
     * Answers "what are we sending", which is the question behind most
     * requests to see the log — and it is never what somebody expects.
     *
     * @return list<array{label: string, value: int}>
     */
    private function topTemplates(string $channel, int $days): array
    {
        return MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->whereNotNull('template_name')
            ->selectRaw('template_name, count(*) as total')
            ->groupBy('template_name')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn (MessageLog $row) => [
                'label' => (string) $row->template_name,
                'value' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Why messages failed, grouped.
     *
     * The single most useful panel on the screen when something is wrong, and
     * the reason is truncated rather than grouped loosely: two failures that
     * differ only in a phone number are the same fault.
     *
     * @return list<array{label: string, value: int}>
     */
    private function failureReasons(string $channel, int $days): array
    {
        return MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->where('status', MessageLog::FAILED)
            ->whereNotNull('failure_reason')
            ->selectRaw('left(failure_reason, 60) as reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn (MessageLog $row) => [
                'label' => (string) $row->reason,
                'value' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * When messages go out, across the day.
     *
     * All twenty-four hours, so an empty night reads as an empty night rather
     * than being squeezed off the chart.
     *
     * @return list<array{label: string, value: int}>
     */
    private function byHour(string $channel, int $days): array
    {
        $counts = MessageLog::query()
            ->forChannel($channel)
            ->real()
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->selectRaw('extract(hour from created_at) as hour, count(*) as total')
            ->groupBy('hour')
            ->pluck('total', 'hour');

        return collect(range(0, 23))
            ->map(fn (int $hour) => [
                'label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                'value' => (int) ($counts->get($hour) ?? $counts->get((string) $hour) ?? 0),
            ])
            ->all();
    }

    /**
     * The connection, step by step, each one read from what it describes.
     *
     * @return list<array{key: string, label: string, state: string, done: bool}>
     */
    public function setupSteps(CommunicationChannel $channel): array
    {
        $approved = MessageTemplate::query()
            ->forChannel($channel->channel)
            ->sendable()
            ->exists();

        // Any send at all, test or not — the step is "has this ever worked",
        // and a test is exactly how somebody proves it.
        $hasSent = MessageLog::query()
            ->forChannel($channel->channel)
            ->whereIn('status', [MessageLog::SENT, ...MessageLog::ARRIVED])
            ->exists();

        return [
            $this->step('account', 'Business account', $channel->is_connected, 'Connected', 'Not connected'),
            $this->step('handle', 'Phone number', $channel->is_verified, 'Verified', 'Unverified'),
            $this->step('templates', 'Message templates', $approved, 'Approved', 'None approved'),
            $this->step('webhook', 'Webhook configuration', $channel->webhook_configured, 'Configured', 'Not set up'),
            $this->step('test', 'Test message', $hasSent, 'Sent', 'Pending'),
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, done: bool}
     */
    private function step(string $key, string $label, bool $done, string $yes, string $no): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $done ? $yes : $no, 'done' => $done];
    }

    /** A whole-number percentage, or null when the base is zero. */
    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : null;
    }
}
