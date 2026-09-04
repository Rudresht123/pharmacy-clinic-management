import { useId, useMemo, useState } from 'react';
import { useElementWidth } from '@/shared/hooks/useElementWidth';

export interface AreaPoint {
    /** Axis tick — short, e.g. "Sep". */
    label: string;
    value: number;
    /** Fuller wording for the tooltip, e.g. "2026-09". */
    title?: string;
}

const HEIGHT = 210;
const PAD_TOP = 18;
const PAD_BOTTOM = 26;
const PAD_RIGHT = 10;

/** Axis ceilings people actually read: 1, 2, 5 and their decades. */
function niceCeiling(value: number) {
    if (value <= 4) {
        return Math.max(value, 1);
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));

    for (const step of [1, 2, 2.5, 5, 10]) {
        const candidate = step * magnitude;

        if (candidate >= value) {
            return candidate;
        }
    }

    return magnitude * 10;
}

/**
 * A single series over time, as a line on a washed fill.
 *
 * Chosen over columns for a long span: twelve bars of which nine are zero
 * read as a broken chart, where a line along the floor reads — correctly —
 * as a quiet year. The fill is a wash rather than a block so the line stays
 * the mark and the area stays context.
 *
 * Only the last point is labelled; everything else is on hover, which is
 * what keeps direct labels working at all.
 *
 * A second series is optional and is a COMPARISON, not an equal: the same
 * measure over an earlier span. It draws as a thin muted line with no fill, so
 * the primary stays the mark and the comparison stays context — and a legend
 * appears, because two series can no longer be named by the title alone.
 */
