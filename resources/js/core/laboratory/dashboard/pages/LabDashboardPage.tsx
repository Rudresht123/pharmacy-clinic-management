import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { LineChart, Ring, TrendCurve } from '@/shared/components/ui/ReportCharts';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { LabStatus, shortStamp } from '../../orders/status';
import { CATALOGUE_PATH, CATALOGUE_TICKS } from '../../tests/catalogue';
import { useLabDashboard, type DashboardRange, type Slice } from '../api';

const RANGES: { value: DashboardRange; label: string; previous: string }[] = [
    { value: '7d', label: 'Last 7 days', previous: 'the 7 days before' },
    { value: '30d', label: 'Last 30 days', previous: 'the 30 days before' },
    { value: '3m', label: 'Last 3 months', previous: 'the 3 months before' },
    { value: '1y', label: 'Last 12 months', previous: 'the year before' },
];

const BLUE = '#3b82f6';
const GREEN = '#22c55e';
const AMBER = '#f59e0b';
const RED = '#ef4444';
const PURPLE = '#8b5cf6';
const SLICE_COLOURS = [BLUE, GREEN, AMBER, PURPLE, '#94a3b8'];

const count = (value: number) => value.toLocaleString('en-IN');

/** One headline figure: icon, value, what it is, how it moved, and its trend. */
function KpiCard({
    icon,
    tone,
    label,
    value,
    foot,
    change,
    series,
    colour,
    goodWhenDown,
}: {
    icon: string;
    tone: string;
    label: string;
    value: number;
    foot: ReactNode;
    change?: number | null;
    series?: number[];
    colour: string;
    goodWhenDown?: boolean;
}) {
    const up = (change ?? 0) >= 0;
    const good = goodWhenDown ? !up : up;

    return (
        <Card className="lab-kpi">
            <div className="lab-kpi-top">
                <span className={`lab-kpi-icon is-${tone}`} aria-hidden="true">
                    <i className={icon} />
                </span>
                <div className="lab-kpi-figure">
                    <b>{count(value)}</b>
                    <span>{label}</span>
                </div>
                {series && series.length > 1 && series.some((point) => point > 0) && (
                    <div className="lab-kpi-trend">
                        <TrendCurve points={series} color={colour} height={40} />
                    </div>
                )}
            </div>
            <div className="lab-kpi-foot">
                {change !== undefined && change !== null && (
                    <em className={good ? 'is-good' : 'is-bad'}>
                        <i className={up ? 'ti ti-arrow-up' : 'ti ti-arrow-down'} aria-hidden="true" />
                        {Math.abs(change)}%
                    </em>
                )}
                <span>{foot}</span>
            </div>
        </Card>
    );
}

/** A card with its title row, the dashboard's own frame. */
function Panel({ icon, title, action, children, className }: { icon: string; title: string; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <Card className={`lab-panel-card ${className ?? ''}`}>
            <div className="lab-panel-head">
                <h6>
                    <i className={icon} aria-hidden="true" />
                    {title}
                </h6>
                {action}
            </div>
            {children}
        </Card>
    );
}

/**
 * The Laboratory Dashboard: what the lab has been asked for, what it has done
 * and what is waiting — the headline figures, the trend, the split, the latest
 * requests and the ways in.
 *
 * Real figures only, counted from the lab's own orders at the branches this
 * person may see. Money is not here: a lab bill is for the billing screens.
 */
