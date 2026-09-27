import { useState } from 'react';

export interface TrendPoint {
    date: string;
    unit: 'day' | 'week' | 'month';
    invoiced: number;
    paid: number;
    outstanding: number;
}

/** How a bucket's tick reads — a week shows its starting day, a month its name. */
function tick(point: TrendPoint): string {
    const date = new Date(point.date);

    if (point.unit === 'month') {
        return date.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' });
    }

    return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

/**
 * Invoiced against paid, with what is still owed drawn over the top.
 *
 * Two bars and a line, because they answer different questions: the bars are
 * volume — how much was billed and how much came in — and the line is the
 * gap, which is the thing somebody running a counter actually watches. A
 * third bar would make the gap something you compute by eye rather than see.
 *
 * The line is SVG over the same grid the bars sit on. Its points are placed
 * by percentage of the same peak, so the two scales cannot drift apart.
 */
export function RevenueTrend({
    points,
    format = String,
}: {
    points: TrendPoint[];
    format?: (value: number) => string;
}) {
    const [hovered, setHovered] = useState<number | null>(null);

    if (points.length === 0) {
        return <p className="bar-list-empty">Nothing to plot in this window.</p>;
    }

    const peak = Math.max(
        ...points.flatMap((point) => [point.invoiced, point.paid, point.outstanding]),
        1,
    );

    /* A quiet bucket still needs a visible foot, or the axis reads as if it
       were missing rather than empty. */
    const height = (value: number) => (value === 0 ? 1.5 : Math.max((value / peak) * 100, 3));

    const plot = 190;

    /*
     * The line's vertices, in the same 0–100 space as the bars: x is the
     * centre of each slot, y is inverted because SVG counts downwards.
     */
    const line = points
        .map((point, index) => {
            const x = ((index + 0.5) / points.length) * 100;
            const y = 100 - height(point.outstanding);

            return `${x},${y}`;
        })
        .join(' ');

    return (
        <div>
            <div className="position-relative" style={{ height: plot }}>
                {/* The bars. */}
                <div
                    className="d-flex align-items-end gap-1 position-absolute w-100"
                    style={{ height: plot, bottom: 0, borderBottom: '1px solid var(--bs-border-color)' }}
                >
                    {points.map((point, index) => (
                        <div
                            key={point.date}
                            className="flex-fill d-flex align-items-end justify-content-center gap-1 position-relative"
                            style={{ height: '100%' }}
                            onMouseEnter={() => setHovered(index)}
                            onMouseLeave={() => setHovered(null)}
                        >
                            {hovered === index && (
                                <div
                                    className="position-absolute bg-dark text-white rounded px-2 py-1"
                                    style={{
                                        bottom: '100%',
                                        left: '50%',
                                        transform: 'translateX(-50%)',
                                        whiteSpace: 'nowrap',
                                        zIndex: 10,
                                        fontSize: 12,
                                    }}
                                >
                                    <b>{tick(point)}</b>
                                    <span className="d-block">
                                        Invoiced: {format(point.invoiced)}
                                    </span>
                                    <span className="d-block">Paid: {format(point.paid)}</span>
                                    <span className="d-block">
                                        Outstanding: {format(point.outstanding)}
                                    </span>
                                </div>
                            )}

                            <div
                                className="bg-primary"
                                style={{
                                    width: '40%',
                                    height: `${height(point.invoiced)}%`,
                                    borderRadius: '3px 3px 0 0',
                                }}
                            />
                            <div
                                className="bg-success"
                                style={{
                                    width: '40%',
                                    height: `${height(point.paid)}%`,
                                    borderRadius: '3px 3px 0 0',
                                }}
                            />
                        </div>
                    ))}
                </div>

                {/* The outstanding line, over the bars and ignoring the mouse
                    so the bars keep their own hover. */}
                <svg
                    className="position-absolute w-100"
                    style={{ height: plot, bottom: 0, left: 0, pointerEvents: 'none' }}
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <polyline
                        points={line}
                        fill="none"
                        stroke="var(--bs-warning)"
                        strokeWidth={0.7}
                        vectorEffect="non-scaling-stroke"
                    />
                </svg>
            </div>

            <div className="d-flex gap-1 mt-1">
                {points.map((point) => (
                    <div
                        key={point.date}
                        className="flex-fill text-center dr-sub text-truncate"
                        style={{ fontSize: 11 }}
                    >
                        {tick(point)}
                    </div>
                ))}
            </div>
        </div>
    );
}
