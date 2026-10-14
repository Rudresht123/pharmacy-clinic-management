import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { DateRangePicker } from '@/core/billing/components/BillingWidgets';
import { formatDate, formatRelative } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useDashboardPanel,
    type BranchActivityEntry,
    type BranchPerformancePanel,
    type DashboardRange,
    type DashboardSummary,
    type Granularity,
    type KpiCard,
    type NetworkOverview,
    type PlanPanel,
    type PnlFigure,
    type PnlPeriod,
    type ProfitLossPanel,
    type RevenueOverviewPanel,
    type ServicePeriod,
    type StockAlertsPanel,
    type TopServicesPanel,
    type VisitTrendsPanel,
} from '../api';
import {
    compactInr,
    count,
    inr,
    LineChart,
    Ring,
    StackedColumns,
    TrendCurve,
} from '@/shared/components/ui/ReportCharts';

/**
 * Head office's dashboard — the whole network on one screen.
 *
 * Laid out after the reference design, section for section: the banner and
 * plan, five figures, revenue beside branch performance, then visits,
 * services and stock, then profit and loss, activity and quick actions.
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

    const branches = data.scope.options ?? [];
    const compare = data.scope.window?.compare ?? 'vs last month';

    const rowTwo = [
        data.revenue_overview && (
            <RevenueOverview key="revenue" initial={data.revenue_overview} range={range} branches={branches} />
        ),
        data.branch_performance && <BranchPerformance key="branches" panel={data.branch_performance} />,
    ].filter(Boolean);

    const rowThree = [
        data.visit_trends && (
            <VisitTrends key="visits" initial={data.visit_trends} range={range} branches={branches} />
        ),
        data.top_services && <TopServices key="services" initial={data.top_services} />,
        data.stock_alerts && <StockAlerts key="stock" panel={data.stock_alerts} />,
    ].filter(Boolean);

    const actions = quickActions(data, isOwner);

    const rowFour = [
        data.profit_loss && <ProfitLoss key="pnl" initial={data.profit_loss} />,
        data.branch_activity && <BranchActivity key="activity" entries={data.branch_activity} />,
        actions.length > 0 && <QuickActions key="actions" actions={actions} />,
    ].filter(Boolean);

    return (
        <div className={`od-page${refreshing ? ' is-refreshing' : ''}`}>
            <header className="od-greeting">
                <div>
                    <h1>
                        {greeting()}, {user?.name ?? 'there'} <span aria-hidden="true">👋</span>
                    </h1>
                    <p>
                        {data.scope.branches === null
                            ? 'Here’s what’s happening across all your branches'
                            : `Here’s what’s happening across ${data.scope.label}`}
                    </p>
                </div>

                <DateRangePicker value={range} onChange={onRange} />
            </header>

            <div className={`od-hero${data.plan ? '' : ' is-single'}`}>
                <Banner
                    name={organization?.name ?? 'Your organisation'}
                    logo={organization?.has_logo ? organization.logo_url : null}
                    overview={data.overview}
                />

                {data.plan && <Plan plan={data.plan} isOwner={isOwner} />}
            </div>

            {data.kpis && data.kpis.length > 0 && <Kpis cards={data.kpis} compare={compare} />}

            {rowTwo.length > 0 && <div className={`od-row od-row-2 is-${rowTwo.length}`}>{rowTwo}</div>}
            {rowThree.length > 0 && <div className={`od-row od-row-3 is-${rowThree.length}`}>{rowThree}</div>}
            {rowFour.length > 0 && <div className={`od-row od-row-3 is-${rowFour.length}`}>{rowFour}</div>}
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

/** One card: a coloured icon, a title, whatever controls it carries, and its body. */
function Panel({
    icon,
    tone = 'blue',
    title,
    actions,
    busy,
    className,
    children,
}: {
    icon: string;
    tone?: string;
    title: string;
    actions?: ReactNode;
    busy?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section className={`od-card${className ? ` ${className}` : ''}`} aria-busy={busy || undefined}>
            <header className="od-card-head">
                <h2>
                    <i className={`${icon} od-tone-${tone}`} aria-hidden="true" />
                    {title}
                </h2>

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
}: {
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
    label: string;
}) {
    return (
        <span className="od-select">
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

/* ------------------------------------------------------------------------ */
/*  Banner and plan                                                         */
/* ------------------------------------------------------------------------ */

function Banner({ name, logo, overview }: { name: string; logo: string | null; overview?: NetworkOverview }) {
    const facts = [
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
    range,
    branches,
}: {
    initial: VisitTrendsPanel;
    range: DashboardRange;
    branches: { id: number; name: string }[];
}) {
    const [branch, setBranch] = useState('');

    const own = branch !== '';
    const query = useDashboardPanel<VisitTrendsPanel>('visit_trends', { ...range, location_id: branch || undefined }, own);
    const panel = own ? (query.data ?? initial) : initial;

    return (
        <Panel
            icon="ti ti-users"
            title="Patient Visit Trends"
            busy={own && query.isFetching}
            actions={<BranchChoice value={branch} onChange={setBranch} branches={branches} />}
        >
            <ul className="od-legend">
                {VISITS.map((series) => (
                    <li key={series.label}>
                        <span className="od-key" style={{ background: series.color }} />
                        {series.label}
                    </li>
                ))}
            </ul>

            {panel.new + panel.returning === 0 ? (
                <Nothing icon="ti ti-users">No visits recorded in this period yet.</Nothing>
            ) : (
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
                    height={220}
                    maxBar={14}
                />
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Top services                                                            */
/* ------------------------------------------------------------------------ */

const SERVICE_COLOR: Record<string, string> = {
    consultation: 'var(--od-blue)',
    pharmacy: 'var(--od-green)',
    laboratory: 'var(--od-amber)',
    procedures: 'var(--od-violet)',
    others: 'var(--od-grey)',
};

const PERIODS: { value: ServicePeriod; label: string }[] = [
    { value: 'this_month', label: 'This Month' },
    { value: 'last_month', label: 'Last Month' },
    { value: 'this_year', label: 'This Year' },
];

function TopServices({ initial }: { initial: TopServicesPanel }) {
    const [period, setPeriod] = useState<ServicePeriod>('this_month');
    const [active, setActive] = useState<string | null>(null);

    const own = period !== 'this_month';
    const query = useDashboardPanel<TopServicesPanel>('top_services', { period }, own);
    const panel = own ? (query.data ?? initial) : initial;

    return (
        <Panel
            icon="ti ti-chart-pie"
            title="Top Services by Revenue"
            busy={own && query.isFetching}
            actions={<Choice value={period} onChange={setPeriod} options={PERIODS} label="Period" />}
        >
            {panel.rows.length === 0 ? (
                <Nothing icon="ti ti-chart-pie">Nothing billed in this period yet.</Nothing>
            ) : (
                <div className="od-services">
                    <Ring
                        slices={panel.rows.map((row) => ({
                            key: row.key,
                            label: row.label,
                            value: row.amount,
                            color: SERVICE_COLOR[row.key] ?? 'var(--od-grey)',
                        }))}
                        caption="Total Revenue"
                        format={inr}
                        active={active}
                        onActive={setActive}
                    />

                    <ul className="od-service-list">
                        {panel.rows.map((row) => (
                            <li
                                key={row.key}
                                className={active === row.key ? 'is-active' : undefined}
                                onMouseEnter={() => setActive(row.key)}
                                onMouseLeave={() => setActive(null)}
                            >
                                <span className="od-key" style={{ background: SERVICE_COLOR[row.key] ?? 'var(--od-grey)' }} />
                                <span className="od-service-name">{row.label}</span>
                                <span className="od-service-share">{Math.round(row.share)}%</span>
                                <b>{inr(row.amount)}</b>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Stock alerts                                                            */
/* ------------------------------------------------------------------------ */

const STOCK_BADGE: Record<string, string> = {
    expired: 'Expired',
    out: 'Out of Stock',
    low: 'Low Stock',
};

function StockAlerts({ panel }: { panel: StockAlertsPanel }) {
    return (
        <Panel
            icon="ti ti-pill"
            tone="rose"
            title="Pharmacy Stock Alerts"
            actions={<ViewAll to="/pharmacy/stock" />}
        >
            {panel.rows.length === 0 ? (
                <Nothing icon="ti ti-circle-check">Every shelf is stocked and in date.</Nothing>
            ) : (
                <ul className="od-stock">
                    {panel.rows.map((row) => (
                        <li key={row.key}>
                            <span className={`od-stock-mark is-${row.status}`}>
                                <i className="ti ti-pill" aria-hidden="true" />
                            </span>

                            <span className="od-stock-text">
                                <b title={row.name}>{row.name}</b>
                                {row.status === 'expired' ? (
                                    <small className="is-expired">Expired on {formatDate(row.expiry_date)}</small>
                                ) : (
                                    <small title={row.branch ?? undefined}>
                                        Stock: {count(row.quantity)} {row.unit}
                                        {row.branch && <> · {row.branch}</>}
                                    </small>
                                )}
                            </span>

                            <span className={`od-badge is-${row.status}`}>{STOCK_BADGE[row.status]}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Profit & loss                                                           */
/* ------------------------------------------------------------------------ */

function ProfitLoss({ initial }: { initial: ProfitLossPanel }) {
    const [period, setPeriod] = useState<PnlPeriod>('this_year');

    const own = period !== 'this_year';
    const query = useDashboardPanel<ProfitLossPanel>('profit_loss', { period }, own);
    const panel = own ? (query.data ?? initial) : initial;

    // Profit in the theme's blue, so it never reads as a second revenue line.
    const figures: { key: string; label: string; figure: PnlFigure; color: string; cost?: boolean }[] = [
        { key: 'revenue', label: 'Total Revenue', figure: panel.revenue, color: 'var(--od-green)' },
        { key: 'expenses', label: 'Total Expenses', figure: panel.expenses, color: 'var(--od-red)', cost: true },
        { key: 'profit', label: 'Net Profit', figure: panel.profit, color: 'var(--od-blue)' },
    ];

    const empty = figures.every(({ figure }) => figure.series.every((value) => value === 0));

    return (
        <Panel
            icon="ti ti-chart-histogram"
            title="Monthly Profit & Loss"
            busy={own && query.isFetching}
            actions={
                <Choice
                    value={period}
                    onChange={setPeriod}
                    label="Year"
                    options={[
                        { value: 'this_year', label: 'This Year' },
                        { value: 'last_year', label: 'Last Year' },
                    ]}
                />
            }
        >
            {/* The three totals, each keyed to its line in the chart below. */}
            <div className="od-pnl">
                {figures.map(({ key, label, figure, color, cost }) => (
                    <div
                        className="od-pnl-figure"
                        key={key}
                        title={cost ? 'Stock bought from suppliers — the costs the software records' : undefined}
                    >
                        <span className="od-pnl-label">
                            <span className="od-key is-round" style={{ background: color }} />
                            {label}
                        </span>
                        <b className={key === 'profit' && figure.value < 0 ? 'is-loss' : undefined}>{inr(figure.value)}</b>
                        <Change change={figure.change} goodWhenUp={!cost} />
                    </div>
                ))}
            </div>

            {empty ? (
                <Nothing icon="ti ti-chart-line">Nothing billed or bought in this year yet.</Nothing>
            ) : (
                <LineChart
                    points={panel.months}
                    series={figures.map(({ label, figure, color }) => ({ label, color, values: figure.series }))}
                    format={inr}
                    axis={compactInr}
                    height={210}
                />
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Branch activity                                                         */
/* ------------------------------------------------------------------------ */

const ACTIVITY_LOOK: Record<string, { icon: string; tone: string }> = {
    Customer: { icon: 'ti ti-user-plus', tone: 'blue' },
    Doctor: { icon: 'ti ti-stethoscope', tone: 'green' },
    Location: { icon: 'ti ti-building-hospital', tone: 'violet' },
    Appointment: { icon: 'ti ti-calendar-check', tone: 'amber' },
    Invoice: { icon: 'ti ti-chart-line', tone: 'blue' },
    BillableService: { icon: 'ti ti-tag', tone: 'violet' },
    PharmacySale: { icon: 'ti ti-pill', tone: 'rose' },
    PharmacySaleReturn: { icon: 'ti ti-arrow-back-up', tone: 'rose' },
    StockInward: { icon: 'ti ti-package', tone: 'rose' },
    StockTransfer: { icon: 'ti ti-truck', tone: 'amber' },
    StockAdjustment: { icon: 'ti ti-adjustments', tone: 'amber' },
    BranchMembership: { icon: 'ti ti-id-badge-2', tone: 'green' },
    Role: { icon: 'ti ti-shield', tone: 'grey' },
};

function BranchActivity({ entries }: { entries: BranchActivityEntry[] }) {
    return (
        <Panel icon="ti ti-history" tone="ink" title="Recent Branch Activities" actions={<ViewAll to="/activity" />}>
            {entries.length === 0 ? (
                <Nothing icon="ti ti-history">Nothing has happened across the branches yet.</Nothing>
            ) : (
                <ul className="od-timeline">
                    {entries.map((entry) => {
                        const look = ACTIVITY_LOOK[entry.entity_type] ?? { icon: 'ti ti-point', tone: 'grey' };

                        return (
                            <li key={entry.id}>
                                <span className={`od-timeline-mark od-bg-${look.tone}`}>
                                    <i className={look.icon} aria-hidden="true" />
                                </span>

                                <span className="od-timeline-text">
                                    <span>{entry.text}</span>
                                    {entry.detail && <small>{entry.detail}</small>}
                                </span>

                                <time dateTime={entry.created_at ?? undefined}>{formatRelative(entry.created_at)}</time>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Quick actions                                                           */
/* ------------------------------------------------------------------------ */

interface QuickAction {
    to: string;
    icon: string;
    label: string;
}

/**
 * Where head office goes next. Each tile is offered only when the screen it
 * opens would let this person in — the panels that arrived say which.
 */
function quickActions(summary: DashboardSummary, isOwner: boolean): QuickAction[] {
    return [
        (summary.profit_loss || summary.stock_alerts) && {
            to: summary.profit_loss ? '/billing/finance' : '/pharmacy/reports',
            icon: 'ti ti-chart-bar',
            label: 'View Reports',
        },
        summary.branch_performance && {
            to: '/locations',
            icon: 'ti ti-building-hospital',
            label: 'Manage Branches',
        },
        isOwner && {
            to: '/setup',
            icon: 'ti ti-settings',
            label: 'Organization Settings',
        },
    ].filter(Boolean) as QuickAction[];
}

function QuickActions({ actions }: { actions: QuickAction[] }) {
    return (
        <Panel icon="ti ti-bolt" title="Quick Actions">
            <div className="od-actions">
                {actions.map((action) => (
                    <Link key={action.to} to={action.to} className="od-action">
                        <i className={action.icon} aria-hidden="true" />
                        <span>{action.label}</span>
                        <i className="ti ti-arrow-right od-action-go" aria-hidden="true" />
                    </Link>
                ))}
            </div>
        </Panel>
    );
}