export default function LabDashboardPage() {
    const { can } = useTenantAuth();
    const [range, setRange] = useState<DashboardRange>('30d');
    const [active, setActive] = useState<string | null>(null);
    const { data, isLoading, isError, refetch } = useLabDashboard(range);
    const words = RANGES.find((option) => option.value === range) ?? RANGES[1];

    const header = (
        <PageHeader
            title="Laboratory Dashboard"
            subtitle="Tests requested, completed and waiting, at a glance."
            icon="ti ti-flask"
            tone="violet"
            crumbs={[{ label: 'Laboratory' }, { label: 'Dashboard' }]}
            actions={
                <div className="lab-range">
                    <i className="ti ti-calendar" aria-hidden="true" />
                    <SearchableSelect
                        compact
                        ariaLabel="Period"
                        value={range}
                        onChange={(value) => setRange((value || '30d') as DashboardRange)}
                        options={RANGES.map(({ value, label }) => ({ value, label }))}
                    />
                </div>
            }
        />
    );

    if (isLoading) {
        return (
            <>
                {header}
                <LoadingBlock label="Counting the lab's tests…" />
            </>
        );
    }

    if (isError || !data) {
        return (
            <>
                {header}
                <ErrorState onRetry={refetch} />
            </>
        );
    }

    const { kpis, overview } = data;
    const byDepartment = data.departments.some((slice) => slice.key !== 'typed');
    const split: Slice[] = byDepartment ? data.departments : data.top_tests;
    const splitTotal = split.reduce((sum, slice) => sum + slice.value, 0);
    const empty = overview.ordered.every((value) => value === 0) && overview.completed.every((value) => value === 0);

    const actions = [
        can('laboratory.view') && { to: '/lab/orders', icon: 'ti ti-list-details', label: 'All Tests', tone: 'blue' },
        can('laboratory.view') && { to: '/lab/orders?status=pending', icon: 'ti ti-hourglass', label: 'Pending Tests', tone: 'amber' },
        CATALOGUE_TICKS.some(can) && {
            to: CATALOGUE_PATH,
            icon: 'ti ti-test-pipe',
            label: 'Test Catalogue',
            tone: 'green',
        },
        (can('laboratory.settings_view') || can('laboratory.settings_manage')) && {
            to: '/settings/laboratory',
            icon: 'ti ti-settings',
            label: 'Lab Settings',
            tone: 'purple',
        },
    ].filter(Boolean) as { to: string; icon: string; label: string; tone: string }[];

    return (
        <>
            {header}

            <div className="lab-kpis">
                <KpiCard
                    icon="ti ti-flask"
                    tone="blue"
                    colour={BLUE}
                    label="Tests Requested"
                    value={kpis.ordered.value}
                    change={kpis.ordered.change}
                    series={kpis.ordered.series}
                    foot={kpis.ordered.change === null ? words.label : `from ${words.previous}`}
                />
                <KpiCard
                    icon="ti ti-circle-check"
                    tone="green"
                    colour={GREEN}
                    label="Completed"
                    value={kpis.completed.value}
                    change={kpis.completed.change}
                    series={kpis.completed.series}
                    foot={kpis.completed.change === null ? words.label : `from ${words.previous}`}
                />
                <KpiCard
                    icon="ti ti-clock-hour-4"
                    tone="amber"
                    colour={AMBER}
                    label="Pending Reports"
                    value={kpis.pending.value}
                    foot={`Right now, across ${count(kpis.pending.orders)} request${kpis.pending.orders === 1 ? '' : 's'}`}
                />
                <KpiCard
                    icon="ti ti-circle-x"
                    tone="red"
                    colour={RED}
                    label="Cancelled"
                    value={kpis.cancelled.value}
                    change={kpis.cancelled.change}
                    series={kpis.cancelled.series}
                    goodWhenDown
                    foot={kpis.cancelled.change === null ? words.label : `from ${words.previous}`}
                />
            </div>

            <div className="lab-dash-row">
                <Panel
                    icon="ti ti-chart-line"
                    title="Test Overview"
                    action={
                        <div className="lab-legend">
                            <span><i style={{ background: BLUE }} /> Requested</span>
                            <span><i style={{ background: PURPLE }} /> Completed</span>
                        </div>
                    }
                >
                    {empty ? (
                        <div className="lab-empty">
                            <i className="ti ti-chart-line" aria-hidden="true" />
                            <p>No tests requested or completed in {words.label.toLowerCase()}.</p>
                        </div>
                    ) : (
                        <LineChart
                            points={overview.points}
                            series={[
                                { label: 'Requested', color: BLUE, values: overview.ordered, area: true },
                                { label: 'Completed', color: PURPLE, values: overview.completed, area: true },
                            ]}
                            height={250}
                            format={(value) => `${count(value)} tests`}
                            axis={(value) => count(Math.round(value))}
                        />
                    )}
                </Panel>

                <Panel icon="ti ti-chart-donut" title={byDepartment ? 'Tests by Department' : 'Most Requested Tests'}>
                    {splitTotal === 0 ? (
                        <div className="lab-empty">
                            <i className="ti ti-chart-donut" aria-hidden="true" />
                            <p>No tests in {words.label.toLowerCase()}.</p>
                        </div>
                    ) : (
                        <div className="lab-split">
                            <Ring
                                slices={split.map((slice, index) => ({
                                    key: slice.key,
                                    label: slice.label,
                                    value: slice.value,
                                    color: SLICE_COLOURS[index % SLICE_COLOURS.length],
                                }))}
                                caption="Total"
                                format={count}
                                active={active}
                                onActive={setActive}
                            />
                            <ul className="lab-split-legend">
                                {split.map((slice, index) => (
                                    <li
                                        key={slice.key}
                                        className={active === slice.key ? 'is-active' : undefined}
                                        onMouseEnter={() => setActive(slice.key)}
                                        onMouseLeave={() => setActive(null)}
                                    >
                                        <i style={{ background: SLICE_COLOURS[index % SLICE_COLOURS.length] }} />
                                        <span>{slice.label}</span>
                                        <b>{Math.round((slice.value / splitTotal) * 100)}%</b>
                                    </li>
                                ))}
                            </ul>
                            {!byDepartment && (
                                <p className="lab-split-note">
                                    These tests were typed by hand, so they have no department yet. Tests ordered from the
                                    catalogue are split by department here.
                                </p>
                            )}
                        </div>
                    )}
                </Panel>
            </div>

            <div className="lab-dash-row">
                <Panel
                    icon="ti ti-list-details"
                    title="Recent Test Requests"
                    action={
                        can('laboratory.view') ? (
                            <Link to="/lab/orders" className="lab-view-all">
                                View all <i className="ti ti-arrow-right" aria-hidden="true" />
                            </Link>
                        ) : undefined
                    }
                >
                    {data.recent.length === 0 ? (
                        <div className="lab-empty">
                            <i className="ti ti-test-pipe" aria-hidden="true" />
                            <p>No tests requested yet.</p>
                        </div>
                    ) : (
                        <div className="lab-ranges-wrap">
                            <table className="lab-ranges lab-recent">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Patient</th>
                                        <th>Test Name</th>
                                        <th>Department</th>
                                        <th>Requested</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.recent.slice(0, 5).map((row, index) => (
                                        <tr key={row.id}>
                                            <td className="lab-row-no">{index + 1}</td>
                                            <td title={row.patient_code ?? undefined}>
                                                {can('laboratory.view') ? (
                                                    <Link to={`/lab/orders/${row.id}`} className="lab-recent-name">
                                                        {row.patient_name ?? '—'}
                                                    </Link>
                                                ) : (
                                                    row.patient_name ?? '—'
                                                )}
                                            </td>
                                            <td className="lab-recent-tests" title={row.tests}>{row.tests}</td>
                                            <td>{row.department ?? <span className="text-muted">Not set</span>}</td>
                                            <td className="text-nowrap">{shortStamp(row.ordered_at)}</td>
                                            <td><LabStatus status={row.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel icon="ti ti-bolt" title="Quick Actions">
                    <div className="lab-actions">
                        {actions.map((action) => (
                            <Link key={action.to} to={action.to} className={`lab-action is-${action.tone}`}>
                                <span aria-hidden="true">
                                    <i className={action.icon} />
                                </span>
                                {action.label}
                            </Link>
                        ))}
                    </div>
                </Panel>
            </div>
        </>
    );
}
