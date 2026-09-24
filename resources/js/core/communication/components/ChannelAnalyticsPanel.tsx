import { useMemo, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { AreaChart } from '@/shared/components/ui/AreaChart';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { BarList } from '@/shared/components/ui/BarList';
import type { Channel, ChannelAnalytics, ChannelFigures } from '../types';

type Series = 'sent' | 'delivered' | 'failed';

const SERIES: { key: Series; label: string; word: string }[] = [
    { key: 'sent', label: 'Sent', word: 'sent' },
    { key: 'delivered', label: 'Delivered', word: 'delivered' },
    { key: 'failed', label: 'Failed', word: 'failed' },
];

function count(value: number): string {
    return value.toLocaleString('en-IN');
}

/**
 * What actually happened on this channel, counted from the delivery log.
 *
 * Every number here is a count of rows. Nothing is estimated, and where a
 * thing is not measured at all it says so rather than showing a zero — a
 * clinic reading 0% concludes its messages are being ignored and rewrites
 * templates that were fine.
 *
 * The trend line is one series at a time rather than three at once. Three
 * overlapping areas on a 30-day window is a picture nobody can read a number
 * off, and the question is almost always about one of them.
 */
export function ChannelAnalyticsPanel({
    channel,
    figures,
    analytics,
    days,
}: {
    channel: Channel;
    figures: ChannelFigures;
    analytics: ChannelAnalytics;
    days: number;
}) {
    const [series, setSeries] = useState<Series>('sent');

    const chosen = SERIES.find((item) => item.key === series) ?? SERIES[0];

    const points = useMemo(
        () =>
            analytics.daily.map((day) => ({
                label: day.label,
                value: day[series],
                title: day.date,
                major: day.major,
            })),
        [analytics.daily, series],
    );

    const busiest = useMemo(() => {
        const peak = analytics.hours.reduce(
            (best, hour) => (hour.value > best.value ? hour : best),
            { label: '—', value: 0 },
        );

        return peak.value > 0 ? `${peak.label}:00` : null;
    }, [analytics.hours]);

    const nothing = analytics.daily.every((day) => day.sent === 0);

    /*
     * The period-over-period change, which is the comparison that means
     * something: this window against the window immediately before it, counted
     * on the server. An earlier attempt drew the first half of THIS window as
     * a second line, which is not a comparison — it is the same data twice.
     */
    const movement =
        figures.change === null
            ? null
            : `${figures.change > 0 ? '+' : ''}${figures.change}% vs the previous ${days} days`;

    const caption = [
        `${chosen.label} per day`,
        movement,
        busiest ? `busiest hour ${busiest}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="row g-3">
            <div className="col-12">
                <Card
                    className="comm-card"
                    title="Over time"
                    icon="ti ti-chart-line"
                    description={`Counted from the delivery log, last ${days} days`}
                    actions={
                        <div className="comm-filters" role="group" aria-label="Series">
                            {SERIES.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    className={`comm-filter${series === item.key ? ' is-active' : ''}`}
                                    aria-pressed={series === item.key}
                                    onClick={() => setSeries(item.key)}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </div>
                    }
                >
                    {nothing ? (
                        <p className="pd-quiet">
                            <i className="ti ti-chart-line" aria-hidden="true" />
                            Nothing sent in this period, so there is no trend to draw yet.
                        </p>
                    ) : (
                        <AreaChart
                            points={points}
                            valueLabel={chosen.word}
                            caption={caption}
                        />
                    )}
                </Card>
            </div>

            <div className="col-12 col-xxl-5">
                <Card
                    className="comm-card"
                    title="Where they ended up"
                    icon="ti ti-chart-pie"
                    description="Every message, by its last known state"
                >
                    <DonutChart
                        slices={analytics.statuses}
                        centreLabel={count(figures.sent)}
                        empty="Nothing sent in this period."
                        format={count}
                    />

                    <dl className="comm-rates">
                        <div>
                            <dt>Delivery rate</dt>
                            <dd>{figures.delivery_rate === null ? '—' : `${figures.delivery_rate}%`}</dd>
                        </div>

                        <div>
                            <dt>{channel === 'email' ? 'Open rate' : 'Read rate'}</dt>
                            <dd>{figures.read_rate === null ? '—' : `${figures.read_rate}%`}</dd>
                        </div>

                        <div>
                            <dt>Failure rate</dt>
                            <dd>{figures.failure_rate === null ? '—' : `${figures.failure_rate}%`}</dd>
                        </div>
                    </dl>

                    {channel === 'email' && (
                        <p className="pf-soon">
                            <i className="ti ti-info-circle" aria-hidden="true" />
                            Opens are only counted where a provider reports them. Click tracking is
                            not wired up, so it is shown as &ldquo;—&rdquo; rather than zero.
                        </p>
                    )}
                </Card>
            </div>

            <div className="col-12 col-xxl-7">
                <div className="row g-3">
                    <div className="col-12">
                        <Card
                            className="comm-card"
                            title="Most used templates"
                            icon="ti ti-template"
                            description="What this clinic actually sends"
                        >
                            <BarList
                                rows={analytics.templates}
                                empty="No templates have been sent in this period."
                                format={count}
                            />
                        </Card>
                    </div>

                    <div className="col-12">
                        <Card
                            className="comm-card"
                            title="Why messages failed"
                            icon="ti ti-alert-triangle"
                            description="Grouped by reason, worst first"
                        >
                            {analytics.failures.length === 0 ? (
                                <p className="pd-quiet">
                                    <i className="ti ti-circle-check" aria-hidden="true" />
                                    Nothing failed in this period.
                                </p>
                            ) : (
                                <BarList
                                    rows={analytics.failures}
                                    empty="Nothing failed in this period."
                                    format={count}
                                />
                            )}
                        </Card>
                    </div>
                </div>
            </div>

            <div className="col-12">
                <Card
                    className="comm-card"
                    title="When they go out"
                    icon="ti ti-clock"
                    description="Across the day, all twenty-four hours"
                >
                    {/* All 24 bars, including the empty night. Dropping the
                        quiet hours would make a clinic that sends only in the
                        morning look like one that sends round the clock. */}
                    <div className="comm-hours">
                        {analytics.hours.map((hour) => {
                            const peak = Math.max(...analytics.hours.map((h) => h.value), 1);

                            return (
                                <div
                                    className="comm-hour"
                                    key={hour.label}
                                    title={`${hour.label}:00 — ${count(hour.value)}`}
                                >
                                    <span
                                        className="comm-hour-bar"
                                        style={{ height: `${Math.round((hour.value / peak) * 100)}%` }}
                                    />

                                    <small>{hour.label}</small>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </div>
        </div>
    );
}
