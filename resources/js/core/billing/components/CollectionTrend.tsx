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

/** How a bucket's tick reads — a week shows its starting day, a month its name. */
function tick(point: CollectionTrendPoint): string {
    const date = new Date(point.date);

    if (point.unit === 'month') {
        return date.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' });
    }

    return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

/**
 * One stacked bar per bucket — where that day's collection came from.
 *
 * A stack rather than five side-by-side bars: five thin slivers per day
 * cannot be compared by eye, where a shared baseline of segments can.
 */
export function CollectionTrend({
    points,
    format = String,
}: {
    points: CollectionTrendPoint[];
    format?: (value: number) => string;
}) {
    const [hovered, setHovered] = useState<number | null>(null);

    if (points.length === 0) {
        return <p className="bar-list-empty">Nothing to plot in this window.</p>;
    }

    const totals = points.map((point) =>
        SEGMENTS.reduce((sum, segment) => sum + (point.by_category[segment.key] || 0), 0),
    );
    const peak = Math.max(...totals, 1);

    const plot = 190;

    return (
        <div>
            <div className="ct-legend">
                {SEGMENTS.map((segment) => (
                    <span key={segment.key} className="ct-legend-item">
                        <i style={{ background: `var(--cat-${segment.hue})` }} aria-hidden="true" />
                        {segment.label}
                    </span>
                ))}
            </div>

            <div className="position-relative" style={{ height: plot }}>
                <div
                    className="d-flex align-items-end gap-2 position-absolute w-100"
                    style={{ height: plot, bottom: 0, borderBottom: '1px solid var(--bs-border-color)' }}
                >
                    {points.map((point, index) => {
                        const total = totals[index];
                        const barHeight = total === 0 ? 1.5 : Math.max((total / peak) * 100, 3);

                        return (
                            <div
                                key={point.date}
                                className="flex-fill d-flex align-items-end justify-content-center position-relative"
                                style={{ height: '100%' }}
                                onMouseEnter={() => setHovered(index)}
                                onMouseLeave={() => setHovered(null)}
                            >
                                {hovered === index && (
                                    <div className="ct-tip">
                                        <b>{tick(point)}</b>
                                        {SEGMENTS.map((segment) => {
                                            const value = point.by_category[segment.key] || 0;

                                            if (value <= 0) return null;

                                            return (
                                                <span key={segment.key} className="d-block">
                                                    {segment.label}: {format(value)}
                                                </span>
                                            );
                                        })}
                                    </div>
                                )}

                                <div
                                    className="d-flex flex-column-reverse"
                                    style={{ width: '55%', height: `${barHeight}%`, borderRadius: '4px 4px 0 0', overflow: 'hidden' }}
                                >
                                    {SEGMENTS.map((segment) => {
                                        const value = point.by_category[segment.key] || 0;

                                        if (value <= 0) return null;

                                        return (
                                            <div
                                                key={segment.key}
                                                style={{
                                                    height: `${(value / total) * 100}%`,
                                                    background: `var(--cat-${segment.hue})`,
                                                }}
                                            />
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="d-flex gap-1 mt-1">
                {points.map((point) => (
                    <div key={point.date} className="flex-fill text-center dr-sub text-truncate" style={{ fontSize: 11 }}>
                        {tick(point)}
                    </div>
                ))}
            </div>
        </div>
    );
}
