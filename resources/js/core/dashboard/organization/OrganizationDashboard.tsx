import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { DateRangePicker } from '@/core/billing/components/BillingWidgets';
import { formatDate } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ActivityFeed, feedItemFromLog } from '@/shared/components/ui/ActivityFeed';
import {
    useDashboardPanel,
    type BranchActivityEntry,
    type BranchPerformancePanel,
    type ComparedFigure,
    type DashboardRange,
    type DashboardSummary,
    type FollowUpRow,
    type FollowUpsPanel,
    type Granularity,
    type KpiCard,
    type NetworkOverview,
    type PharmacyInventoryPanel,
    type PlanPanel,
    type PnlPeriod,
    type ProfitLossPanel,
    type RevenueOverviewPanel,
    type ServicePeriod,
    type TopServicesPanel,
    type VisitTrendsPanel,
} from '../api';
import {
    compactInr,
    count,
    GroupedColumns,
    inr,
    Ring,
    StackedColumns,
    TrendCurve,
} from '@/shared/components/ui/ReportCharts';
import { PatientRecordLink, useCanContact } from '@/core/customers/components/PatientRecordLink';

/**
 * The workspace dashboard — head office's view of the network, or a branch's
 * view of itself. One screen for both: a branch dashboard is the same cards
 * about the branch somebody is standing in, without the branch-against-branch
 * table only the network has.
 *
 * Laid out after the reference design: the banner and plan, the figures,
 * revenue beside branch performance, then the cards two by two — visits and
 * services, profit and stock, activity and follow-ups.
 *
 * Every section is optional. The server sends only what this person may see
 * (App\Services\Tenant\DashboardSummary), so a card whose panel did not
 * arrive is simply not drawn, and the rows close up around the gap.
 */
export function OrganizationDashboard({
    data,
    range,
    onRange,
    refreshing,
}: {
    data: DashboardSummary;
    range: DashboardRange;
    onRange: (range: DashboardRange) => void;
    refreshing: boolean;
}) {
    const { user, organization } = useTenantAuth();
    const isOwner = user?.role === 'owner';
    const isBranch = data.context === 'branch';

    const branches = data.scope.options ?? [];
    const compare = data.scope.window?.compare ?? 'vs last month';

    const rowTwo = [
        data.revenue_overview && (
            <RevenueOverview key="revenue" initial={data.revenue_overview} range={range} branches={branches} />
        ),
        data.branch_performance && <BranchPerformance key="branches" panel={data.branch_performance} />,
    ].filter(Boolean);

    // Two by two, in the reference's order; a missing card lets the next
    // one move up, and an odd one out takes the whole row.
    const cards = [
        data.visit_trends && <VisitTrends key="visits" initial={data.visit_trends} branches={branches} />,
        data.top_services && <TopServices key="services" initial={data.top_services} />,
        data.profit_loss && <ProfitLoss key="pnl" initial={data.profit_loss} />,
        data.pharmacy_inventory && (
            <PharmacyInventory key="stock" initial={data.pharmacy_inventory} branches={branches} />
        ),
        data.branch_activity && (
            <BranchActivity key="activity" entries={data.branch_activity} isBranch={isBranch} />
        ),
        data.follow_ups && <FollowUps key="follow-ups" panel={data.follow_ups} />,
    ].filter(Boolean);

    return (
        <div className={`od-page${refreshing ? ' is-refreshing' : ''}`}>
            <header className="od-greeting">
                <div>
                    <h1>
                        {greeting()}, {user?.name ?? 'there'} <span aria-hidden="true">👋</span>
                    </h1>
                    <p>
                        {isBranch && data.scope.branch_name
                            ? `Here’s what’s happening at ${data.scope.branch_name}`
                            : data.scope.branches === null
                              ? 'Here’s what’s happening across all your branches'
                              : `Here’s what’s happening across ${data.scope.label}`}
                    </p>
                </div>

                <DateRangePicker value={range} onChange={onRange} />
            </header>

            <div className={`od-hero${data.plan && !isBranch ? '' : ' is-single'}`}>
                <Banner
                    name={isBranch ? (data.scope.branch_name ?? 'Your branch') : (organization?.name ?? 'Your organisation')}
                    logo={organization?.has_logo ? organization.logo_url : null}
                    overview={data.overview}
                    city={isBranch ? data.scope.city : null}
                />

                {data.plan && !isBranch && <Plan plan={data.plan} isOwner={isOwner} />}
            </div>

            {data.kpis && data.kpis.length > 0 && <Kpis cards={data.kpis} compare={compare} />}

            {rowTwo.length > 0 && <div className={`od-row od-row-2 is-${rowTwo.length}`}>{rowTwo}</div>}

            {cards.length > 0 && <div className={`od-pairs${cards.length % 2 ? ' is-odd' : ''}`}>{cards}</div>}
        </div>
    );
}

/** Good morning until noon, afternoon until five, evening after. */
function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'Good Morning' : hour < 17 ? 'Good Afternoon' : 'Good Evening';
}

/* ------------------------------------------------------------------------ */
/*  Shared pieces                                                           */
/* ------------------------------------------------------------------------ */

