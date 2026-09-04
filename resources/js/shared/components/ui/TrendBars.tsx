import { useState } from 'react';

export interface TrendPoint {
    /** Axis tick — short, e.g. "Sep". */
    label: string;
    value: number;
    /** Fuller wording for the tooltip, e.g. "September 2026". */
    title?: string;
}

/**
 * Columns over time, one series.
 *
 * Only the most recent column carries a printed value — a number on every
 * column floods the chart and stops being read. The rest are available on
 * hover and, for anyone who cannot hover, in the figures beside the chart.
 *
 * No legend: with one series the caption already names what is plotted.
 */
export function TrendBars({ points, caption }: { points: TrendPoint[]; caption?: string }) {
    const [hovered, setHovered] = useState<number | null>(null);

    const peak = Math.max(...points.map((point) => point.value), 1);
    const lastIndex = points.length - 1;

    return (
        <div className="trend">
            {caption && <p className="trend-caption">{caption}</p>}

            <div className="trend-plot">
                {points.map((point, index) => {
                    // A zero month still needs a visible foot, or the axis
                    // reads as if the month were missing rather than quiet.
                    const height = point.value === 0 ? 2 : Math.max((point.value / peak) * 100, 4);

                    return (
                        <div
                            className="trend-slot"
                            key={point.label + index}
                            onMouseEnter={() => setHovered(index)}
                            onMouseLeave={() => setHovered(null)}
                        >
                            {hovered === index && (
                                <span className="trend-tip" role="status">
                                    {point.title ?? point.label}: <b>{point.value}</b>
                                </span>
                            )}

                            <span className="trend-bar-wrap">
                                <span
                                    className={`trend-bar${point.value === 0 ? ' is-zero' : ''}`}
                                    style={{ height: `${height}%` }}
                                />
                            </span>

                            <span className="trend-tick">{point.label}</span>

                            {index === lastIndex && (
                                <span className="trend-endlabel">{point.value}</span>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
