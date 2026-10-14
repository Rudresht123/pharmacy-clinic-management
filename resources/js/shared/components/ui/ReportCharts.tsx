import { useEffect, useId, useRef, useState, type MouseEvent } from 'react';

/* ------------------------------------------------------------------------ */
/*  Numbers                                                                 */
/* ------------------------------------------------------------------------ */

/** "₹ 8,74,320" — whole rupees, Indian grouping, the way the reference prints them. */
export function inr(value: number): string {
    const rounded = Math.round(value || 0);

    // A loss reads "−₹ 94,913", sign before the symbol.
    return `${rounded < 0 ? '−' : ''}₹ ${Math.abs(rounded).toLocaleString('en-IN')}`;
}

/** "12,842". */
export function count(value: number): string {
    return Math.round(value || 0).toLocaleString('en-IN');
}

/**
 * An axis label in the units an Indian ledger is read in — 50K, 1.5L, 2Cr.
 * Short enough to sit beside a gridline without crowding it.
 */
export function compactInr(value: number): string {
    const trim = (n: number) => String(Number(n.toFixed(1)));

    if (value >= 1e7) return `${trim(value / 1e7)}Cr`;
    if (value >= 1e5) return `${trim(value / 1e5)}L`;
    if (value >= 1e3) return `${trim(value / 1e3)}K`;

    return trim(value);
}

/**
 * An axis that ends on a round number, split into `ticks` even steps.
 *
 * The top of the chart is the next tidy value above the largest one — 2.5L,
 * not 2.03L — so every gridline reads as a number somebody would say.
 */
function niceScale(max: number, ticks = 5): { top: number; step: number } {
    if (max <= 0) {
        return { top: ticks, step: 1 };
    }

    const raw = max / ticks;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const residual = raw / magnitude;
    const nice = residual <= 1 ? 1 : residual <= 2 ? 2 : residual <= 2.5 ? 2.5 : residual <= 5 ? 5 : 10;
    const step = nice * magnitude;

    return { top: step * Math.ceil(max / step), step };
}

/* ------------------------------------------------------------------------ */
/*  Size                                                                    */
/* ------------------------------------------------------------------------ */

/**
 * How wide a box is, kept current.
 *
 * The charts draw in real pixels rather than stretching a viewBox, so axis
 * labels stay the size they were set at instead of widening with the card.
 */
function useWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const node = ref.current;

        if (!node) return;

        setWidth(Math.floor(node.getBoundingClientRect().width));

        const observer = new ResizeObserver(([entry]) => setWidth(Math.floor(entry.contentRect.width)));

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return [ref, width] as const;
}