/**
 * One card: its icon in a tinted square, a title with the line under it,
 * whatever controls it carries, and its body.
 */
function Panel({
    icon,
    tone = 'blue',
    title,
    subtitle,
    actions,
    busy,
    className,
    children,
}: {
    icon: string;
    tone?: string;
    title: string;
    subtitle?: string;
    actions?: ReactNode;
    busy?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section className={`od-card${className ? ` ${className}` : ''}`} aria-busy={busy || undefined}>
            <header className="od-card-head">
                <div className="od-card-title">
                    <span className={`od-card-mark od-bg-${tone}`} aria-hidden="true">
                        <i className={icon} />
                    </span>

                    <div>
                        <h2>{title}</h2>
                        {subtitle && <p>{subtitle}</p>}
                    </div>
                </div>

                {actions && <div className="od-card-actions">{actions}</div>}
            </header>

            <div className={`od-card-body${busy ? ' is-busy' : ''}`}>{children}</div>
        </section>
    );
}

function ViewAll({ to, children = 'View All' }: { to: string; children?: ReactNode }) {
    return (
        <Link to={to} className="od-link">
            {children}
            <i className="ti ti-arrow-right" aria-hidden="true" />
        </Link>
    );
}

/** A compact select, the size of the controls in a card's header. */
function Choice<T extends string>({
    value,
    onChange,
    options,
    label,
    icon,
}: {
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
    label: string;
    icon?: string;
}) {
    return (
        <span className={`od-select${icon ? ' has-icon' : ''}`}>
            {icon && <i className={`${icon} od-select-icon`} aria-hidden="true" />}
            <select value={value} onChange={(event) => onChange(event.target.value as T)} aria-label={label}>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <i className="ti ti-chevron-down" aria-hidden="true" />
        </span>
    );
}

/** "All Branches", or one of them — offered only when there is more than one. */
function BranchChoice({
    value,
    onChange,
    branches,
}: {
    value: string;
    onChange: (value: string) => void;
    branches: { id: number; name: string }[];
}) {
    if (branches.length < 2) {
        return null;
    }

    return (
        <Choice
            value={value}
            onChange={onChange}
            label="Branch"
            options={[
                { value: '', label: 'All Branches' },
                ...branches.map((branch) => ({ value: String(branch.id), label: branch.name })),
            ]}
        />
    );
}

const PERIODS: { value: ServicePeriod; label: string }[] = [
    { value: 'this_month', label: 'This Month' },
    { value: 'last_month', label: 'Last Month' },
    { value: 'this_year', label: 'This Year' },
];

/**
 * A change against the period before, arrow and colour together. `goodWhenUp`
 * is false for a cost, where a rise is the half somebody needs to catch.
 */
function Change({ change, goodWhenUp = true }: { change: number | null; goodWhenUp?: boolean }) {
    if (change === null) {
        return null;
    }

    const good = change === 0 ? null : change > 0 === goodWhenUp;

    return (
        <em className={`od-change${good === null ? '' : good ? ' is-good' : ' is-bad'}`}>
            <i
                className={change > 0 ? 'ti ti-arrow-up' : change < 0 ? 'ti ti-arrow-down' : 'ti ti-minus'}
                aria-hidden="true"
            />
            {Math.abs(change)}%
        </em>
    );
}

/**
 * One of the small figures over a chart: its icon, the figure and its name,
 * and how it moved. Plain white with a hairline — colour stays on the icon
 * and the change.
 */
function Mini({
    icon,
    tone,
    value,
    label,
    figure,
    compare,
    goodWhenUp,
    loss,
}: {
    icon: string;
    tone: string;
    value: string;
    label: string;
    figure?: ComparedFigure;
    compare?: string;
    goodWhenUp?: boolean;
    loss?: boolean;
}) {
    return (
        <div className="od-mini">
            <span className={`od-mini-icon od-bg-${tone}`} aria-hidden="true">
                <i className={icon} />
            </span>

            <span className="od-mini-text">
                <b className={loss ? 'is-loss' : undefined}>{value}</b>
                <small>{label}</small>
            </span>

            {figure && figure.change !== null && (
                <span className="od-mini-change">
                    <Change change={figure.change} goodWhenUp={goodWhenUp} />
                    {compare && <small>{compare}</small>}
                </span>
            )}
        </div>
    );
}

