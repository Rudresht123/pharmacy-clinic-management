import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { compactInr, count, inr, Ring, StackedColumns } from '@/shared/components/ui/ReportCharts';
import { formatDate } from '@/shared/utils/format';
import type {
    EnquiriesPanel,
    EnquiryStatus,
    EnquiryType,
    LocationsPanel,
    ModuleUsagePanel,
    OrganizationBucket,
    OrganizationRow,
    PlatformActivity,
    PlatformDashboardData,
    PlatformKpi,
    PlanKey,
    RevenueTrendPanel,
    Slice,
    SubscriptionRevenuePanel,
    TopOrganization,
} from '../platform-api';
import { IndiaMap } from './IndiaMap';
import { REGIONS, regionOf } from './regions';

/**
 * The super admin dashboard — the platform on one screen.
 *
 * Laid out after the reference design: six figures, then revenue beside the
 * plan and status rings, three lists, and subscriptions, modules, locations
 * and activity along the bottom.
 *
 * Real figures only (App\Services\Platform\PlatformDashboard). Every card is
 * always drawn; one whose panel arrived null — revenue, plans, anything not
 * yet recorded on the platform — shows an empty state in its place, so the
 * layout is the same today as the day the data arrives.
 */
export function PlatformDashboard({ data }: { data: PlatformDashboardData }) {
    return (
        <>
            <Kpis cards={data.kpis} compare={data.window.compare} />

            <div className="sa-row sa-row-charts is-3">
                <RevenueTrend panel={data.revenue_trend} />
                <ByPlan slices={data.by_plan} />
                <ByStatus slices={data.by_status} />
            </div>

            <div className="sa-row is-3">
                <NewOrganizations rows={data.new_organizations} />
                <TopOrganizations rows={data.top_organizations} />
                <RecentSignups rows={data.recent_signups} />
            </div>

            {/* Leads waiting on a reply — high on the page because they need action. */}
            <Enquiries panel={data.enquiries} compare={data.window.compare} />

            <div className="sa-row is-2">
                <SubscriptionRevenue panel={data.subscription_revenue} />
                <ModuleUsage panel={data.module_usage} />
            </div>

            {/* The map gets the wider half — it needs the room to read. */}
            <div className="sa-row sa-row-map is-2">
                <Locations panel={data.locations} />
                <RecentActivity entries={data.activity} />
            </div>
        </>
    );
}

/* ------------------------------------------------------------------------ */
/*  Shared pieces                                                           */
/* ------------------------------------------------------------------------ */

/** One card: an icon in a tinted tile, a title, its controls, and its body. */
function Panel({
    icon,
    tone = 'blue',
    title,
    actions,
    children,
}: {
    icon: string;
    tone?: string;
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="od-card sa-card">
            <header className="od-card-head">
                <h2>
                    <span className={`sa-card-icon od-bg-${tone}`}>
                        <i className={icon} aria-hidden="true" />
                    </span>
                    {title}
                </h2>

                {actions && <div className="od-card-actions">{actions}</div>}
            </header>

            <div className="od-card-body">{children}</div>
        </section>
    );
}

function ViewAll({ to }: { to: string }) {
    return (
        <Link to={to} className="sa-view-all">
            View all
        </Link>
    );
}

/** Last 6 or 12 months — the charts carry a year and show the end of it. */
function MonthsChoice({ value, onChange }: { value: number; onChange: (value: number) => void }) {
    return (
        <span className="od-select">
            <select value={value} onChange={(event) => onChange(Number(event.target.value))} aria-label="Period">
                <option value={6}>Last 6 months</option>
                <option value={12}>Last 12 months</option>
            </select>
            <i className="ti ti-chevron-down" aria-hidden="true" />
        </span>
    );
}

/** A change against the period before, arrow and colour together. */
function Change({ change }: { change: number | null }) {
    if (change === null) {
        return null;
    }

    const tone = change > 0 ? ' is-good' : change < 0 ? ' is-bad' : '';
    const arrow = change > 0 ? 'ti ti-arrow-up' : change < 0 ? 'ti ti-arrow-down' : 'ti ti-minus';

    return (
        <em className={`od-change${tone}`}>
            <i className={arrow} aria-hidden="true" />
            {Math.abs(change)}%
        </em>
    );
}

