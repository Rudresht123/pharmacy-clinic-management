import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Card } from '@/shared/components/ui/Card';

/** The colours every billing chart shares, from the theme's categorical palette. */
export const SERIES = ['var(--cat-1)', 'var(--cat-2)', 'var(--cat-3)', 'var(--cat-4)', 'var(--cat-5)'];

/**
 * One KPI: a tinted icon square, the label, the figure, and how it moved
 * against the previous period. The change is omitted — not shown as 0% —
 * when there is nothing to compare with.
 */
export function KpiCard({
    icon,
    tone,
    label,
    value,
    change,
    goodWhenDown = false,
    hint,
    onClick,
}: {
    icon: string;
    tone: 'emerald' | 'violet' | 'sky' | 'amber' | 'rose' | 'indigo';
    label: string;
    value: ReactNode;
    change?: number | null;
    goodWhenDown?: boolean;
    hint?: string;
    onClick?: () => void;
}) {
    const up = (change ?? 0) >= 0;
    const good = goodWhenDown ? !up : up;

    return (
        <button type="button" className={`sxa-kpi ${onClick ? 'is-link' : ''}`} onClick={onClick} disabled={!onClick} data-tone={tone}>
            <span className="sxa-kpi-icon" aria-hidden="true">
                <i className={icon} />
            </span>
            <span className="sxa-kpi-body">
                <span className="sxa-kpi-label">{label}</span>
                <span className="sxa-kpi-value">{value}</span>
                {change !== undefined && change !== null ? (
                    <span className={`sxa-kpi-change ${good ? 'is-good' : 'is-bad'}`}>
                        <i className={up ? 'ti ti-arrow-up' : 'ti ti-arrow-down'} aria-hidden="true" />
                        {Math.abs(change).toFixed(1)}%
                        <small> vs previous</small>
                    </span>
                ) : (
                    hint && <span className="sxa-kpi-hint">{hint}</span>
                )}
            </span>
        </button>
    );
}

export function KpiRow({ children, columns = 5 }: { children: ReactNode; columns?: number }) {
    return (
        <div className="sxa-kpis" style={{ ['--sxa-cols' as string]: columns }}>
            {children}
        </div>
    );
}

/** A chart in a card: title on the left, legend or actions on the right. */
export function ChartCard({
    title,
    legend,
    actions,
    children,
    className = '',
}: {
    title: string;
    legend?: Array<{ label: string; color: string }>;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Card className={`sxa-chart ${className}`}>
            <div className="sxa-chart-head">
                <h6>{title}</h6>
                <div className="sxa-chart-side">
                    {legend?.map((item) => (
                        <span key={item.label} className="sxa-legend">
                            <i style={{ background: item.color }} aria-hidden="true" />
                            {item.label}
                        </span>
                    ))}
                    {actions}
                </div>
            </div>
            {children}
        </Card>
    );
}

export interface GroupPoint {
    key: string;
    label: string;
    title: string;
    values: number[];
}

/**
 * The box a chart may fill: the card's spare width and height, measured.
 * A chart beside a taller donut grows to meet it instead of leaving a gap
 * under itself, and draws in real pixels so its labels never stretch.
 */