/** What a chart's colours mean, centred under it. */
function Legend({ items }: { items: { label: string; color: string; line?: boolean }[] }) {
    return (
        <ul className="od-legend is-below">
            {items.map((item) => (
                <li key={item.label}>
                    <span
                        className={`od-key${item.line ? ' is-line' : ''}`}
                        style={{ background: item.color, ...(item.line ? { color: item.color } : {}) }}
                    />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

/** A card with nothing to draw yet, said quietly in the space the chart would take. */
function Nothing({ icon, children }: { icon: string; children: ReactNode }) {
    return (
        <div className="od-nothing">
            <i className={icon} aria-hidden="true" />
            <p>{children}</p>
        </div>
    );
}

/** "Jan 2024". */
function monthYear(iso: string | null | undefined): string {
    if (!iso) return '';

    // en-US: "Sep 2026", where en-GB now says "Sept".
    return new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
}

/** "Oct 7, 2026" — the reference's own date. */
function shortDate(iso: string): string {
    return new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function plural(n: number, word: string): string {
    return `${n} ${word}${n === 1 ? '' : 's'}`;
}

/* ------------------------------------------------------------------------ */
/*  Banner and plan                                                         */
/* ------------------------------------------------------------------------ */

function Banner({
    name,
    logo,
    overview,
    city,
}: {
    name: string;
    logo: string | null;
    overview?: NetworkOverview;
    city?: string | null;
}) {
    const facts = [
        city && { icon: 'ti ti-map-pin', text: city },
        // A branch's own banner does not count branches.
        !city &&
            overview?.branches != null && {
                icon: 'ti ti-building-hospital',
                text: `${count(overview.branches)} ${overview.branches === 1 ? 'Branch' : 'Branches'}`,
            },
        overview?.doctors != null && {
            icon: 'ti ti-stethoscope',
            text: `${count(overview.doctors)} ${overview.doctors === 1 ? 'Doctor' : 'Doctors'}`,
        },
        overview?.staff != null && {
            icon: 'ti ti-users',
            text: `${count(overview.staff)} Staff`,
        },
        overview?.since && { icon: 'ti ti-calendar', text: `Active since ${monthYear(overview.since)}` },
    ].filter(Boolean) as { icon: string; text: string }[];

    return (
        <section className="od-banner">
            <span className="od-banner-mark">
                {logo ? <img src={logo} alt="" /> : <i className="ti ti-building-hospital" aria-hidden="true" />}
            </span>

            <div className="od-banner-text">
                <h2>{name}</h2>

                {facts.length > 0 && (
                    <ul>
                        {facts.map((fact) => (
                            <li key={fact.text}>
                                <i className={fact.icon} aria-hidden="true" />
                                {fact.text}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <img className="od-banner-art" src="/assets/img/dashboard/clinic-building.svg" alt="" aria-hidden="true" />
        </section>
    );
}

/**
 * What this organization holds. There are no subscription tiers in the
 * software — a super admin assigns modules — so outside demo mode this names
 * how many modules are on, and the owner manages them from here.
 */
function Plan({ plan, isOwner }: { plan: PlanPanel; isOwner: boolean }) {
    const line = plan.valid_until
        ? `Valid till ${formatDate(plan.valid_until)}`
        : plan.trial_ends_at
          ? `Trial ends ${formatDate(plan.trial_ends_at)}`
          : plan.since
            ? `Active since ${monthYear(plan.since)}`
            : `${plan.modules} modules enabled`;

    return (
        <section className="od-plan">
            <span className="od-plan-mark">
                <i className="ti ti-crown" aria-hidden="true" />
            </span>

            <div className="od-plan-text">
                <b>{plan.name ?? `${plan.modules} Modules Plan`}</b>
                <span>{line}</span>
            </div>

            {isOwner && (
                <Link to="/settings/modules" className="od-plan-button">
                    Manage Plan
                    <i className="ti ti-arrow-right" aria-hidden="true" />
                </Link>
            )}
        </section>
    );
}

/* ------------------------------------------------------------------------ */
/*  The five figures                                                        */
/* ------------------------------------------------------------------------ */

const KPI_LOOK: Record<KpiCard['key'], { icon: string; tone: string; color: string }> = {
    patients: { icon: 'ti ti-users', tone: 'blue', color: 'var(--od-blue)' },
    appointments: { icon: 'ti ti-calendar-check', tone: 'green', color: 'var(--od-green)' },
    revenue: { icon: 'ti ti-currency-rupee', tone: 'rose', color: 'var(--od-rose)' },
    purchases: { icon: 'ti ti-shopping-cart', tone: 'violet', color: 'var(--od-violet)' },
    profit: { icon: 'ti ti-chart-bar', tone: 'amber', color: 'var(--od-amber)' },
};

function Kpis({ cards, compare }: { cards: KpiCard[]; compare: string }) {
    return (
        <div className={`od-kpis is-${cards.length}`}>
            {cards.map((card) => {
                const look = KPI_LOOK[card.key];

                return (
                    <article className={`od-kpi is-${look.tone}`} key={card.key} title={card.hint}>
                        <div className="od-kpi-top">
                            <span className="od-kpi-icon">
                                <i className={look.icon} aria-hidden="true" />
                            </span>
                            <span className="od-kpi-label">{card.label}</span>
                        </div>

                        <div className="od-kpi-body">
                            <div className="od-kpi-value">
                                <b>{card.money ? inr(card.value) : count(card.value)}</b>
                                <Change change={card.change} />
                            </div>

                            <span className="od-kpi-hint">{compare}</span>

                            <TrendCurve points={card.series} color={look.color} height={34} />
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------------ */
/*  Revenue overview                                                        */
/* ------------------------------------------------------------------------ */

const DEPARTMENTS = [
    { key: 'opd', label: 'OPD', color: 'var(--od-blue)' },
    { key: 'pharmacy', label: 'Pharmacy', color: 'var(--od-green)' },
    { key: 'laboratory', label: 'Lab', color: 'var(--od-amber)' },
    { key: 'procedures', label: 'Procedures', color: 'var(--od-violet)' },
] as const;

function RevenueOverview({
    initial,
    range,
    branches,
}: {
    initial: RevenueOverviewPanel;
    range: DashboardRange;
    branches: { id: number; name: string }[];
}) {
    const [granularity, setGranularity] = useState<Granularity>('monthly');
    const [branch, setBranch] = useState('');

    const own = granularity !== 'monthly' || branch !== '';
    const query = useDashboardPanel<RevenueOverviewPanel>(
        'revenue_overview',
        { ...range, granularity, location_id: branch || undefined },
        own,
    );

    const panel = own ? (query.data ?? initial) : initial;
    const empty = panel.series.every((point) => point.total === 0);

    return (
        <Panel
            icon="ti ti-chart-bar"
            title="Revenue Overview"
            subtitle="Billed revenue by department"
            busy={own && query.isFetching}
            actions={
                <>
                    <div className="od-seg" role="group" aria-label="Show revenue by">
                        {(['daily', 'weekly', 'monthly'] as const).map((option) => (
                            <button
                                key={option}
                                type="button"
                                className={granularity === option ? 'is-active' : undefined}
                                aria-pressed={granularity === option}
                                onClick={() => setGranularity(option)}
                            >
                                {option[0].toUpperCase() + option.slice(1)}
                            </button>
                        ))}
                    </div>
                    <BranchChoice value={branch} onChange={setBranch} branches={branches} />
                </>
            }
        >
            <ul className="od-legend">
                {DEPARTMENTS.map((part) => (
                    <li key={part.key}>
                        <span className="od-key" style={{ background: part.color }} />
                        {part.label}
                    </li>
                ))}
                <li>
                    <span className="od-key is-line" style={{ background: 'var(--od-line)' }} />
                    Total Revenue
                </li>
            </ul>

            {empty ? (
                <Nothing icon="ti ti-chart-bar">No revenue recorded in this period yet.</Nothing>
            ) : (
                <StackedColumns
                    points={panel.series.map((point) => ({
                        key: point.key,
                        label: point.label,
                        title: point.title,
                        values: DEPARTMENTS.map((part) => point[part.key]),
                    }))}
                    series={DEPARTMENTS.map((part) => ({ label: part.label, color: part.color }))}
                    format={inr}
                    axis={compactInr}
                    totalLine={{ label: 'Total Revenue', color: 'var(--od-line)' }}
                    height={250}
                />
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Branch performance                                                      */
/* ------------------------------------------------------------------------ */

const BRANCH_TONES = ['blue', 'green', 'amber', 'violet', 'rose'];

function BranchPerformance({ panel }: { panel: BranchPerformancePanel }) {
    const showAppointments = panel.rows.some((row) => row.appointments !== null);
    const showMoney = panel.rows.some((row) => row.revenue !== null);

    return (
        <Panel
            icon="ti ti-building-hospital"
            title="Branch Performance"
            subtitle="Branches compared over the period"
            actions={<ViewAll to="/locations">View All Branches</ViewAll>}
        >
            {panel.rows.length === 0 ? (
                <Nothing icon="ti ti-building-hospital">No branches yet. Add one to see how it performs.</Nothing>
            ) : (
                <div className="od-table-wrap">
                    <table className="od-table">
                        <thead>
                            <tr>
                                <th className="is-rank">#</th>
                                <th>Branch Name</th>
                                <th className="is-num">Patients</th>
                                {showAppointments && <th className="is-num">Appointments</th>}
                                {showMoney && <th className="is-num">Revenue</th>}
                                {showMoney && <th className="is-num">Profit</th>}
                                {showMoney && <th className="is-num">Growth</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {panel.rows.map((row, index) => (
                                <tr key={row.id}>
                                    <td className="is-rank">
                                        <span>{index + 1}</span>
                                    </td>
                                    <td>
                                        <div className="od-branch">
                                            <span className={`od-branch-mark od-bg-${BRANCH_TONES[index % BRANCH_TONES.length]}`}>
                                                <i className="ti ti-building-hospital" aria-hidden="true" />
                                            </span>
                                            <span className="od-branch-text">
                                                <b>{row.name}</b>
                                                {row.city && (
                                                    <small>
                                                        <i className="ti ti-map-pin" aria-hidden="true" />
                                                        {row.city}
                                                    </small>
                                                )}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="is-num">{count(row.patients)}</td>
                                    {showAppointments && (
                                        <td className="is-num">{row.appointments === null ? '—' : count(row.appointments)}</td>
                                    )}
                                    {showMoney && <td className="is-num">{row.revenue === null ? '—' : inr(row.revenue)}</td>}
                                    {showMoney && <td className="is-num">{row.profit === null ? '—' : inr(row.profit)}</td>}
                                    {showMoney && (
                                        <td className="is-num">
                                            {row.growth === null ? <span className="od-muted">—</span> : <Change change={row.growth} />}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Patient visits                                                          */
/* ------------------------------------------------------------------------ */

const VISITS = [
    { label: 'New Patients', color: 'var(--od-blue)' },
    { label: 'Follow-up Patients', color: 'var(--od-green)' },
];

function VisitTrends({
    initial,
    branches,
}: {
    initial: VisitTrendsPanel;
    branches: { id: number; name: string }[];
}) {
    const [period, setPeriod] = useState<ServicePeriod>('this_month');
    const [branch, setBranch] = useState('');

    const own = period !== 'this_month' || branch !== '';
    const query = useDashboardPanel<VisitTrendsPanel>(
        'visit_trends',
        { period, location_id: branch || undefined },
        own,
    );
    const panel = own ? (query.data ?? initial) : initial;
    const totals = panel.totals;

    return (
        <Panel
            icon="ti ti-users"
            title="Patient Visit Trends"
            subtitle="New vs follow-up patients over time"
            className="od-visits"
            busy={own && query.isFetching}
            actions={
                <>
                    <BranchChoice value={branch} onChange={setBranch} branches={branches} />
                    <Choice value={period} onChange={setPeriod} options={PERIODS} label="Period" icon="ti ti-calendar" />
                </>
            }
        >
            <div className="od-minis">
                <Mini
                    icon="ti ti-users"
                    tone="blue"
                    value={count(panel.new + panel.returning)}
                    label="Total Visits"
                    figure={totals?.visits}
                    compare={panel.compare}
                />
                <Mini icon="ti ti-user-plus" tone="blue" value={count(panel.new)} label="New Patients" figure={totals?.new} />
                <Mini
                    icon="ti ti-users-group"
                    tone="green"
                    value={count(panel.returning)}
                    label="Follow-up Patients"
                    figure={totals?.returning}
                />
            </div>

            {panel.new + panel.returning === 0 ? (
                <Nothing icon="ti ti-users">No visits recorded in this period yet.</Nothing>
            ) : (
                <>
                    <StackedColumns
                        points={panel.series.map((point) => ({
                            key: point.key,
                            label: point.label,
                            title: point.title,
                            values: [point.new, point.returning],
                        }))}
                        series={VISITS}
                        format={count}
                        axis={count}
                        height={210}
                        maxBar={14}
                        tipTotal="Total"
                        fill
                    />
                    <Legend items={VISITS} />
                </>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Top services                                                            */
/* ------------------------------------------------------------------------ */

const SERVICE_LOOK: Record<string, { color: string; icon: string }> = {
    consultation: { color: 'var(--od-blue)', icon: 'ti ti-stethoscope' },
    pharmacy: { color: 'var(--od-green)', icon: 'ti ti-pill' },
    laboratory: { color: 'var(--od-amber)', icon: 'ti ti-flask' },
    procedures: { color: 'var(--od-violet)', icon: 'ti ti-vaccine' },
    others: { color: 'var(--od-grey)', icon: 'ti ti-dots' },
};

const OTHER_SERVICE = { color: 'var(--od-grey)', icon: 'ti ti-dots' };

function TopServices({ initial }: { initial: TopServicesPanel }) {
    const [period, setPeriod] = useState<ServicePeriod>('this_month');
    const [active, setActive] = useState<string | null>(null);

    const own = period !== 'this_month';
    const query = useDashboardPanel<TopServicesPanel>('top_services', { period }, own);
    const panel = own ? (query.data ?? initial) : initial;

    const largest = Math.max(...panel.rows.map((row) => row.share), 1);
    const hover = (key: string | null) => ({
        onMouseEnter: () => setActive(key),
        onMouseLeave: () => setActive(null),
    });

    return (
        <Panel
            icon="ti ti-chart-pie"
            title="Top Services by Revenue"
            subtitle="Revenue distribution by service category"
            busy={own && query.isFetching}
            actions={<Choice value={period} onChange={setPeriod} options={PERIODS} label="Period" icon="ti ti-calendar" />}
        >
            {panel.rows.length === 0 ? (
                <Nothing icon="ti ti-chart-pie">Nothing billed in this period yet.</Nothing>
            ) : (
                <div className="od-svc">
                    <div className="od-svc-share">
                        <Ring
                            slices={panel.rows.map((row) => ({
                                key: row.key,
                                label: row.label,
                                value: row.amount,
                                color: (SERVICE_LOOK[row.key] ?? OTHER_SERVICE).color,
                            }))}
                            caption="Total Revenue"
                            format={inr}
                            active={active}
                            onActive={setActive}
                        />

                        <ul className="od-svc-legend">
                            {panel.rows.map((row) => (
                                <li key={row.key} className={active === row.key ? 'is-active' : undefined} {...hover(row.key)}>
                                    <span
                                        className="od-key is-round"
                                        style={{ background: (SERVICE_LOOK[row.key] ?? OTHER_SERVICE).color }}
                                    />
                                    <span className="od-svc-name">{row.label}</span>
                                    <span className="od-svc-pct">{row.share.toFixed(1)}%</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <ul className="od-svc-bars">
                        {panel.rows.map((row) => {
                            const look = SERVICE_LOOK[row.key] ?? OTHER_SERVICE;

                            return (
                                <li key={row.key} className={active === row.key ? 'is-active' : undefined} {...hover(row.key)}>
                                    <span className="od-svc-icon" style={{ color: look.color }} aria-hidden="true">
                                        <i className={look.icon} />
                                    </span>

                                    <span className="od-svc-track-wrap">
                                        <span className="od-svc-name">{row.label}</span>
                                        <span className="od-svc-track">
                                            <span
                                                style={{
                                                    width: `${Math.max((row.share / largest) * 100, 2)}%`,
                                                    background: look.color,
                                                }}
                                            />
                                        </span>
                                    </span>

                                    <span className="od-svc-amount">
                                        <b>{inr(row.amount)}</b>
                                        <small>{row.share.toFixed(1)}%</small>
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Profit & loss                                                           */
/* ------------------------------------------------------------------------ */

const PNL = {
    revenue: { label: 'Revenue', color: 'var(--od-blue)' },
    expenses: { label: 'Expenses', color: 'var(--od-red)' },
    profit: { label: 'Net Profit', color: 'var(--od-green)' },
};

function ProfitLoss({ initial }: { initial: ProfitLossPanel }) {
    const [period, setPeriod] = useState<PnlPeriod>('this_year');

    const own = period !== 'this_year';
    const query = useDashboardPanel<ProfitLossPanel>('profit_loss', { period }, own);
    const panel = own ? (query.data ?? initial) : initial;

    const empty = [panel.revenue, panel.expenses, panel.profit].every((figure) =>
        figure.series.every((value) => value === 0),
    );

    return (
        <Panel
            icon="ti ti-chart-histogram"
            title="Monthly Profit & Loss"
            subtitle="Revenue, expenses and net profit overview"
            busy={own && query.isFetching}
            actions={
                <Choice
                    value={period}
                    onChange={setPeriod}
                    label="Year"
                    icon="ti ti-calendar"
                    options={[
                        { value: 'this_year', label: 'This Year' },
                        { value: 'last_year', label: 'Last Year' },
                    ]}
                />
            }
        >
            <div className="od-minis">
                <Mini
                    icon="ti ti-trending-up"
                    tone="green"
                    value={inr(panel.revenue.value)}
                    label="Total Revenue"
                    figure={panel.revenue}
                />
                {/* Stock bought from suppliers — the only cost the software records. */}
                <Mini
                    icon="ti ti-wallet"
                    tone="rose"
                    value={inr(panel.expenses.value)}
                    label="Total Expenses"
                    figure={panel.expenses}
                    goodWhenUp={false}
                />
                <Mini
                    icon="ti ti-coins"
                    tone="blue"
                    value={inr(panel.profit.value)}
                    label="Net Profit"
                    figure={panel.profit}
                    loss={panel.profit.value < 0}
                />
            </div>

            {empty ? (
                <Nothing icon="ti ti-chart-line">Nothing billed or bought in this year yet.</Nothing>
            ) : (
                <>
                    <GroupedColumns
                        points={panel.months}
                        bars={[
                            { ...PNL.revenue, values: panel.revenue.series },
                            { ...PNL.expenses, values: panel.expenses.series },
                        ]}
                        line={{ ...PNL.profit, values: panel.profit.series }}
                        format={inr}
                        axis={compactInr}
                        height={210}
                        fill
                    />
                    <Legend items={[PNL.revenue, PNL.expenses, { ...PNL.profit, line: true }]} />
                </>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Pharmacy inventory                                                      */
/* ------------------------------------------------------------------------ */

const CATEGORY_COLORS = [
    'var(--od-blue)',
    'var(--od-green)',
    'var(--od-amber)',
    'var(--od-violet)',
    'var(--od-red)',
];

function PharmacyInventory({
    initial,
    branches,
}: {
    initial: PharmacyInventoryPanel;
    branches: { id: number; name: string }[];
}) {
    const [branch, setBranch] = useState('');
    const [active, setActive] = useState<string | null>(null);

    const own = branch !== '';
    const query = useDashboardPanel<PharmacyInventoryPanel>(
        'pharmacy_inventory',
        { location_id: branch || undefined },
        own,
    );
    const panel = own ? (query.data ?? initial) : initial;

    const colorOf = (key: string, index: number) =>
        key === 'others' ? 'var(--od-grey)' : CATEGORY_COLORS[index % CATEGORY_COLORS.length];

    const tiles = [
        { key: 'low', icon: 'ti ti-alert-triangle', tone: 'rose', label: 'Low Stock', value: panel.counts.low, to: '/pharmacy/stock' },
        {
            key: 'expiring',
            icon: 'ti ti-clock-hour-4',
            tone: 'amber',
            label: `Expiring (${panel.expiry_days} days)`,
            value: panel.counts.expiring,
            to: '/pharmacy/stock',
        },
        { key: 'out', icon: 'ti ti-package', tone: 'blue', label: 'Out of Stock', value: panel.counts.out, to: '/pharmacy/stock' },
        { key: 'medicines', icon: 'ti ti-pill', tone: 'green', label: 'Total Medicines', value: panel.counts.medicines },
    ];

    return (
        <Panel
            icon="ti ti-pill"
            tone="rose"
            title="Pharmacy Inventory"
            subtitle="Stock status and expiry alerts"
            busy={own && query.isFetching}
            actions={<BranchChoice value={branch} onChange={setBranch} branches={branches} />}
        >
            <div className="od-inv-tiles">
                {tiles.map((tile) => {
                    const body = (
                        <>
                            <span className={`od-mini-icon od-bg-${tile.tone}`} aria-hidden="true">
                                <i className={tile.icon} />
                            </span>
                            <span className="od-inv-tile-text">
                                <b className={`od-tone-${tile.tone}`}>{count(tile.value)}</b>
                                <small>{tile.label}</small>
                            </span>
                            {tile.to && <i className="ti ti-arrow-right od-inv-go" aria-hidden="true" />}
                        </>
                    );

                    return tile.to ? (
                        <Link key={tile.key} to={tile.to} className="od-inv-tile is-link">
                            {body}
                        </Link>
                    ) : (
                        <div key={tile.key} className="od-inv-tile">
                            {body}
                        </div>
                    );
                })}
            </div>

            <div className="od-inv-body">
                <div className="od-inv-low">
                    <h3>Low Stock Medicines</h3>

                    {panel.rows.length === 0 ? (
                        <Nothing icon="ti ti-circle-check">Every line is above its reorder level.</Nothing>
                    ) : (
                        <div className="od-table-wrap od-inv-scroll">
                            <table className="od-table">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th className="is-num is-stock">Stock</th>
                                        <th className="is-num is-min">Min. Level</th>
                                        <th className="is-status">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {panel.rows.map((row) => (
                                        <tr key={row.key}>
                                            <td>
                                                <div className="od-med">
                                                    <i className="ti ti-pill" aria-hidden="true" />
                                                    <span>
                                                        <b title={row.name}>{row.name}</b>
                                                        {row.detail && <small title={row.detail}>{row.detail}</small>}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="is-num">{count(row.stock)}</td>
                                            <td className="is-num">{count(row.min_level)}</td>
                                            <td>
                                                <span className={`od-badge is-${row.status}`}>
                                                    {row.status === 'out' ? 'Out of Stock' : 'Low'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <div className="od-inv-cats">
                    <h3>Stock by Category</h3>

                    {panel.categories.length === 0 ? (
                        <Nothing icon="ti ti-chart-donut">No stock on the shelves yet.</Nothing>
                    ) : (
                        <div className="od-inv-ring">
                            <Ring
                                slices={panel.categories.map((slice, index) => ({
                                    key: slice.key,
                                    label: slice.label,
                                    value: slice.units,
                                    color: colorOf(slice.key, index),
                                }))}
                                caption="Total Stock"
                                format={count}
                                active={active}
                                onActive={setActive}
                            />

                            <ul className="od-svc-legend">
                                {panel.categories.map((slice, index) => (
                                    <li
                                        key={slice.key}
                                        className={active === slice.key ? 'is-active' : undefined}
                                        onMouseEnter={() => setActive(slice.key)}
                                        onMouseLeave={() => setActive(null)}
                                    >
                                        <span className="od-key is-round" style={{ background: colorOf(slice.key, index) }} />
                                        <span className="od-svc-name" title={slice.label}>
                                            {slice.label}
                                        </span>
                                        <span className="od-svc-pct">{Math.round(slice.share)}%</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Branch activity                                                         */
/* ------------------------------------------------------------------------ */

/** Records known by a number — a bill, a pharmacy sale. */
const NUMBERED = ['Invoice', 'PharmacySale'];

function BranchActivity({ entries, isBranch }: { entries: BranchActivityEntry[]; isBranch: boolean }) {
    return (
        <Panel
            icon="ti ti-history"
            tone="ink"
            title={isBranch ? 'Recent Activities' : 'Recent Branch Activities'}
            subtitle={isBranch ? 'What has just happened at this branch' : 'What has just happened across the branches'}
            actions={<ViewAll to="/activity" />}
        >
            {entries.length === 0 ? (
                <Nothing icon="ti ti-history">Nothing has happened here yet.</Nothing>
            ) : (
                <ActivityFeed
                    items={entries.map((entry) => {
                        // A bill or a sale is named by its number, in the title.
                        const numbered = NUMBERED.includes(entry.entity_type) && !!entry.detail;

                        return feedItemFromLog({
                            ...entry,
                            text: numbered ? `${entry.text} #${entry.detail!.replace(/^#/, '')}` : entry.text,
                            detail: [
                                numbered ? null : entry.detail,
                                // On a branch's own screen every entry is at that branch.
                                isBranch ? null : entry.branch,
                                entry.amount != null ? inr(entry.amount) : null,
                            ],
                        });
                    })}
                />
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Follow-ups                                                              */
/* ------------------------------------------------------------------------ */

type FollowUpState = 'overdue' | 'today' | 'upcoming';

const FOLLOW_UP_STATE: Record<FollowUpState, { label: string; tone: string }> = {
    overdue: { label: 'Overdue', tone: 'rose' },
    today: { label: 'Due Today', tone: 'amber' },
    upcoming: { label: 'Upcoming', tone: 'blue' },
};

function stateOf(row: FollowUpRow): FollowUpState {
    return row.due_in < 0 ? 'overdue' : row.due_in === 0 ? 'today' : 'upcoming';
}

function FollowUps({ panel }: { panel: FollowUpsPanel }) {
    const canContact = useCanContact();

    return (
        <Panel
            icon="ti ti-calendar-event"
            title="Upcoming Follow-ups"
            subtitle="Patients asked back who have not come yet"
        >
            {panel.rows.length === 0 ? (
                <Nothing icon="ti ti-calendar-check">Nobody is due back in the next two weeks.</Nothing>
            ) : (
                <div className="od-table-wrap">
                    <table className="od-table od-fu">
                        <thead>
                            <tr>
                                <th>Due Date</th>
                                <th>Patient</th>
                                <th>Last Visit</th>
                                <th>Follow-up In</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {panel.rows.map((row) => {
                                const state = stateOf(row);

                                return (
                                    <tr key={row.id}>
                                        <td>
                                            <div className="od-fu-due">
                                                <b>{shortDate(row.due_on)}</b>
                                                <small className={`is-${state}`}>
                                                    {state === 'overdue'
                                                        ? `${plural(-row.due_in, 'day')} late`
                                                        : state === 'today'
                                                          ? 'Today'
                                                          : `In ${plural(row.due_in, 'day')}`}
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <PatientRecordLink className="od-fu-who" customerId={row.customer_id}>
                                                <b>{row.name ?? 'Unnamed'}</b>
                                                {row.code && <small>{row.code}</small>}
                                            </PatientRecordLink>
                                        </td>
                                        <td className="od-fu-dim">{shortDate(row.seen_on)}</td>
                                        <td className="od-fu-dim">after {plural(row.days, 'day')}</td>
                                        <td>
                                            {row.reason ? (
                                                <span className="od-fu-reason" title={row.reason}>
                                                    {row.reason}
                                                </span>
                                            ) : (
                                                <span className="od-muted">—</span>
                                            )}
                                        </td>
                                        <td>
                                            <span className={`od-fu-state is-${FOLLOW_UP_STATE[state].tone}`}>
                                                {FOLLOW_UP_STATE[state].label}
                                            </span>
                                        </td>
                                        <td>
                                            {canContact && <Contact name={row.name} phone={row.phone} />}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

/**
 * Call or copy the patient's number. A small menu fixed to the viewport
 * under its button, so the table's own scrolling frame cannot clip it.
 */
function Contact({ name, phone }: { name: string | null; phone: string | null }) {
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const [copied, setCopied] = useState(false);
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!at) return;

        const close = () => setAt(null);
        const outside = (event: MouseEvent) => {
            const target = event.target as Node;

            if (!button.current?.contains(target) && !menu.current?.contains(target)) close();
        };
        const escape = (event: KeyboardEvent) => event.key === 'Escape' && close();

        document.addEventListener('mousedown', outside);
        document.addEventListener('keydown', escape);
        window.addEventListener('scroll', close, true);

        return () => {
            document.removeEventListener('mousedown', outside);
            document.removeEventListener('keydown', escape);
            window.removeEventListener('scroll', close, true);
        };
    }, [at]);

    if (!phone) {
        return (
            <button type="button" className="od-fu-contact" disabled title="No phone number on file">
                <i className="ti ti-phone-off" aria-hidden="true" />
                Contact
            </button>
        );
    }

    const toggle = () => {
        const rect = button.current?.getBoundingClientRect();

        setAt((was) => (was || !rect ? null : { top: rect.bottom + 6, right: window.innerWidth - rect.right }));
    };

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(phone);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard refused; the number is on screen to read off.
        }
    };

    return (
        <>
            <button
                ref={button}
                type="button"
                className="od-fu-contact"
                aria-haspopup="menu"
                aria-expanded={at !== null}
                onClick={toggle}
            >
                <i className="ti ti-phone" aria-hidden="true" />
                Contact
            </button>

            {at && (
                <div
                    ref={menu}
                    className="dropdown-menu show mt-0 od-fu-menu"
                    role="menu"
                    style={{ position: 'fixed', top: at.top, right: at.right, left: 'auto' }}
                >
                    <div className="od-fu-menu-head">
                        <small>{name ?? 'Patient'}</small>
                        <b>{phone}</b>
                    </div>

                    <a className="dropdown-item" role="menuitem" href={`tel:${phone}`} onClick={() => setAt(null)}>
                        <i className="ti ti-phone-call me-2" aria-hidden="true" />
                        Call
                    </a>

                    <button type="button" className="dropdown-item" role="menuitem" onClick={copy}>
                        <i className={`ti me-2 ${copied ? 'ti-check' : 'ti-copy'}`} aria-hidden="true" />
                        {copied ? 'Copied' : 'Copy number'}
                    </button>
                </div>
            )}
        </>
    );
}