function Nothing({ icon, children }: { icon: string; children: ReactNode }) {
    return (
        <div className="od-nothing">
            <i className={icon} aria-hidden="true" />
            <p>{children}</p>
        </div>
    );
}

/** "₹16L" — the axis in the units the figures are quoted in. */
function rupeeAxis(value: number): string {
    return value === 0 ? '₹0' : `₹${compactInr(value)}`;
}

const STATUS_LABEL: Record<OrganizationBucket, string> = {
    active: 'Active',
    trial: 'Trial',
    setting_up: 'Setting Up',
    suspended: 'Suspended',
    cancelled: 'Cancelled',
    failed: 'Failed',
};

/** The word and its colour together — a status is never colour alone. */
function Status({ status }: { status: OrganizationBucket }) {
    return <span className={`sa-status is-${status}`}>{STATUS_LABEL[status] ?? status}</span>;
}

/** A linked name where there is an organization to open, plain text where there is not. */
function OrganizationName({ row }: { row: OrganizationRow }) {
    return row.uuid ? (
        <Link to={`/organizations/${row.uuid}`} className="sa-org-link">
            {row.name}
        </Link>
    ) : (
        <span className="sa-org-name">{row.name}</span>
    );
}

/* ------------------------------------------------------------------------ */
/*  The six figures                                                         */
/* ------------------------------------------------------------------------ */

const KPI_LOOK: Record<PlatformKpi['key'], { icon: string; tone: string }> = {
    organizations: { icon: 'ti ti-building', tone: 'blue' },
    active: { icon: 'ti ti-circle-check', tone: 'green' },
    joined: { icon: 'ti ti-circle-plus', tone: 'violet' },
    awaiting: { icon: 'ti ti-hourglass', tone: 'amber' },
    suspended: { icon: 'ti ti-ban', tone: 'rose' },
    modules: { icon: 'ti ti-box', tone: 'teal' },
};

