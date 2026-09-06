import { useState } from 'react';

export interface WaitPoint {
    /** Axis tick — "9 AM". */
    label: string;
    /** Mean wait, in whole minutes, for people who checked in that hour. */
    minutes: number;
    /** How many that average is over — a mean of one is not a trend. */
    of: number;
}

/** A rounded axis maximum and the ticks to reach it. */
function axisFor(peak: number): { max: number; ticks: number[] } {
    const step = peak <= 10 ? 5 : peak <= 30 ? 10 : peak <= 60 ? 20 : 30;
    const max = Math.max(step, Math.ceil(peak / step) * step);

    const ticks: number[] = [];

    for (let at = max; at >= 0; at -= step) {
        ticks.push(at);
    }

    return { max, ticks };
}

/**
 * How long people waited, hour by hour.
 *
 * A line rather than columns: this is one measure moving over time, and the
 * question asked of it — is the wait growing — is about the slope. Columns
 * make you compare heights; a line draws the answer.
 *
 * Only hours somebody actually checked in are plotted. A clinic that opens at
 * ten should not have a line starting at zero for the two hours before it,
 * which reads as "nobody waited" rather than "nothing happened".
 */
export function WaitTrend({
    points,
    warn,
    critical,
}: {
    points: WaitPoint[];
    warn: number;
    critical: number;
}) {
    const [hovered, setHovered] = useState<number | null>(null);

    if (points.length === 0) {
        return (
            <div className="opd-quiet">
                <i className="ti ti-clock-off" aria-hidden="true" />
                <p>Nobody has been called in yet, so there is no wait to average.</p>
            </div>
        );
    }

    const peak = Math.max(...points.map((point) => point.minutes), critical);
    const { max, ticks } = axisFor(peak);

    /* The polyline, in a 0–100 box the CSS stretches to whatever width it has. */
    const step = points.length > 1 ? 100 / (points.length - 1) : 0;

    const at = (index: number, minutes: number) => ({
        x: points.length > 1 ? index * step : 50,
        y: 100 - (minutes / max) * 100,
    });

    const line = points
        .map((point, index) => {
            const { x, y } = at(index, point.minutes);

            return `${x},${y}`;
        })
        .join(' ');

    const area = `0,100 ${line} 100,100`;

    return (
        <div className="wt">
            <div className="wt-grid">
                <div className="wt-axis" aria-hidden="true">
                    {ticks.map((tick) => (
                        <span key={tick}>{tick}</span>
                    ))}
                </div>

                <div className="wt-plot">
                    <div className="wt-rules" aria-hidden="true">
                        {ticks.map((tick) => (
                            <span key={tick} />
                        ))}
                    </div>

                    {/*
                        The threshold, drawn where it actually falls rather than
                        described in the caption. A line at twenty minutes turns
                        every point into "over" or "under" without anybody having
                        to hold the number in their head.
                    */}
                    {critical <= max && (
                        <span
                            className="wt-limit"
                            style={{ bottom: `${(critical / max) * 100}%` }}
                            aria-hidden="true"
                        >
                            <em>{critical}m</em>
                        </span>
                    )}

                    <svg
                        className="wt-svg"
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <polygon className="wt-area" points={area} />
                        <polyline className="wt-line" points={line} />
                    </svg>

                    {/*
                        The dots and their hit targets sit outside the SVG,
                        because a viewBox stretched by preserveAspectRatio="none"
                        would squash a circle into an ellipse and a 1px stroke
                        into whatever the width happens to make it.
                    */}
                    {points.map((point, index) => {
                        const { x, y } = at(index, point.minutes);
                        const band =
                            point.minutes >= critical
                                ? 'is-critical'
                                : point.minutes >= warn
                                  ? 'is-warn'
                                  : 'is-ok';

                        return (
                            <button
                                type="button"
                                key={point.label}
                                className={`wt-dot ${band}${hovered === index ? ' is-on' : ''}`}
                                style={{ left: `${x}%`, top: `${y}%` }}
                                onMouseEnter={() => setHovered(index)}
                                onMouseLeave={() => setHovered(null)}
                                onFocus={() => setHovered(index)}
                                onBlur={() => setHovered(null)}
                                aria-label={`${point.label}: ${point.minutes} minutes average over ${point.of}`}
                            >
                                {hovered === index && (
                                    <span className="wt-tip" role="status">
                                        <b>{point.label}</b>
                                        <span>{point.minutes} min average</span>
                                        <em>
                                            over {point.of} {point.of === 1 ? 'patient' : 'patients'}
                                        </em>
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                <div className="wt-ticks" aria-hidden="true">
                    {points.map((point) => (
                        <span key={point.label}>{point.label}</span>
                    ))}
                </div>
            </div>
        </div>
    );
}
