import { useState } from 'react';
import type { OpdFlowPoint } from '../types';

/**
 * A rounded axis maximum, and the ticks to reach it.
 *
 * Bars have to be read against something. Scaling to the tallest column alone
 * makes every chart look equally busy — a peak of 3 draws the same as a peak
 * of 30 — so the axis climbs in 5s, 10s or 25s to a round number above the
 * peak, and the same four gridlines carry it whatever the day was like.
 */
function axisFor(peak: number): { max: number; ticks: number[] } {
    const step = peak <= 8 ? 2 : peak <= 20 ? 5 : peak <= 40 ? 10 : 25;
    const max = Math.max(step, Math.ceil(peak / step) * step);

    const ticks: number[] = [];

    for (let at = max; at >= 0; at -= step) {
        ticks.push(at);
    }

    return { max, ticks };
}

/**
 * Arrivals through the day, split by whether the patient had been before.
 *
 * Stacked rather than side by side: the two parts add up to a real total —
 * how many came through the door that hour — so the full column height is
 * itself a number worth reading. Paired bars would make that total something
 * you have to work out.
 *
 * One axis, one unit (patients). The legend names both series and the tooltip
 * gives the split, so identity is never carried by colour alone.
 */
export function FlowChart({ points }: { points: OpdFlowPoint[] }) {
    const [hovered, setHovered] = useState<number | null>(null);

    const peak = Math.max(...points.map((point) => point.value), 1);
    const { max, ticks } = axisFor(peak);

    return (
        <div className="fl">
            <div className="fl-legend">
                <span className="fl-key is-fresh">
                    <i aria-hidden="true" />
                    New patients
                </span>
                <span className="fl-key is-returning">
                    <i aria-hidden="true" />
                    Follow-up
                </span>
            </div>

            <div className="fl-grid">
                {/* The scale, and the lines that carry it across the plot. */}
                <div className="fl-axis" aria-hidden="true">
                    {ticks.map((tick) => (
                        <span key={tick}>{tick}</span>
                    ))}
                </div>

                <div className="fl-plot">
                    <div className="fl-rules" aria-hidden="true">
                        {ticks.map((tick) => (
                            <span key={tick} />
                        ))}
                    </div>

                    {points.map((point, index) => {
                        /*
                         * A quiet hour still needs a visible foot, or the axis
                         * reads as if the hour were missing rather than empty.
                         */
                        const height =
                            point.value === 0 ? 2 : Math.max((point.value / max) * 100, 3);

                        const freshShare =
                            point.value === 0 ? 0 : (point.fresh / point.value) * 100;

                        return (
                            <div
                                className="fl-slot"
                                key={point.label}
                                onMouseEnter={() => setHovered(index)}
                                onMouseLeave={() => setHovered(null)}
                                onFocus={() => setHovered(index)}
                                onBlur={() => setHovered(null)}
                                tabIndex={0}
                                role="img"
                                aria-label={`${point.label}: ${point.fresh} new, ${point.returning} follow-up`}
                            >
                                {hovered === index && (
                                    <span className="fl-tip" role="status">
                                        <b>{point.label}</b>
                                        <span className="is-fresh">
                                            <i aria-hidden="true" />
                                            New: {point.fresh}
                                        </span>
                                        <span className="is-returning">
                                            <i aria-hidden="true" />
                                            Follow-up: {point.returning}
                                        </span>
                                    </span>
                                )}

                                <span
                                    className={`fl-stack${point.value === 0 ? ' is-zero' : ''}`}
                                    style={{ height: `${height}%` }}
                                >
                                    {/* Follow-up on the baseline: it is the
                                        steadier of the two, so the part that
                                        varies reads off the top. */}
                                    <span
                                        className="fl-part is-returning"
                                        style={{ height: `${100 - freshShare}%` }}
                                    />
                                    <span
                                        className="fl-part is-fresh"
                                        style={{ height: `${freshShare}%` }}
                                    />
                                </span>
                            </div>
                        );
                    })}
                </div>

                <div className="fl-ticks" aria-hidden="true">
                    {points.map((point) => (
                        <span key={point.label}>{point.label}</span>
                    ))}
                </div>
            </div>
        </div>
    );
}
