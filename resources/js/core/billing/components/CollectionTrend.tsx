import { useState } from 'react';
import type { CategorySplit } from '../api';

export interface CollectionTrendPoint {
    date: string;
    unit: 'day' | 'week' | 'month';
    by_category: CategorySplit;
}

const SEGMENTS: { key: keyof CategorySplit; label: string; hue: number }[] = [
    { key: 'consultation', label: 'OPD', hue: 1 },
    { key: 'pharmacy', label: 'Pharmacy', hue: 2 },
    { key: 'laboratory', label: 'Laboratory', hue: 3 },
    { key: 'procedure', label: 'Procedures', hue: 4 },
    { key: 'other', label: 'Other', hue: 5 },
];

/** The plot's height in px — the bars, not the axis labels under them. */
const PLOT = 210;

/** No more date labels than this under the bars; the rest are a hover away. */
const MAX_TICKS = 8;

/** How a bucket's tick reads — a week shows its starting day, a month its name. */
function tick(point: CollectionTrendPoint): string {
    const date = new Date(point.date);

    if (point.unit === 'month') {
        return date.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' });
    }

    return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

/** The tooltip's heading — says what the bar covers, not just where it starts. */
function heading(point: CollectionTrendPoint): string {
    const date = new Date(point.date);

    if (point.unit === 'month') {
        return date.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
    }

    if (point.unit === 'week') {
        return `Week of ${date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}`;
    }

    return date.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * The axis top, rounded up to a figure somebody would say out loud — 5,000
 * rather than 4,737 — so the gridlines land on round amounts.
 */
function niceCeil(value: number): number {
    if (value <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));

    for (const step of [1, 2, 2.5, 5, 10]) {
        if (step * magnitude >= value) {
            return step * magnitude;
        }
    }

    return 10 * magnitude;
}

/** ₹2.5k, ₹1.2L — an axis label has room for three or four characters. */
function compact(value: number): string {
    if (value >= 100000) {
        return `₹${+(value / 100000).toFixed(1)}L`;
    }

    if (value >= 1000) {
        return `₹${+(value / 1000).toFixed(1)}k`;
    }

    return `₹${Math.round(value)}`;
}

/**
 * One stacked bar per bucket — where that day's collection came from.
 *
 * A stack rather than five side-by-side bars: five thin slivers per day
 * cannot be compared by eye, where a shared baseline of segments can.
 *
 * HOVER, two ways. A column shows its whole breakdown beside the bar and the
 * other columns step back; a legend entry lights one category through every
 * bar. The tooltip sits beside the column, inside the plot, rather than above
 * the bar — above a tall bar it would run out of the card.
 */
export function CollectionTrend({
    points,
    format = String,
}: {
    points: CollectionTrendPoint[];
    format?: (value: number) => string;
}) {
    const [hovered, setHovered] = useState<number | null>(null);
    const [focus, setFocus] = useState<keyof CategorySplit | null>(null);

    if (points.length === 0) {
        return <p className="bar-list-empty">Nothing to plot in this window.</p>;
    }

    const totals = points.map((point) =>
        SEGMENTS.reduce((sum, segment) => sum + (point.by_category[segment.key] || 0), 0),
    );
    const top = niceCeil(Math.max(...totals));
    const quiet = totals.every((total) => total === 0);

    // Thinned from the newest end, so the latest bucket is always labelled.
    const every = Math.ceil(points.length / MAX_TICKS);
    const labelled = (index: number) => (points.length - 1 - index) % every === 0;

    return (
        <div className="ct">
            <div className="ct-legend">
                {SEGMENTS.map((segment) => (
                    <span
                        key={segment.key}
                        className={`ct-legend-item${focus && focus !== segment.key ? ' is-dim' : ''}`}
                        onMouseEnter={() => setFocus(segment.key)}
                        onMouseLeave={() => setFocus(null)}
                    >
                        <i style={{ background: `var(--cat-${segment.hue})` }} aria-hidden="true" />
                        {segment.label}
                    </span>
                ))}
            </div>

            <div className="ct-chart">
                <div className="ct-axis" style={{ height: PLOT }} aria-hidden="true">
                    <span style={{ top: 0 }}>{compact(top)}</span>
                    <span style={{ top: '50%' }}>{compact(top / 2)}</span>
                    <span style={{ top: '100%' }}>₹0</span>
                </div>

                <div className="ct-plot" style={{ height: PLOT }}>
                    <div className="ct-grid" style={{ top: 0 }} />
                    <div className="ct-grid" style={{ top: '50%' }} />
                    <div className="ct-grid is-base" style={{ top: '100%' }} />

                    {quiet && <div className="ct-quiet">No collection in this period</div>}

                    <div className="ct-bars">
                        {points.map((point, index) => {
                            const total = totals[index];
                            const height = total === 0 ? 0 : Math.max((total / top) * 100, 1.5);
                            const active = hovered === index;
                            // The tooltip opens away from the nearer edge.
                            const side = index < points.length / 2 ? 'is-right' : 'is-left';

                            return (
                                <div
                                    key={point.date}
                                    className={`ct-col${active ? ' is-active' : ''}${
                                        hovered !== null && !active ? ' is-dim' : ''
                                    }`}
                                    tabIndex={0}
                                    aria-label={`${heading(point)}: ${format(total)}`}
                                    onMouseEnter={() => setHovered(index)}
                                    onMouseLeave={() => setHovered(null)}
                                    onFocus={() => setHovered(index)}
                                    onBlur={() => setHovered(null)}
                                >
                                    <div className="ct-bar" style={{ height: `${height}%` }}>
                                        {SEGMENTS.map((segment) => {
                                            const value = point.by_category[segment.key] || 0;

                                            if (value <= 0) return null;

                                            return (
                                                <div
                                                    key={segment.key}
                                                    className={`ct-seg${focus && focus !== segment.key ? ' is-dim' : ''}`}
                                                    style={{
                                                        height: `${(value / total) * 100}%`,
                                                        background: `var(--cat-${segment.hue})`,
                                                    }}
                                                />
                                            );
                                        })}
                                    </div>

                                    {active && (
                                        <div className={`ct-tip ${side}`} role="status">
                                            <div className="ct-tip-head">{heading(point)}</div>

                                            {total === 0 ? (
                                                <div className="ct-tip-none">Nothing collected</div>
                                            ) : (
                                                SEGMENTS.map((segment) => {
                                                    const value = point.by_category[segment.key] || 0;

                                                    if (value <= 0) return null;

                                                    return (
                                                        <div key={segment.key} className="ct-tip-row">
                                                            <i style={{ background: `var(--cat-${segment.hue})` }} />
                                                            <span>{segment.label}</span>
                                                            <b>{format(value)}</b>
                                                            <em>{Math.round((value / total) * 100)}%</em>
                                                        </div>
                                                    );
                                                })
                                            )}

                                            <div className="ct-tip-total">
                                                <span>Total</span>
                                                <b>{format(total)}</b>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="ct-ticks">
                {points.map((point, index) => (
                    <span key={point.date} className={hovered === index ? 'is-active' : undefined}>
                        {labelled(index) || hovered === index ? tick(point) : ''}
                    </span>
                ))}
            </div>
        </div>
    );
}