export function ChartFill({ min = 220, children }: { min?: number; children: (width: number, height: number) => ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    const [box, setBox] = useState({ width: 0, height: 0 });

    useEffect(() => {
        const node = ref.current;

        if (!node) return;

        const read = (width: number, height: number) => setBox({ width: Math.floor(width), height: Math.floor(height) });
        const rect = node.getBoundingClientRect();

        read(rect.width, rect.height);

        const observer = new ResizeObserver(([entry]) => read(entry.contentRect.width, entry.contentRect.height));

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={ref} className="sxa-fill" style={{ minHeight: min }}>
            {box.width > 0 && <div className="sxa-fill-inner">{children(box.width, Math.max(min, box.height))}</div>}
        </div>
    );
}

/**
 * Columns side by side per period — one per series — with a value axis and
 * a hover card listing every series for the period. `height` is the least
 * it takes; it grows to the card's height when a neighbour is taller.
 */
export function GroupedBars({
    points,
    series,
    format,
    axis,
    height = 220,
    empty = 'Nothing yet for this period.',
}: {
    points: GroupPoint[];
    series: Array<{ label: string; color: string }>;
    format: (value: number) => string;
    axis: (value: number) => string;
    height?: number;
    empty?: string;
}) {
    const [hovered, setHovered] = useState<number | null>(null);
    const max = Math.max(0, ...points.flatMap((p) => p.values));
    const { top, ticks } = useMemo(() => scale(max), [max]);

    if (max === 0) {
        return (
            <div className="donut-empty" style={{ minHeight: height }}>
                <i className="ti ti-chart-bar" aria-hidden="true" />
                <p>{empty}</p>
            </div>
        );
    }

    return (
        <ChartFill min={height}>
            {(W, H) => {
                const left = 52;
                const right = 8;
                const head = 10;
                const bottom = 26;
                const plotW = W - left - right;
                const plotH = H - bottom - head;
                const slot = plotW / points.length;
                const gap = series.length > 1 ? 4 : 0;
                const barW = Math.max(4, Math.min(20, (slot * 0.6 - gap * (series.length - 1)) / series.length));
                const y = (v: number) => head + plotH - (v / top) * plotH;

                return (
                    <div className="sxa-bars" onMouseLeave={() => setHovered(null)}>
                        <svg width={W} height={H} role="img" aria-label={`${series.map((s) => s.label).join(', ')} by month`}>
                            {ticks.map((t) => (
                                <g key={t}>
                                    <line x1={left} x2={W - right} y1={y(t)} y2={y(t)} className="sxa-grid" />
                                    <text x={left - 8} y={y(t) + 4} textAnchor="end" className="sxa-axis">
                                        {axis(t)}
                                    </text>
                                </g>
                            ))}

                            {points.map((point, i) => {
                                const groupW = barW * series.length + gap * (series.length - 1);
                                const x0 = left + slot * i + (slot - groupW) / 2;

                                return (
                                    <g key={point.key} onMouseEnter={() => setHovered(i)}>
                                        <rect x={left + slot * i} y={head} width={slot} height={plotH} className={`sxa-hit ${hovered === i ? 'is-on' : ''}`} />
                                        {point.values.map((v, s) => (
                                            <rect
                                                key={s}
                                                x={x0 + s * (barW + gap)}
                                                y={y(v)}
                                                width={barW}
                                                height={Math.max(0, head + plotH - y(v))}
                                                rx={Math.min(4, barW / 2)}
                                                style={{ fill: series[s]?.color }}
                                            />
                                        ))}
                                        <text x={left + slot * i + slot / 2} y={H - 8} textAnchor="middle" className="sxa-axis">
                                            {point.label}
                                        </text>
                                    </g>
                                );
                            })}
                        </svg>

                        {hovered !== null && points[hovered] && (
                            <div className="sxa-tip" style={{ left: Math.min(Math.max(left + slot * (hovered + 0.5), 90), W - 90) }}>
                                <b>{points[hovered].title}</b>
                                {series.map((s, i) => (
                                    <span key={s.label}>
                                        <i style={{ background: s.color }} aria-hidden="true" /> {s.label}: {format(points[hovered].values[i] ?? 0)}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                );
            }}
        </ChartFill>
    );
}

/** A tidy axis: five steps up to a round ceiling. */
function scale(max: number): { top: number; ticks: number[] } {
    const raw = max / 4;
    const power = Math.pow(10, Math.floor(Math.log10(raw || 1)));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * power).find((s) => s >= raw) ?? raw;
    const top = step * 4;

    return { top, ticks: [0, step, step * 2, step * 3, top] };
}

/** ₹12.8L / ₹4.2K / ₹950 — for axes and donut centres. */
export function compactPaise(paise: number): string {
    const rupees = paise / 100;

    if (rupees >= 1_00_00_000) return `₹${(rupees / 1_00_00_000).toFixed(1)}Cr`;
    if (rupees >= 1_00_000) return `₹${(rupees / 1_00_000).toFixed(1)}L`;
    if (rupees >= 1_000) return `₹${(rupees / 1_000).toFixed(1)}K`;

    return `₹${Math.round(rupees)}`;
}

/** A date range as one pill: two native date inputs. */
export function RangePicker({ from, to, onChange }: { from: string; to: string; onChange: (from: string, to: string) => void }) {
    return (
        <div className="sxa-range">
            <i className="ti ti-calendar" aria-hidden="true" />
            <input type="date" value={from} aria-label="From" onChange={(e) => onChange(e.target.value, to)} />
            <span>–</span>
            <input type="date" value={to} aria-label="To" onChange={(e) => onChange(from, e.target.value)} />
        </div>
    );
}

export function monthBounds(): { from: string; to: string } {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();

    return {
        from: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`,
        to: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(last)}`,
    };
}
