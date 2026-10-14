import { useMemo, useState } from 'react';
import { formatDate } from '@/shared/utils/format';
import {
    compactInr,
    count,
    inr,
    LineChart,
    Ring,
    StackedColumns,
} from '@/shared/components/ui/ReportCharts';
import { PAYMENT_METHOD_LABELS } from '../../types';
import type {
    CellFormat,
    DashboardChart,
    DashboardKpi,
    DashboardTable,
    ReportDashboard,
    ReportKey,
} from '../../reports';

/**
 * One report's dashboard: four figures, two charts, two short lists.
 *
 * Drawn entirely from what the server describes (App\Services\Pharmacy\
 * Reports\ReportDashboards), so all six reports share this one renderer and
 * a report changes shape without a change here.
 */
export function ReportDashboardView({
    dashboard,
    compare,
    onViewAll,
}: {
    dashboard: ReportDashboard;
    /** "vs last month" or "vs previous period". */
    compare: string;
    onViewAll: (report: ReportKey) => void;
}) {
    return (
        <div className="phr-dash">
            <div className="phr-kpis">
                {dashboard.kpis.map((kpi) => (
                    <Kpi key={kpi.key} kpi={kpi} compare={compare} />
                ))}
            </div>

            <div className="phr-charts">
                {dashboard.charts.map((chart) => (
                    <ChartCard key={chart.title} chart={chart} />
                ))}
            </div>

            <div className="phr-lists">
                {dashboard.tables.map((table) => (
                    <TableCard key={table.title} table={table} onViewAll={onViewAll} />
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------------ */
/*  Colour                                                                  */
/* ------------------------------------------------------------------------ */

const TONE: Record<string, string> = {
    blue: 'var(--od-blue)',
    green: 'var(--od-green)',
    violet: 'var(--od-violet)',
    amber: 'var(--od-amber)',
    rose: 'var(--od-rose)',
    teal: 'var(--od-teal)',
};

/** A ring's slices, in order — muted ("Others") always grey. */
const SLICES = ['blue', 'green', 'violet', 'amber', 'rose', 'teal'];

/** Each figure's icon and colour, by what it counts. */
const KPI_LOOK: Record<string, { icon: string; tone: string }> = {
    net_sales: { icon: 'ti ti-chart-bar', tone: 'blue' },
    gross_profit: { icon: 'ti ti-coins', tone: 'teal' },
    purchases: { icon: 'ti ti-shopping-cart', tone: 'violet' },
    low_stock: { icon: 'ti ti-alert-triangle', tone: 'amber' },

    total_purchases: { icon: 'ti ti-shopping-cart', tone: 'blue' },
    grns: { icon: 'ti ti-file-invoice', tone: 'teal' },
    suppliers: { icon: 'ti ti-users', tone: 'violet' },
    average_purchase: { icon: 'ti ti-receipt', tone: 'amber' },

    stock_cost: { icon: 'ti ti-packages', tone: 'teal' },
    stock_mrp: { icon: 'ti ti-tag', tone: 'blue' },
    items_in_stock: { icon: 'ti ti-box', tone: 'amber' },
    low_stock_items: { icon: 'ti ti-alert-triangle', tone: 'rose' },

    total_items: { icon: 'ti ti-box', tone: 'teal' },
    near_expiry: { icon: 'ti ti-alert-triangle', tone: 'amber' },
    expired_items: { icon: 'ti ti-clock-x', tone: 'rose' },
    expiry_value: { icon: 'ti ti-currency-rupee', tone: 'amber' },

    revenue: { icon: 'ti ti-cash', tone: 'teal' },
    cogs: { icon: 'ti ti-receipt-2', tone: 'blue' },
    profit_per_bill: { icon: 'ti ti-trending-up', tone: 'amber' },

    taxable: { icon: 'ti ti-receipt-tax', tone: 'teal' },
    cgst: { icon: 'ti ti-building-bank', tone: 'blue' },
    sgst: { icon: 'ti ti-building-bank', tone: 'violet' },
    total_gst: { icon: 'ti ti-percentage', tone: 'amber' },
};

/** `upi` is how a tender is stored; "UPI" is how it is read. */
function sliceLabel(label: string): string {
    return PAYMENT_METHOD_LABELS[label] ?? label;
}

/* ------------------------------------------------------------------------ */
/*  Figures                                                                 */
/* ------------------------------------------------------------------------ */

function Kpi({ kpi, compare }: { kpi: DashboardKpi; compare: string }) {
    const look = KPI_LOOK[kpi.key] ?? { icon: 'ti ti-chart-bar', tone: 'blue' };
    const change = kpi.change ?? null;

    // A rise is good news for takings and bad news for a cost.
    const good = change === null || change === 0 ? null : change > 0 !== Boolean(kpi.cost);

    return (
        <article className="phr-kpi">
            <span className={`phr-kpi-icon od-bg-${look.tone}`}>
                <i className={look.icon} aria-hidden="true" />
            </span>

            <div className="phr-kpi-body">
                <span className="phr-kpi-label">
                    {kpi.label}
                    {kpi.info && <i className="ti ti-info-circle" title={kpi.info} aria-label={kpi.info} />}
                </span>

                <b className="phr-kpi-value">
                    {kpi.money ? inr(kpi.value) : count(kpi.value)}
                    {kpi.suffix && <small>{kpi.suffix}</small>}
                </b>

                {change !== null ? (
                    <span className={`phr-kpi-change${good === null ? '' : good ? ' is-good' : ' is-bad'}`}>
                        <i
                            className={change > 0 ? 'ti ti-arrow-up-right' : change < 0 ? 'ti ti-arrow-down-right' : 'ti ti-minus'}
                            aria-hidden="true"
                        />
                        {change > 0 ? '+' : ''}
                        {change}% <span>{compare}</span>
                    </span>
                ) : (
                    kpi.hint && <span className={`phr-kpi-hint${kpi.hint_tone ? ` is-${kpi.hint_tone}` : ''}`}>{kpi.hint}</span>
                )}
            </div>

            {kpi.spark && kpi.spark.length > 1 && <Spark values={kpi.spark} color={TONE[look.tone]} />}
        </article>
    );
}

/** A short run of bars — the figure's shape across the window, nothing more. */
function Spark({ values, color }: { values: number[]; color: string }) {
    const peak = Math.max(...values.map((value) => Math.abs(value)), 1);

    return (
        <svg className="phr-spark" viewBox={`0 0 ${values.length * 9} 40`} preserveAspectRatio="none" aria-hidden="true">
            {values.map((value, index) => {
                const height = Math.max((Math.abs(value) / peak) * 38, 2);

                return (
                    <rect
                        key={index}
                        x={index * 9 + 1}
                        y={40 - height}
                        width={6}
                        height={height}
                        rx={1.5}
                        style={{ fill: color, opacity: index === values.length - 1 ? 0.9 : 0.35 }}
                    />
                );
            })}
        </svg>
    );
}

/* ------------------------------------------------------------------------ */
/*  Charts                                                                  */
/* ------------------------------------------------------------------------ */

type Step = 'daily' | 'weekly' | 'monthly';

/** The step a span reads best at before anybody chooses one. */
function defaultStep(days: number): Step {
    return days <= 45 ? 'daily' : days <= 210 ? 'weekly' : 'monthly';
}

/** Monday of the week a day falls in, Y-m-d. */
function weekOf(date: string): string {
    const day = new Date(`${date}T00:00:00`);
    const back = (day.getDay() + 6) % 7;

    day.setDate(day.getDate() - back);

    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
}

/**
 * Days folded into weeks or months, every series summed the same way.
 *
 * Done here rather than asked of the server: the days have already arrived,
 * and turning a dropdown should not cost a round trip.
 */
function fold(chart: DashboardChart, step: Step) {
    const points = chart.points ?? [];
    const series = chart.series ?? [];

    if (step === 'daily') {
        return { points: points.map((point) => ({ ...point, key: point.date })), series };
    }

    const order: string[] = [];
    const groups = new Map<string, number[]>();

    points.forEach((point, index) => {
        const key = step === 'monthly' ? point.date.slice(0, 7) : weekOf(point.date);

        if (!groups.has(key)) {
            groups.set(key, []);
            order.push(key);
        }

        groups.get(key)!.push(index);
    });

    return {
        points: order.map((key) => {
            const date = new Date(`${step === 'monthly' ? `${key}-01` : key}T00:00:00`);

            return {
                key,
                date: key,
                label:
                    step === 'monthly'
                        ? date.toLocaleDateString('en-GB', { month: 'short' })
                        : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }),
                title:
                    step === 'monthly'
                        ? date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
                        : `Week of ${date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}`,
            };
        }),
        series: series.map((line) => ({
            ...line,
            values: order.map((key) => groups.get(key)!.reduce((sum, index) => sum + (line.values[index] ?? 0), 0)),
        })),
    };
}

function ChartCard({ chart }: { chart: DashboardChart }) {
    const days = chart.points?.length ?? 0;
    const [step, setStep] = useState<Step>(() => defaultStep(days));

    const folded = useMemo(() => fold(chart, chart.granular ? step : 'daily'), [chart, step]);

    const format = chart.money ? inr : count;
    const axis = chart.money ? compactInr : count;

    const isTimeline = chart.kind === 'area' || chart.kind === 'bars' || chart.kind === 'stacked' || chart.kind === 'lines';
    const empty = isTimeline
        ? folded.series.every((line) => line.values.every((value) => value === 0))
        : (chart.rows ?? []).every((row) => row.value === 0);

    return (
        <section className={`phr-card phr-chart is-${chart.kind}${chart.wide ? ' is-wide' : ''}`}>
            <header className="phr-card-head">
                <span className="phr-card-icon">
                    <i className={chart.icon} aria-hidden="true" />
                </span>

                <div className="phr-card-title">
                    <h2>{chart.title}</h2>
                    {chart.subtitle && <p>{chart.subtitle}</p>}
                </div>

                <div className="phr-card-actions">
                    {isTimeline && (chart.series?.length ?? 0) > 1 && (
                        <ul className="phr-legend">
                            {chart.series!.map((line) => (
                                <li key={line.label} className={line.dashed ? 'is-dashed' : undefined}>
                                    <span style={{ background: TONE[line.tone] }} />
                                    {line.label}
                                </li>
                            ))}
                        </ul>
                    )}

                    {chart.granular && days > 1 && (
                        <span className="od-select">
                            <select
                                value={step}
                                onChange={(event) => setStep(event.target.value as Step)}
                                aria-label="Show by"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                            <i className="ti ti-chevron-down" aria-hidden="true" />
                        </span>
                    )}
                </div>
            </header>

            {empty ? (
                <div className="od-nothing">
                    <i className={chart.icon} aria-hidden="true" />
                    <p>Nothing to show for this range yet.</p>
                </div>
            ) : chart.kind === 'area' || chart.kind === 'lines' ? (
                <LineChart
                    points={folded.points}
                    series={folded.series.map((line, index) => ({
                        label: line.label,
                        color: TONE[line.tone] ?? TONE.blue,
                        values: line.values,
                        dashed: line.dashed,
                        area: chart.kind === 'area' && index === 0,
                    }))}
                    format={format}
                    axis={axis}
                    height={250}
                />
            ) : chart.kind === 'bars' || chart.kind === 'stacked' ? (
                <StackedColumns
                    points={folded.points.map((point, index) => ({
                        key: point.key,
                        label: point.label,
                        title: point.title,
                        values: folded.series.map((line) => line.values[index] ?? 0),
                    }))}
                    series={folded.series.map((line) => ({ label: line.label, color: TONE[line.tone] ?? TONE.blue }))}
                    format={format}
                    axis={axis}
                    height={250}
                    maxBar={chart.kind === 'stacked' ? 14 : 22}
                />
            ) : chart.kind === 'meter' ? (
                <Meter rows={chart.rows ?? []} />
            ) : (
                <Donut chart={chart} format={format} />
            )}
        </section>
    );
}

function Donut({ chart, format }: { chart: DashboardChart; format: (value: number) => string }) {
    const [active, setActive] = useState<string | null>(null);

    const rows = chart.rows ?? [];
    const total = rows.reduce((sum, row) => sum + row.value, 0);

    let hue = 0;

    const slices = rows.map((row) => ({
        key: row.label,
        label: sliceLabel(row.label),
        value: row.value,
        color: row.muted ? 'var(--od-grey)' : TONE[SLICES[hue++ % SLICES.length]],
    }));

    return (
        <div className="phr-donut">
            <Ring slices={slices} caption={chart.centre ?? 'Total'} format={format} active={active} onActive={setActive} />

            <ul className="phr-donut-legend">
                {slices.map((slice) => (
                    <li
                        key={slice.key}
                        className={active === slice.key ? 'is-active' : undefined}
                        onMouseEnter={() => setActive(slice.key)}
                        onMouseLeave={() => setActive(null)}
                    >
                        <span className="phr-dot" style={{ background: slice.color }} />
                        <span className="phr-donut-name" title={slice.label}>
                            {slice.label}
                        </span>
                        <span className="phr-donut-share">{total > 0 ? Math.round((slice.value / total) * 100) : 0}%</span>
                        <b>{format(slice.value)}</b>
                    </li>
                ))}
            </ul>
        </div>
    );
}

const METER_TONE: Record<string, string> = {
    in_stock: 'var(--od-green)',
    out: 'var(--od-rose)',
    low: 'var(--od-amber)',
    expired: 'var(--od-grey)',
};

/** Counts as bars on a shared scale — how many items stand where. */
function Meter({ rows }: { rows: NonNullable<DashboardChart['rows']> }) {
    const peak = Math.max(...rows.map((row) => row.value), 1);

    return (
        <ul className="phr-meter">
            {rows.map((row, index) => (
                <li key={row.label}>
                    <span className="phr-rank">{index + 1}</span>
                    <span className="phr-meter-label">{row.label}</span>
                    <span className="phr-meter-track">
                        <span
                            style={{
                                width: `${(row.value / peak) * 100}%`,
                                background: METER_TONE[row.key ?? ''] ?? 'var(--od-blue)',
                            }}
                        />
                    </span>
                    <b>{count(row.value)}</b>
                </li>
            ))}
        </ul>
    );
}

/* ------------------------------------------------------------------------ */
/*  Lists                                                                   */
/* ------------------------------------------------------------------------ */

const BADGE: Record<string, { label: string; tone: string }> = {
    paid: { label: 'Paid', tone: 'good' },
    partial: { label: 'Partial', tone: 'warn' },
    unpaid: { label: 'Unpaid', tone: 'bad' },
    received: { label: 'Received', tone: 'good' },
    low: { label: 'Low', tone: 'bad' },
    out: { label: 'Out of stock', tone: 'bad' },
    in_stock: { label: 'In stock', tone: 'good' },
    expired: { label: 'Expired', tone: 'bad' },
};

export function Badge({ status }: { status: string }) {
    const badge = BADGE[status] ?? { label: status, tone: 'muted' };

    return <span className={`phr-badge is-${badge.tone}`}>{badge.label}</span>;
}

/** One cell, read the way its column says. */
function Cell({ value, format }: { value: unknown; format?: CellFormat }) {
    if (value === null || value === undefined || value === '') {
        return <span className="phr-muted">—</span>;
    }

    switch (format) {
        case 'rank':
            return <span className="phr-rank">{String(value)}</span>;
        case 'money':
            return <>{inr(Number(value))}</>;
        case 'count':
            return <>{count(Number(value))}</>;
        case 'percent':
            return <>{Number(value).toFixed(1).replace(/\.0$/, '')}%</>;
        case 'date':
            return <>{formatDate(String(value))}</>;
        case 'datetime': {
            const when = new Date(String(value));

            return (
                <span className="phr-when">
                    {formatDate(String(value))}
                    <small>{when.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}</small>
                </span>
            );
        }
        case 'badge':
            return <Badge status={String(value)} />;
        case 'delta': {
            const change = Number(value);

            return (
                <span className={change > 0 ? 'phr-up' : change < 0 ? 'phr-down' : undefined}>
                    {change > 0 ? '+' : ''}
                    {count(change)}
                </span>
            );
        }
        case 'expiry': {
            const days = (new Date(`${String(value)}T00:00:00`).getTime() - new Date().setHours(0, 0, 0, 0)) / 86_400_000;

            return <span className={days <= 30 ? 'phr-down' : undefined}>{formatDate(String(value))}</span>;
        }
        case 'muted':
            return <span className="phr-muted">{String(value)}</span>;
        default:
            return <>{String(value)}</>;
    }
}

const NUMERIC: CellFormat[] = ['money', 'count', 'percent', 'delta'];

function TableCard({ table, onViewAll }: { table: DashboardTable; onViewAll: (report: ReportKey) => void }) {
    return (
        <section className="phr-card phr-list">
            <header className="phr-card-head">
                <span className="phr-card-icon">
                    <i className={table.icon} aria-hidden="true" />
                </span>

                <div className="phr-card-title">
                    <h2>{table.title}</h2>
                    {table.subtitle && <p>{table.subtitle}</p>}
                </div>

                <div className="phr-card-actions">
                    <button type="button" className="phr-view-all" onClick={() => onViewAll(table.link)}>
                        View all
                    </button>
                </div>
            </header>

            {table.rows.length === 0 ? (
                <div className="od-nothing is-short">
                    <i className={table.icon} aria-hidden="true" />
                    <p>Nothing to list for this range.</p>
                </div>
            ) : (
                <div className="phr-table-wrap">
                    <table className="phr-table">
                        <thead>
                            <tr>
                                {table.columns.map((column) => (
                                    <th
                                        key={column.key}
                                        className={
                                            column.format === 'rank'
                                                ? 'is-rank'
                                                : NUMERIC.includes(column.format as CellFormat)
                                                  ? 'is-num'
                                                  : undefined
                                        }
                                    >
                                        {column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {table.rows.map((row, index) => (
                                <tr key={String(row.id ?? index)}>
                                    {table.columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className={
                                                column.format === 'rank'
                                                    ? 'is-rank'
                                                    : NUMERIC.includes(column.format as CellFormat)
                                                      ? 'is-num'
                                                      : column.format === 'muted' || column.format === 'date' || column.format === 'expiry'
                                                        ? 'is-quiet'
                                                        : undefined
                                            }
                                        >
                                            {column.sub ? (
                                                <span className="phr-stacked">
                                                    <b>
                                                        <Cell value={row[column.key]} format={column.format} />
                                                    </b>
                                                    {row[column.sub] ? (
                                                        <small>
                                                            {column.sub_prefix ?? ''}
                                                            {String(row[column.sub])}
                                                        </small>
                                                    ) : null}
                                                </span>
                                            ) : (
                                                <Cell value={row[column.key]} format={column.format} />
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                        {table.total && (
                            <tfoot>
                                <tr>
                                    {table.columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className={NUMERIC.includes(column.format as CellFormat) ? 'is-num' : undefined}
                                        >
                                            <Cell value={table.total![column.key]} format={column.format} />
                                        </td>
                                    ))}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            )}
        </section>
    );
}