/** A useId that is also a valid SVG `url(#…)` reference. */
function useSvgId(prefix: string): string {
    return `${prefix}-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
}

/* ------------------------------------------------------------------------ */
/*  Stacked columns                                                         */
/* ------------------------------------------------------------------------ */

export interface StackSeries {
    label: string;
    /** A CSS colour — a custom property, so dark mode restyles it. */
    color: string;
}

export interface StackPoint {
    key: string;
    label: string;
    title: string;
    /** One value per series, bottom of the stack first. */
    values: number[];
}

/**
 * Columns stacked from their parts, optionally with the total drawn as a
 * line over their tops.
 *
 * The line repeats what the column heights already say, and earns its place
 * by giving the eye a path through the period — the trend reads off the line,
 * the make-up off the column.
 *
 * Hovering a column pins a guide and a card with every part of it, so the
 * figures are always one point away without a label on every column.
 */
export function StackedColumns({
    points,
    series,
    height = 240,
    format,
    axis,
    totalLine,
    overlay,
    maxBar = 28,
}: {
    points: StackPoint[];
    series: StackSeries[];
    height?: number;
    /** How a value reads in the hover card. */
    format: (value: number) => string;
    /** How a value reads on the axis. */
    axis: (value: number) => string;
    /** Draw the total as a line, under this name in the hover card. */
    totalLine?: { label: string; color: string };
    /**
     * A second measure drawn as a line over the columns — on the same axis,
     * so it must be in the same units. Never a second scale.
     */
    overlay?: { label: string; color: string; values: number[] };
    maxBar?: number;
}) {
    const [box, width] = useWidth<HTMLDivElement>();
    const [hovered, setHovered] = useState<number | null>(null);
    const clipBase = useSvgId('od-col');

    const totals = points.map((point) => point.values.reduce((sum, value) => sum + value, 0));
    const { top: ceiling, step } = niceScale(Math.max(...totals, ...(overlay?.values ?? []), 0));

    const pad = { left: 46, right: 10, top: 12, bottom: 26 };
    const plotWidth = Math.max(width - pad.left - pad.right, 10);
    const plotHeight = height - pad.top - pad.bottom;
    const slot = plotWidth / Math.max(points.length, 1);
    const bar = Math.max(Math.min(slot * (points.length > 20 ? 0.62 : 0.46), maxBar), 3);

    const y = (value: number) => pad.top + plotHeight - (value / ceiling) * plotHeight;
    const centre = (index: number) => pad.left + slot * (index + 0.5);

    const ticks = Array.from({ length: Math.round(ceiling / step) + 1 }, (_, index) => index * step);

    /*
     * As many axis labels as fit at their own width, evenly spaced, always
     * including the last — and dropping whichever regular one would crowd
     * it, so "29 Oct" never runs into "31 Oct".
     */
    const labelWidth = Math.max(...points.map((point) => point.label.length), 1) * 6.4 + 12;
    const every = Math.max(Math.ceil(points.length / Math.max(Math.floor(plotWidth / labelWidth), 1)), 1);
    const last = points.length - 1;
    const showLabel = (index: number) => index === last || (index % every === 0 && last - index >= every);

    const line = totals.map((total, index) => `${index === 0 ? 'M' : 'L'}${centre(index)} ${y(total)}`).join(' ');

    const active = hovered === null ? null : points[hovered];
    const flip = hovered !== null && centre(hovered) > width * 0.62;

    return (
        <div className="od-chart" ref={box} style={{ height }} onMouseLeave={() => setHovered(null)}>
            {width > 0 && (
                <svg width={width} height={height} role="img" aria-label={`${series.map((s) => s.label).join(', ')} by period`}>
                    <defs>
                        {points.map((point, index) => {
                            const x = centre(index) - bar / 2;
                            const topY = y(totals[index]);
                            const bottom = pad.top + plotHeight;
                            const radius = Math.min(4, bar / 2, Math.max(bottom - topY, 0));

                            return (
                                <clipPath id={`${clipBase}-${index}`} key={point.key}>
                                    <path
                                        d={`M${x} ${bottom} V${topY + radius} Q${x} ${topY} ${x + radius} ${topY} H${
                                            x + bar - radius
                                        } Q${x + bar} ${topY} ${x + bar} ${topY + radius} V${bottom} Z`}
                                    />
                                </clipPath>
                            );
                        })}
                    </defs>

                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line
                                className="od-grid"
                                x1={pad.left}
                                x2={width - pad.right}
                                y1={y(tick)}
                                y2={y(tick)}
                            />
                            <text className="od-axis" x={pad.left - 10} y={y(tick) + 4} textAnchor="end">
                                {axis(tick)}
                            </text>
                        </g>
                    ))}

                    {hovered !== null && (
                        <line
                            className="od-guide"
                            x1={centre(hovered)}
                            x2={centre(hovered)}
                            y1={pad.top}
                            y2={pad.top + plotHeight}
                        />
                    )}

                    {points.map((point, index) => {
                        let stacked = 0;

                        return (
                            <g
                                key={point.key}
                                clipPath={`url(#${clipBase}-${index})`}
                                className={hovered !== null && hovered !== index ? 'is-dimmed' : undefined}
                            >
                                {point.values.map((value, part) => {
                                    const from = stacked;

                                    stacked += value;

                                    return value > 0 ? (
                                        <rect
                                            key={series[part].label}
                                            x={centre(index) - bar / 2}
                                            y={y(stacked)}
                                            width={bar}
                                            height={Math.max(y(from) - y(stacked), 0)}
                                            style={{ fill: series[part].color }}
                                        />
                                    ) : null;
                                })}
                            </g>
                        );
                    })}

                    {totalLine && totals.some((total) => total > 0) && (
                        <g className="od-total">
                            <path d={line} style={{ stroke: totalLine.color }} />
                            {totals.map((total, index) => (
                                <circle
                                    key={points[index].key}
                                    cx={centre(index)}
                                    cy={y(total)}
                                    r={hovered === index ? 4.5 : 3.2}
                                    style={{ stroke: totalLine.color }}
                                />
                            ))}
                        </g>
                    )}

                    {overlay && (
                        <g className="od-total">
                            <path
                                d={overlay.values
                                    .map((value, index) => `${index === 0 ? 'M' : 'L'}${centre(index)} ${y(value)}`)
                                    .join(' ')}
                                style={{ stroke: overlay.color }}
                            />
                            {overlay.values.map((value, index) => (
                                <circle
                                    key={points[index]?.key ?? index}
                                    cx={centre(index)}
                                    cy={y(value)}
                                    r={hovered === index ? 4.5 : 3.2}
                                    style={{ stroke: overlay.color }}
                                />
                            ))}
                        </g>
                    )}

                    {points.map((point, index) =>
                        showLabel(index) ? (
                            <text
                                key={point.key}
                                className="od-axis"
                                x={centre(index)}
                                y={height - 7}
                                textAnchor="middle"
                            >
                                {point.label}
                            </text>
                        ) : null,
                    )}

                    {/* Hit areas: a whole slot each, so a thin column is still easy to point at. */}
                    {points.map((point, index) => (
                        <rect
                            key={point.key}
                            className="od-hit"
                            x={pad.left + slot * index}
                            y={pad.top}
                            width={slot}
                            height={plotHeight}
                            onMouseEnter={() => setHovered(index)}
                        />
                    ))}
                </svg>
            )}

            {active && hovered !== null && (
                <div
                    className="od-tip"
                    role="status"
                    style={{
                        left: flip ? centre(hovered) - 14 : centre(hovered) + 14,
                        top: pad.top,
                        transform: flip ? 'translateX(-100%)' : undefined,
                    }}
                >
                    <b>{active.title}</b>

                    <dl>
                        {totalLine && (
                            <div>
                                <dt>
                                    <span style={{ background: totalLine.color }} />
                                    {totalLine.label}
                                </dt>
                                <dd>{format(totals[hovered])}</dd>
                            </div>
                        )}

                        {overlay && (
                            <div>
                                <dt>
                                    <span style={{ background: overlay.color }} />
                                    {overlay.label}
                                </dt>
                                <dd>{format(overlay.values[hovered] ?? 0)}</dd>
                            </div>
                        )}

                        {/* Top of the stack first, the order the eye meets them. */}
                        {[...series.keys()].reverse().map((part) => (
                            <div key={series[part].label}>
                                <dt>
                                    <span style={{ background: series[part].color }} />
                                    {series[part].label}
                                </dt>
                                <dd>{format(active.values[part])}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------------ */
/*  Lines                                                                   */
/* ------------------------------------------------------------------------ */

export interface LineSeries {
    label: string;
    color: string;
    values: number[];
    /** A comparison drawn thin and dashed, without dots — context, not the subject. */
    dashed?: boolean;
    /** A soft fill under the line, fading to nothing. */
    area?: boolean;
}

/**
 * Several measures over the same months, one line each, on one axis.
 *
 * The axis runs below zero when a value does — a month at a loss is drawn
 * under the zero line, which is drawn solid so the reader can see which
 * side of it a point is on. Straight segments rather than curves: a curve
 * between two months invents values that were never there.
 */
export function LineChart({
    points,
    series,
    height = 220,
    format,
    axis,
}: {
    points: { key: string; label: string; title: string }[];
    series: LineSeries[];
    height?: number;
    format: (value: number) => string;
    axis: (value: number) => string;
}) {
    const [box, width] = useWidth<HTMLDivElement>();
    const [hovered, setHovered] = useState<number | null>(null);
    const fade = useSvgId('od-area');

    const values = series.flatMap((line) => line.values);
    const low = Math.min(...values, 0);
    const high = Math.max(...values, 0);

    // A tidy step over the whole span, then the floor and ceiling on it.
    const { step } = niceScale(high - low || 1, 4);
    const floor = Math.floor(low / step) * step;
    const ceiling = Math.max(Math.ceil(high / step) * step, floor + step);

    const pad = { left: 52, right: 14, top: 12, bottom: 26 };
    const plotWidth = Math.max(width - pad.left - pad.right, 10);
    const plotHeight = height - pad.top - pad.bottom;

    const x = (index: number) =>
        points.length < 2 ? pad.left + plotWidth / 2 : pad.left + (index / (points.length - 1)) * plotWidth;
    const y = (value: number) => pad.top + plotHeight - ((value - floor) / (ceiling - floor)) * plotHeight;

    const ticks = Array.from({ length: Math.round((ceiling - floor) / step) + 1 }, (_, index) => floor + index * step);

    const labelWidth = Math.max(...points.map((point) => point.label.length), 1) * 6.4 + 12;
    const every = Math.max(Math.ceil(points.length / Math.max(Math.floor(plotWidth / labelWidth), 1)), 1);
    const last = points.length - 1;
    const showLabel = (index: number) => index === last || (index % every === 0 && last - index >= every);

    const active = hovered === null ? null : points[hovered];
    const flip = hovered !== null && x(hovered) > width * 0.6;

    // The nearest month to the pointer, so the whole plot is the hit area.
    function track(event: MouseEvent<SVGRectElement>) {
        const rect = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientX - rect.left) / rect.width;

        setHovered(Math.min(Math.max(Math.round(ratio * last), 0), last));
    }

    return (
        <div className="od-chart" ref={box} style={{ height }} onMouseLeave={() => setHovered(null)}>
            {width > 0 && (
                <svg width={width} height={height} role="img" aria-label={`${series.map((s) => s.label).join(', ')} by month`}>
                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line
                                className={tick === 0 && floor < 0 ? 'od-zero' : 'od-grid'}
                                x1={pad.left}
                                x2={width - pad.right}
                                y1={y(tick)}
                                y2={y(tick)}
                            />
                            <text className="od-axis" x={pad.left - 10} y={y(tick) + 4} textAnchor="end">
                                {tick < 0 ? `−${axis(-tick)}` : axis(tick)}
                            </text>
                        </g>
                    ))}

                    {hovered !== null && (
                        <line className="od-guide" x1={x(hovered)} x2={x(hovered)} y1={pad.top} y2={pad.top + plotHeight} />
                    )}

                    {series.map((line, which) => {
                        const path = line.values
                            .map((value, index) => `${index === 0 ? 'M' : 'L'}${x(index)} ${y(value)}`)
                            .join(' ');

                        // A dot per point while there is room for one; past
                        // that the dots are a smear and only the hovered one
                        // is drawn.
                        const dots = !line.dashed && points.length <= 45;

                        return (
                            <g key={line.label} className={`od-line${line.dashed ? ' is-dashed' : ''}`}>
                                {line.area && (
                                    <>
                                        <defs>
                                            <linearGradient id={`${fade}-${which}`} x1="0" x2="0" y1="0" y2="1">
                                                <stop offset="0%" style={{ stopColor: line.color, stopOpacity: 0.22 }} />
                                                <stop offset="100%" style={{ stopColor: line.color, stopOpacity: 0 }} />
                                            </linearGradient>
                                        </defs>
                                        <path
                                            className="od-area"
                                            d={`${path} L${x(line.values.length - 1)} ${y(Math.max(floor, 0))} L${x(0)} ${y(Math.max(floor, 0))} Z`}
                                            style={{ fill: `url(#${fade}-${which})` }}
                                        />
                                    </>
                                )}

                                <path d={path} style={{ stroke: line.color }} />

                                {line.values.map((value, index) =>
                                    dots || (hovered === index && !line.dashed) ? (
                                        <circle
                                            key={points[index]?.key ?? index}
                                            cx={x(index)}
                                            cy={y(value)}
                                            r={hovered === index ? 4.5 : 3}
                                            style={{ stroke: line.color }}
                                        />
                                    ) : null,
                                )}
                            </g>
                        );
                    })}

                    {points.map((point, index) =>
                        showLabel(index) ? (
                            <text key={point.key} className="od-axis" x={x(index)} y={height - 7} textAnchor="middle">
                                {point.label}
                            </text>
                        ) : null,
                    )}

                    <rect
                        className="od-hit"
                        x={pad.left}
                        y={pad.top}
                        width={plotWidth}
                        height={plotHeight}
                        onMouseMove={track}
                    />
                </svg>
            )}

            {active && hovered !== null && (
                <div
                    className="od-tip"
                    role="status"
                    style={{
                        left: flip ? x(hovered) - 14 : x(hovered) + 14,
                        top: pad.top,
                        transform: flip ? 'translateX(-100%)' : undefined,
                    }}
                >
                    <b>{active.title}</b>

                    <dl>
                        {series.map((line) => (
                            <div key={line.label}>
                                <dt>
                                    <span style={{ background: line.color }} />
                                    {line.label}
                                </dt>
                                <dd>{format(line.values[hovered] ?? 0)}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------------ */
/*  Trend line                                                              */
/* ------------------------------------------------------------------------ */

/**
 * A soft curve with a fill fading to nothing — the shape of a figure over
 * the period, not its size. No axes and no hover: the number beside it
 * carries the size.
 *
 * Smoothed with a gentle Catmull-Rom pass, clamped so a curve never swings
 * below the floor of its own box.
 */
export function TrendCurve({ points, color, height = 38 }: { points: number[]; color: string; height?: number }) {
    const gradient = useSvgId('od-fade');

    if (points.length < 2) {
        return <span className="od-curve is-flat" style={{ height }} aria-hidden="true" />;
    }

    const low = Math.min(...points, 0);
    const high = Math.max(...points);
    const span = high - low || 1;

    const coords = points.map((value, index) => ({
        x: (index / (points.length - 1)) * 100,
        y: high === low ? 60 : 92 - ((value - low) / span) * 84,
    }));

    const clamp = (value: number) => Math.min(Math.max(value, 4), 96);
    let curve = `M${coords[0].x} ${coords[0].y}`;

    for (let i = 0; i < coords.length - 1; i++) {
        const p0 = coords[i - 1] ?? coords[i];
        const p1 = coords[i];
        const p2 = coords[i + 1];
        const p3 = coords[i + 2] ?? p2;

        const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: clamp(p1.y + (p2.y - p0.y) / 6) };
        const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: clamp(p2.y - (p3.y - p1.y) / 6) };

        curve += ` C${c1.x} ${c1.y} ${c2.x} ${c2.y} ${p2.x} ${p2.y}`;
    }

    return (
        <svg className="od-curve" style={{ height }} viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <defs>
                <linearGradient id={gradient} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" style={{ stopColor: color, stopOpacity: 0.28 }} />
                    <stop offset="100%" style={{ stopColor: color, stopOpacity: 0 }} />
                </linearGradient>
            </defs>

            <path d={`${curve} L100 100 L0 100 Z`} style={{ fill: `url(#${gradient})` }} />
            <path d={curve} className="od-curve-line" style={{ stroke: color }} vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

/* ------------------------------------------------------------------------ */
/*  Ring                                                                    */
/* ------------------------------------------------------------------------ */

const RADIUS = 36;
const THICKNESS = 17;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

export interface RingSlice {
    key: string;
    label: string;
    value: number;
    color: string;
}

/**
 * How a total divides, with the total in the middle.
 *
 * Colours belong to the category, not to its rank, so "Consultation" is the
 * same blue this month and last — a palette that reshuffles whenever the
 * order changes teaches nothing. Pointing at a slice puts its own figure in
 * the hole.
 */
export function Ring({
    slices,
    caption,
    format,
    active,
    onActive,
}: {
    slices: RingSlice[];
    caption: string;
    format: (value: number) => string;
    active: string | null;
    onActive: (key: string | null) => void;
}) {
    const total = slices.reduce((sum, slice) => sum + slice.value, 0);
    const gap = slices.length > 1 ? 1.2 : 0;

    let offset = 0;

    const arcs = slices.map((slice) => {
        const length = (slice.value / (total || 1)) * CIRCUMFERENCE;
        const drawn = Math.max(length - gap, 0.6);
        const arc = { ...slice, dash: `${drawn} ${CIRCUMFERENCE - drawn}`, offset: -offset };

        offset += length;

        return arc;
    });

    const focus = arcs.find((arc) => arc.key === active);

    return (
        <div className="od-ring">
            <svg viewBox="0 0 100 100" aria-hidden="true">
                <g transform="rotate(-90 50 50)">
                    {arcs.map((arc) => (
                        <circle
                            key={arc.key}
                            cx="50"
                            cy="50"
                            r={RADIUS}
                            fill="none"
                            strokeWidth={active === arc.key ? THICKNESS + 3 : THICKNESS}
                            strokeDasharray={arc.dash}
                            strokeDashoffset={arc.offset}
                            className={active && active !== arc.key ? 'is-dimmed' : undefined}
                            style={{ stroke: arc.color }}
                            onMouseEnter={() => onActive(arc.key)}
                            onMouseLeave={() => onActive(null)}
                        />
                    ))}
                </g>
            </svg>

            <div className="od-ring-centre" role="status">
                <b>{format(focus ? focus.value : total)}</b>
                <span>{focus ? focus.label : caption}</span>
            </div>
        </div>
    );
}