export function AreaChart({
    points,
    compare,
    caption,
    valueLabel = 'joined',
    seriesLabel = 'This month',
    compareLabel = 'Last month',
}: {
    points: AreaPoint[];
    /** The same measure over an earlier span, drawn as context. */
    compare?: AreaPoint[];
    caption?: string;
    /** Reads as "3 joined" in the tooltip. */
    valueLabel?: string;
    seriesLabel?: string;
    compareLabel?: string;
}) {
    const gradientId = useId();
    const { ref, width } = useElementWidth<HTMLDivElement>();
    const [hovered, setHovered] = useState<number | null>(null);

    const geometry = useMemo(() => {
        if (width === 0 || points.length === 0) {
            return null;
        }

        /*
         * Both series share one ceiling. Scaling them separately would draw
         * a worse month at the same height as a better one — the comparison
         * would look identical to the thing it is meant to be compared with.
         */
        const ceiling = niceCeiling(
            Math.max(
                ...points.map((point) => point.value),
                ...(compare ?? []).map((point) => point.value),
            ),
        );
        const plotHeight = HEIGHT - PAD_TOP - PAD_BOTTOM;

        // Left padding is claimed by the axis labels, whose width depends on
        // how many digits the ceiling has.
        const padLeft = 12 + String(ceiling).length * 7;
        const plotWidth = Math.max(width - padLeft - PAD_RIGHT, 1);

        const x = (index: number) =>
            padLeft +
            (points.length === 1 ? plotWidth / 2 : (index / (points.length - 1)) * plotWidth);

        const y = (value: number) => PAD_TOP + plotHeight - (value / ceiling) * plotHeight;

        const step = points.length > 1 ? plotWidth / (points.length - 1) : plotWidth;
        const coords = points.map((point, index) => ({ x: x(index), y: y(point.value) }));
        const line = coords.map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x} ${c.y}`).join(' ');
        const baseline = PAD_TOP + plotHeight;

        return {
            ceiling,
            padLeft,
            baseline,
            step,
            coords,
            line,
            area: `${line} L${coords[coords.length - 1].x} ${baseline} L${coords[0].x} ${baseline} Z`,
            // Four gridlines including the floor: enough to read a value off,
            // few enough to stay recessive.
            ticks: [0, 0.5, 1].map((fraction) => ({
                value: ceiling * fraction,
                y: y(ceiling * fraction),
            })),

            compareLine: compare?.length
                ? compare
                      .map((point, index) => `${index === 0 ? 'M' : 'L'}${x(index)} ${y(point.value)}`)
                      .join(' ')
                : null,
        };
    }, [points, compare, width]);

    const last = points.length - 1;
    const active = hovered ?? last;

    return (
        <div className="area" ref={ref}>
            {(caption || compare) && (
                <div className="area-head">
                    {caption && <p className="area-caption">{caption}</p>}

                    {compare && (
                        <p className="area-legend">
                            <span className="area-key is-primary">{seriesLabel}</span>
                            <span className="area-key is-compare">{compareLabel}</span>
                        </p>
                    )}
                </div>
            )}

            <div className="area-plot" style={{ height: HEIGHT }}>
                {geometry && (
                    <>
                        <svg width={width} height={HEIGHT} role="img" aria-hidden="true">
                            <defs>
                                <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" className="area-stop-top" />
                                    <stop offset="100%" className="area-stop-bottom" />
                                </linearGradient>
                            </defs>

                            {geometry.ticks.map((tick) => (
                                <g key={tick.value}>
                                    <line
                                        className="area-grid"
                                        x1={geometry.padLeft}
                                        x2={width - PAD_RIGHT}
                                        y1={tick.y}
                                        y2={tick.y}
                                    />
                                    <text
                                        className="area-axis"
                                        x={geometry.padLeft - 8}
                                        y={tick.y + 4}
                                        textAnchor="end"
                                    >
                                        {tick.value}
                                    </text>
                                </g>
                            ))}

                            <path d={geometry.area} fill={`url(#${gradientId})`} />
                            <path className="area-line" d={geometry.line} />

                            {hovered !== null && (
                                <line
                                    className="area-crosshair"
                                    x1={geometry.coords[hovered].x}
                                    x2={geometry.coords[hovered].x}
                                    y1={PAD_TOP - 6}
                                    y2={geometry.baseline}
                                />
                            )}

                            {/* The ring keeps the dot legible where it sits on
                                the line, and is part of its hit area. */}
                            <circle
                                className="area-dot"
                                cx={geometry.coords[active].x}
                                cy={geometry.coords[active].y}
                                r={5}
                            />
                        </svg>

                        {/*
                         * One full-height band per point, so the whole column
                         * is the hit target rather than a 10px dot.
                         */}
                        <div className="area-bands">
                            {points.map((point, index) => (
                                <button
                                    type="button"
                                    key={point.label + index}
                                    className="area-band"
                                    // Centred on its own point rather than
                                    // evenly divided, so the band the pointer
                                    // is over is always the nearest mark.
                                    style={{
                                        left: Math.max(
                                            0,
                                            geometry.coords[index].x - geometry.step / 2,
                                        ),
                                        width: geometry.step,
                                    }}
                                    onMouseEnter={() => setHovered(index)}
                                    onMouseLeave={() => setHovered(null)}
                                    onFocus={() => setHovered(index)}
                                    onBlur={() => setHovered(null)}
                                >
                                    <span className="visually-hidden">
                                        {point.title ?? point.label}: {point.value}
                                    </span>
                                </button>
                            ))}
                        </div>

                        {hovered !== null && (
                            <span
                                className="area-tip"
                                role="status"
                                style={{
                                    left: geometry.coords[hovered].x,
                                    bottom: HEIGHT - geometry.coords[hovered].y + 14,
                                }}
                            >
                                {points[hovered].title ?? points[hovered].label}:{' '}
                                <b>{points[hovered].value}</b> {valueLabel}
                            </span>
                        )}
                    </>
                )}
            </div>

            <div
                className="area-ticks"
                style={{
                    paddingLeft: geometry?.padLeft ?? 0,
                    paddingRight: PAD_RIGHT,
                }}
            >
                {points.map((point, index) => (
                    <span
                        key={point.label + index}
                        className={`area-tick${index === active ? ' is-active' : ''}`}
                    >
                        {point.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