function Kpis({ cards, compare }: { cards: PlatformKpi[]; compare: string }) {
    return (
        <div className={`sa-kpis is-${cards.length}`}>
            {cards.map((card) => {
                const look = KPI_LOOK[card.key] ?? { icon: 'ti ti-chart-dots', tone: 'blue' };

                return (
                    <article className={`sa-kpi${card.value === null ? ' is-empty' : ''}`} key={card.key}>
                        <span className={`sa-kpi-icon od-bg-${look.tone}`}>
                            <i className={look.icon} aria-hidden="true" />
                        </span>

                        <div className="sa-kpi-text">
                            <b>
                                {card.value === null
                                    ? '—'
                                    : card.money
                                      ? inr(card.value).replace('₹ ', '₹')
                                      : count(card.value)}
                            </b>
                            <span className="sa-kpi-label">{card.label}</span>

                            {card.value === null ? (
                                <span className="sa-kpi-change">No data yet</span>
                            ) : card.change !== null ? (
                                <span className="sa-kpi-change">
                                    <Change change={card.change} />
                                    <span>{compare}</span>
                                </span>
                            ) : (
                                card.hint && <span className="sa-kpi-change">{card.hint}</span>
                            )}
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------------ */
/*  Revenue trend                                                           */
/* ------------------------------------------------------------------------ */

function RevenueTrend({ panel }: { panel: RevenueTrendPanel | null }) {
    const [months, setMonths] = useState(6);

    if (!panel || panel.mrr.every((value) => value === 0)) {
        return (
            <Panel icon="ti ti-chart-bar" title="Revenue Trend">
                <Nothing icon="ti ti-chart-bar">No subscription revenue recorded yet.</Nothing>
            </Panel>
        );
    }

    const mrr = panel.mrr.slice(-months);
    const added = panel.new_subscriptions.slice(-months);

    return (
        <Panel
            icon="ti ti-chart-bar"
            title="Revenue Trend"
            actions={<MonthsChoice value={months} onChange={setMonths} />}
        >
            <ul className="od-legend">
                <li>
                    <span className="od-key" style={{ background: 'var(--od-blue)' }} />
                    MRR
                </li>
                <li>
                    <span className="sa-key-line" style={{ color: 'var(--od-green)' }} />
                    New Subscriptions
                </li>
            </ul>

            <StackedColumns
                points={panel.months.slice(-months).map((month, index) => ({
                    ...month,
                    values: [mrr[index] ?? 0],
                }))}
                series={[{ label: 'MRR', color: 'var(--od-blue)' }]}
                overlay={{ label: 'New Subscriptions', color: 'var(--od-green)', values: added }}
                format={inr}
                axis={rupeeAxis}
                height={210}
                maxBar={30}
            />
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  The two rings                                                           */
/* ------------------------------------------------------------------------ */

/* Plans are identity, so they take the categorical hues in fixed order. */
const PLAN_COLOR: Record<string, string> = {
    basic: 'var(--cat-1)',
    standard: 'var(--cat-2)',
    professional: 'var(--cat-3)',
    enterprise: 'var(--cat-4)',
};

/* Statuses are state, so they take the reserved status colours. */
const STATUS_COLOR: Record<string, string> = {
    active: 'var(--sa-good)',
    trial: 'var(--cat-1)',
    setting_up: 'var(--sa-warn)',
    suspended: 'var(--od-grey)',
    cancelled: 'var(--sa-bad)',
    failed: 'var(--od-rose)',
};

function ByPlan({ slices }: { slices: Slice[] | null }) {
    return (
        <Panel icon="ti ti-building-community" tone="violet" title="Active Organizations by Plan">
            <RingWithLegend slices={slices ?? []} colors={PLAN_COLOR} empty="No subscription plans set up yet." />
        </Panel>
    );
}

function ByStatus({ slices }: { slices: Slice[] }) {
    return (
        <Panel icon="ti ti-target" title="Organizations by Status">
            <RingWithLegend slices={slices} colors={STATUS_COLOR} empty="No organizations yet." />
        </Panel>
    );
}

/**
 * The ring, and beside it every slice's name, count and share — so the
 * chart is never read by colour alone, and a zero still says it is zero.
 */
function RingWithLegend({ slices, colors, empty }: { slices: Slice[]; colors: Record<string, string>; empty: string }) {
    const [active, setActive] = useState<string | null>(null);

    const total = slices.reduce((sum, slice) => sum + slice.total, 0);

    if (total === 0) {
        return <Nothing icon="ti ti-chart-donut">{empty}</Nothing>;
    }

    const color = (key: string) => colors[key] ?? 'var(--od-grey)';

    return (
        <div className="od-services sa-ring">
            <Ring
                // An empty slice would still draw a sliver; it lives in the legend only.
                slices={slices
                    .filter((slice) => slice.total > 0)
                    .map((slice) => ({ key: slice.key, label: slice.label, value: slice.total, color: color(slice.key) }))}
                caption="Organizations"
                format={count}
                active={active}
                onActive={setActive}
            />

            <ul className="od-service-list sa-ring-list">
                {slices.map((slice) => (
                    <li
                        key={slice.key}
                        className={active === slice.key ? 'is-active' : undefined}
                        onMouseEnter={() => slice.total > 0 && setActive(slice.key)}
                        onMouseLeave={() => setActive(null)}
                    >
                        <span className="od-key" style={{ background: color(slice.key) }} />
                        <span className="od-service-name">{slice.label}</span>
                        <b>{count(slice.total)}</b>
                        <span className="od-service-share">({Math.round((slice.total / total) * 100)}%)</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/* ------------------------------------------------------------------------ */
/*  Lists                                                                   */
/* ------------------------------------------------------------------------ */

/**
 * Plan where the platform knows it, otherwise the organization's type —
 * the column is about what kind of customer this is either way.
 */
function kindColumn(rows: OrganizationRow[]): { label: string; value: (row: OrganizationRow) => string } | null {
    if (rows.some((row) => row.plan)) {
        return { label: 'Plan', value: (row) => row.plan ?? '—' };
    }

    if (rows.some((row) => row.type)) {
        return { label: 'Type', value: (row) => row.type ?? '—' };
    }

    return null;
}

function NewOrganizations({ rows }: { rows: OrganizationRow[] }) {
    const kind = kindColumn(rows);
    const showClinics = rows.some((row) => row.clinics !== null);

    return (
        <Panel icon="ti ti-building" title="New Organizations" actions={<ViewAll to="/organizations" />}>
            {rows.length === 0 ? (
                <Nothing icon="ti ti-building">No organizations yet.</Nothing>
            ) : (
                <div className="od-table-wrap">
                    <table className="od-table sa-table">
                        <thead>
                            <tr>
                                <th className="is-rank">#</th>
                                <th>Organization Name</th>
                                {kind && <th>{kind.label}</th>}
                                {showClinics && <th className="is-num">Clinics</th>}
                                <th>Joined On</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => (
                                <tr key={row.uuid ?? row.name}>
                                    <td className="is-rank">
                                        <span>{index + 1}</span>
                                    </td>
                                    <td>
                                        <OrganizationName row={row} />
                                    </td>
                                    {kind && <td>{kind.value(row)}</td>}
                                    {showClinics && <td className="is-num">{row.clinics ?? '—'}</td>}
                                    <td className="sa-nowrap">{formatDate(row.joined_on)}</td>
                                    <td>
                                        <Status status={row.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

function TopOrganizations({ rows }: { rows: TopOrganization[] | null }) {
    return (
        <Panel icon="ti ti-trophy" tone="amber" title="Top Performing Organizations">
            {!rows || rows.length === 0 ? (
                <Nothing icon="ti ti-trophy">No revenue recorded per organization yet.</Nothing>
            ) : (
                <div className="od-table-wrap">
                    <table className="od-table sa-table">
                        <thead>
                            <tr>
                                <th className="is-rank">#</th>
                                <th>Organization Name</th>
                                <th className="is-num">Clinics</th>
                                <th className="is-num">Revenue</th>
                                <th className="is-num">Growth</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => (
                                <tr key={row.name}>
                                    <td className="is-rank">
                                        <span>{index + 1}</span>
                                    </td>
                                    <td>
                                        <span className="sa-org-name">{row.name}</span>
                                    </td>
                                    <td className="is-num">{count(row.clinics)}</td>
                                    <td className="is-num">{inr(row.revenue).replace('₹ ', '₹')}</td>
                                    <td className="is-num">
                                        {row.growth === null ? <span className="od-muted">—</span> : <Change change={row.growth} />}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

/** "31 Oct 10:12 AM". */
function dayAndTime(iso: string | null | undefined): string {
    if (!iso) return '—';

    const date = new Date(iso);
    const day = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    const time = date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

    return `${day} ${time}`;
}

function RecentSignups({ rows }: { rows: OrganizationRow[] }) {
    const kind = kindColumn(rows);

    return (
        <Panel icon="ti ti-user-plus" title="Recent Signups" actions={<ViewAll to="/organizations" />}>
            {rows.length === 0 ? (
                <Nothing icon="ti ti-user-plus">No owner has finished signing up yet.</Nothing>
            ) : (
                <div className="od-table-wrap">
                    <table className="od-table sa-table">
                        <thead>
                            <tr>
                                <th>Date &amp; time</th>
                                <th>Organization</th>
                                {kind && <th>{kind.label}</th>}
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.uuid ?? row.name}>
                                    <td className="sa-nowrap">{dayAndTime(row.signed_up_at)}</td>
                                    <td>
                                        <OrganizationName row={row} />
                                    </td>
                                    {kind && <td>{kind.value(row)}</td>}
                                    <td>
                                        <Status status={row.status} />
                                    </td>
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
/*  Subscription revenue                                                    */
/* ------------------------------------------------------------------------ */

const PLANS: { key: PlanKey; label: string }[] = [
    { key: 'basic', label: 'Basic' },
    { key: 'standard', label: 'Standard' },
    { key: 'professional', label: 'Professional' },
    { key: 'enterprise', label: 'Enterprise' },
];

function SubscriptionRevenue({ panel }: { panel: SubscriptionRevenuePanel | null }) {
    const [months, setMonths] = useState(6);

    if (!panel || Object.values(panel.series).every((values) => values.every((value) => value === 0))) {
        return (
            <Panel icon="ti ti-currency-rupee" tone="green" title="Subscription Revenue">
                <Nothing icon="ti ti-currency-rupee">No subscription revenue recorded yet.</Nothing>
            </Panel>
        );
    }

    const from = panel.months.length - months;

    return (
        <Panel
            icon="ti ti-currency-rupee"
            tone="green"
            title="Subscription Revenue"
            actions={<MonthsChoice value={months} onChange={setMonths} />}
        >
            <ul className="od-legend">
                {PLANS.map((plan) => (
                    <li key={plan.key}>
                        <span className="od-key is-round" style={{ background: PLAN_COLOR[plan.key] }} />
                        {plan.label}
                    </li>
                ))}
            </ul>

            <StackedColumns
                points={panel.months.slice(-months).map((month, index) => ({
                    ...month,
                    values: PLANS.map((plan) => panel.series[plan.key][from + index] ?? 0),
                }))}
                series={PLANS.map((plan) => ({ label: plan.label, color: PLAN_COLOR[plan.key] }))}
                format={inr}
                axis={rupeeAxis}
                height={200}
                maxBar={22}
            />
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Module usage                                                            */
/* ------------------------------------------------------------------------ */

/**
 * One hue for every bar: the length is the message, and a colour per module
 * would only repeat the name written beside it.
 */
function ModuleUsage({ panel }: { panel: ModuleUsagePanel | null }) {
    if (!panel || panel.rows.length === 0) {
        return (
            <Panel icon="ti ti-box" tone="teal" title="Module Usage">
                <Nothing icon="ti ti-box">No active organization is using a module yet.</Nothing>
            </Panel>
        );
    }

    const rows = [...panel.rows].sort((a, b) => b.share - a.share);

    return (
        <Panel icon="ti ti-box" tone="teal" title="Module Usage">
            <p className="sa-note">
                Share of {count(panel.organizations)} active {panel.organizations === 1 ? 'organization' : 'organizations'}
            </p>

            <ul className="sa-bars">
                {rows.map((row) => (
                    <li key={row.key} title={`${row.organizations} of ${panel.organizations} organizations`}>
                        <span className="sa-bar-name">{row.label}</span>
                        <span className="sa-bar-track">
                            <span style={{ width: `${Math.min(row.share, 100)}%` }} />
                        </span>
                        <b>{row.share}%</b>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Locations                                                               */
/* ------------------------------------------------------------------------ */

/** How many states the list names before folding the rest into "+N more". */
const TOP_STATES = 4;

/**
 * The map with a ranked list of states beside it. Pointing at a row lights
 * its dot and pointing at a dot lights its row, so the two read as one.
 *
 * Drawn even with nothing on it — an empty India with a note says "no
 * locations yet" more plainly than a missing card.
 */
function Locations({ panel }: { panel: LocationsPanel }) {
    const [active, setActive] = useState<string | null>(null);

    const states = panel.points
        .filter((point) => point.total > 0)
        .map((point) => ({ ...point, region: regionOf(point.state) }))
        .sort((a, b) => b.total - a.total);
    const shown = states.slice(0, TOP_STATES);
    const most = Math.max(...states.map((point) => point.total), 1);

    const byRegion = REGIONS.map((region) => ({
        ...region,
        total: states.filter((point) => point.region?.key === region.key).reduce((sum, point) => sum + point.total, 0),
    }));

    return (
        <Panel icon="ti ti-map-2" title="Organization Locations">
            <div className="sa-locations">
                <div className="sa-locations-map">
                    <IndiaMap places={panel.places} active={active} onActive={setActive} />

                    {panel.places.length === 0 && (
                        <p className="sa-map-empty">
                            <i className="ti ti-map-pin-off" aria-hidden="true" />
                            No organization's address has been located yet.
                        </p>
                    )}

                    <ul className="sa-locations-legend">
                        <li>
                            <span className="sa-ring-key" />
                            Exact address
                        </li>
                        <li>
                            <span className="sa-ring-key is-approximate" />
                            Approximate (PIN code / city)
                        </li>
                    </ul>
                </div>

                <aside className="sa-locations-side">
                    <dl className="sa-locations-totals">
                        <div>
                            <dt>Total Organizations</dt>
                            <dd>{count(panel.organizations)}</dd>
                        </div>
                        {panel.clinics !== null ? (
                            <div>
                                <dt>Total Clinics</dt>
                                <dd>{count(panel.clinics)}</dd>
                            </div>
                        ) : (
                            <div title="Organizations whose address has been located on the map">
                                <dt>On the Map</dt>
                                <dd>{count(panel.located)}</dd>
                            </div>
                        )}
                    </dl>

                    {/* What the dot colours mean — every region, with its count. */}
                    <h3 className="sa-states-title">By Region</h3>
                    <ul className="sa-regions" aria-label="Dot colour: region">
                        {byRegion.map((region) => (
                            <li key={region.key} className={region.total === 0 ? 'is-zero' : undefined}>
                                <span className="sa-region-key" style={{ background: region.color }} />
                                <span>{region.label}</span>
                                <b>{count(region.total)}</b>
                            </li>
                        ))}
                    </ul>

                    <h3 className="sa-states-title">Top States</h3>

                    {shown.length === 0 ? (
                        <p className="sa-states-empty">
                            <i className="ti ti-map-pin-off" aria-hidden="true" />
                            No organization has finished setup with its address yet.
                        </p>
                    ) : (
                        <ol className="sa-states">
                            {shown.map((point) => (
                                <li
                                    key={point.state}
                                    className={active === point.state ? 'is-active' : undefined}
                                    onMouseEnter={() => setActive(point.state)}
                                    onMouseLeave={() => setActive(null)}
                                >
                                    <span
                                        className="sa-region-key"
                                        style={{ background: point.region?.color ?? 'var(--od-grey)' }}
                                    />
                                    <span className="sa-states-name" title={point.state}>
                                        {point.state}
                                        {point.region && <small>{point.region.label}</small>}
                                    </span>
                                    <b>{count(point.total)}</b>
                                    <span className="sa-states-bar" aria-hidden="true">
                                        <span
                                            style={{
                                                width: `${(point.total / most) * 100}%`,
                                                background: point.region?.color ?? 'var(--od-grey)',
                                            }}
                                        />
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}

                    {states.length > TOP_STATES && (
                        <p className="sa-states-more">
                            +{states.length - TOP_STATES} more {states.length - TOP_STATES === 1 ? 'state' : 'states'}
                        </p>
                    )}
                </aside>
            </div>
        </Panel>
    );
}

/* ------------------------------------------------------------------------ */
/*  Website enquiries                                                       */
/* ------------------------------------------------------------------------ */

const ENQUIRY_TYPE: Record<EnquiryType, string> = {
    demo: 'Demo',
    trial: 'Free Trial',
    contact: 'Contact',
};

const ENQUIRY_STATUS: Record<EnquiryStatus, string> = {
    new: 'New',
    contacted: 'Contacted',
    converted: 'Converted',
    closed: 'Closed',
};

/**
 * Demo and trial requests from the marketing website: the period's counts
 * across the top, the latest requests under them with a way to reach each.
 */
function Enquiries({ panel, compare }: { panel: EnquiriesPanel; compare: string }) {
    const stats = [
        { key: 'total', label: 'Enquiries Received', value: panel.total, icon: 'ti ti-inbox', tone: 'blue' },
        { key: 'demo', label: 'Demo Requests', value: panel.demo, icon: 'ti ti-presentation', tone: 'violet' },
        { key: 'trial', label: 'Free Trial Requests', value: panel.trial, icon: 'ti ti-rocket', tone: 'green' },
        { key: 'awaiting', label: 'Awaiting Reply', value: panel.awaiting_reply, icon: 'ti ti-clock', tone: 'amber' },
    ];

    return (
        <Panel icon="ti ti-message-circle" tone="violet" title="Website Enquiries & Demo Requests">
            <div className="sa-enquiry-stats">
                {stats.map((stat) => (
                    <div className="sa-enquiry-stat" key={stat.key}>
                        <span className={`sa-enquiry-stat-icon od-bg-${stat.tone}`}>
                            <i className={stat.icon} aria-hidden="true" />
                        </span>
                        <div>
                            <b>{count(stat.value)}</b>
                            <span>{stat.label}</span>
                            {stat.key === 'total' && panel.change !== null && (
                                <small>
                                    <Change change={panel.change} /> {compare}
                                </small>
                            )}
                            {stat.key === 'awaiting' && <small>Any date</small>}
                        </div>
                    </div>
                ))}
            </div>

            {panel.rows.length === 0 ? (
                <Nothing icon="ti ti-inbox">
                    No enquiries from the website yet. Demo and free-trial requests appear here as they arrive.
                </Nothing>
            ) : (
                <div className="od-table-wrap">
                    <table className="od-table sa-table">
                        <thead>
                            <tr>
                                <th>Received</th>
                                <th>Name</th>
                                <th>Organization</th>
                                <th>Contact</th>
                                <th>Interested In</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {panel.rows.map((row) => (
                                <tr key={row.id}>
                                    <td className="sa-nowrap">{dayAndTime(row.created_at)}</td>
                                    <td>
                                        <span className="sa-org-name">{row.name}</span>
                                    </td>
                                    <td>{row.organization_name ?? '—'}</td>
                                    <td>
                                        <span className="sa-contact">
                                            <a href={`mailto:${row.email}`}>{row.email}</a>
                                            {row.phone && <a href={`tel:${row.phone.replace(/\s+/g, '')}`}>{row.phone}</a>}
                                        </span>
                                    </td>
                                    <td className="sa-interest" title={row.interest ?? undefined}>
                                        {row.interest ?? '—'}
                                    </td>
                                    <td>
                                        <span className={`sa-type is-${row.type}`}>{ENQUIRY_TYPE[row.type] ?? row.type}</span>
                                    </td>
                                    <td>
                                        <span className={`sa-status is-${row.status}`}>
                                            {ENQUIRY_STATUS[row.status] ?? row.status}
                                        </span>
                                    </td>
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
/*  Recent activity                                                         */
/* ------------------------------------------------------------------------ */

const ACTIVITY_LOOK: Record<string, { icon: string; tone: string }> = {
    Organization: { icon: 'ti ti-building', tone: 'blue' },
    Location: { icon: 'ti ti-building-hospital', tone: 'green' },
    OrganizationModule: { icon: 'ti ti-box', tone: 'violet' },
    OrganizationType: { icon: 'ti ti-category', tone: 'teal' },
    PlatformUser: { icon: 'ti ti-user-shield', tone: 'grey' },
    Payment: { icon: 'ti ti-receipt', tone: 'green' },
    Subscription: { icon: 'ti ti-refresh', tone: 'amber' },
};

function RecentActivity({ entries }: { entries: PlatformActivity[] }) {
    return (
        <Panel icon="ti ti-clock" title="Recent Activity" actions={<ViewAll to="/audit" />}>
            {entries.length === 0 ? (
                <Nothing icon="ti ti-history">Nothing has happened on the platform yet.</Nothing>
            ) : (
                <ul className="sa-activity">
                    {entries.map((entry) => {
                        const look = ACTIVITY_LOOK[entry.entity_type] ?? { icon: 'ti ti-point', tone: 'grey' };

                        return (
                            <li key={entry.id}>
                                <span className={`sa-activity-mark od-bg-${look.tone}`}>
                                    <i className={look.icon} aria-hidden="true" />
                                </span>

                                <span className="sa-activity-text">
                                    <span>{entry.text}</span>
                                    <time dateTime={entry.created_at ?? undefined}>
                                        {activityTime(entry.created_at)}
                                        {entry.actor_name && <> · {entry.actor_name}</>}
                                    </time>
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Panel>
    );
}

/** "27 Oct 2026, 09:55 AM". */
function activityTime(iso: string | null): string {
    if (!iso) return '';

    const date = new Date(iso);

    return `${formatDate(iso)}, ${date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}`;
}
