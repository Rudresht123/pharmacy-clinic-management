import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { AreaChart } from '@/shared/components/ui/AreaChart';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { formatDate, formatRelative } from '@/shared/utils/format';
import type {
    ActivityEntry,
    AppointmentsPanel,
    BranchesPanel,
    DashboardSummary,
    HeadlineCard,
    Insight,
    PatientsPanel,
    PlanPanel,
    Department,
} from './api';

/**
 * One card of the dashboard.
 *
 * A panel decides for itself whether it has anything to render, from the
 * summary it is given. The page never asks "does the OPD module exist" — it
 * walks this list and each entry answers by returning null.
 *
 * Adding a module's panel is one entry here and one on the server. Nothing in
 * DashboardPage changes, and a panel the server has not sent simply does not
 * appear.
 */
export interface PanelDefinition {
    key: string;
    /** Which of the two columns it belongs to. */
    column: 'main' | 'rail';
    render: (summary: DashboardSummary) => React.ReactNode;
}

/** The tone each headline figure wears, keyed by what it counts. */
const CARD_TONE: Record<HeadlineCard['key'], string> = {
    branches: 'violet',
    staff: 'sky',
    patients: 'emerald',
    appointments: 'amber',
};

const CARD_ICON: Record<HeadlineCard['key'], string> = {
    branches: 'ti ti-building-store',
    staff: 'ti ti-users-group',
    patients: 'ti ti-user-heart',
    appointments: 'ti ti-calendar-event',
};

/**
 * A month-on-month change.
 *
 * Direction in colour AND an arrow. A minus sign at 11px is easy to miss, and
 * "down 8%" is the half somebody needs to catch.
 */
function Delta({ change }: { change: number }) {
    const rising = change > 0;

    return (
        <em className={`db-delta${rising ? ' is-up' : change < 0 ? ' is-down' : ''}`}>
            <i
                className={
                    rising
                        ? 'ti ti-arrow-up-right'
                        : change < 0
                          ? 'ti ti-arrow-down-right'
                          : 'ti ti-minus'
                }
            />
            {Math.abs(change)}%
        </em>
    );
}

function More({ to, children }: { to: string; children: React.ReactNode }) {
    return (
        <Link to={to} className="db-link">
            {children}
            <i className="ti ti-arrow-right" />
        </Link>
    );
}

/* -------------------------------------------------------------------------- */

export function Headline({ cards }: { cards: HeadlineCard[] }) {
    return (
        <div className="db-cards">
            {cards.map((card) => {
                return (
                    <article className={`db-card is-${CARD_TONE[card.key]}`} key={card.key}>
                        <span className="db-card-icon">
                            <i className={CARD_ICON[card.key]} />
                        </span>

                        <div className="db-card-body">
                            <span className="db-card-label">{card.label}</span>

                            <span className="db-card-value">
                                {card.total.toLocaleString()}

                                {card.change !== null && <Delta change={card.change} />}
                            </span>

                            <span className="db-card-hint">
                                {card.this_month > 0
                                    ? `+${card.this_month} this month`
                                    : 'None added this month'}
                            </span>
                        </div>
                    </article>
                );
            })}
        </div>
    );
}

/**
 * Appointments day by day, this month against last.
 *
 * The comparison is drawn as a thin muted line with no fill: it is context for
 * the month being read, not a second thing of equal weight.
 */
function AppointmentsOverview({ appointments }: { appointments: AppointmentsPanel }) {
    const nothing =
        appointments.trend.current.every((point) => point.value === 0) &&
        appointments.trend.previous.every((point) => point.value === 0);

    return (
        <Card
            className="chart-card"
            title="Appointments overview"
            icon="ti ti-calendar-stats"
            description="Across the branches you can see"
            actions={<More to="/queue">Open the queue</More>}
        >
            {nothing ? (
                <p className="db-quiet">
                    <i className="ti ti-calendar" />
                    No appointments this month or last. The chart fills in as bookings are taken.
                </p>
            ) : (
                <AreaChart
                    points={appointments.trend.current}
                    compare={appointments.trend.previous}
                    valueLabel="booked"
                />
            )}
        </Card>
    );
}

/** Where the book came from. */
function PatientsByBranch({ patients, label }: { patients: PatientsPanel; label: string }) {
    return (
        <Card
            className="chart-card"
            title={`${label} by branch`}
            icon="ti ti-chart-donut"
            description="Distribution across the network"
        >
            <DonutChart
                slices={patients.by_branch.map((row) => ({
                    label: row.label,
                    value: row.total,
                    muted: row.muted,
                }))}
                centreLabel={label}
            />
        </Card>
    );
}

/** The network at a glance, with the one fact that has a deadline. */
function Branches({ branches, label }: { branches: BranchesPanel; label: string }) {
    return (
        <Card
            className="chart-card"
            title={label}
            icon="ti ti-building-store"
            actions={<More to="/locations">View all</More>}
        >
            {branches.rows.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-building-store" />
                    No branches yet. Add one to start recording where things happen.
                </p>
            ) : (
                <div className="db-scroll">
                    <table className="db-table">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th>Location</th>
                                <th className="is-num">Staff</th>
                                <th className="is-num">{label === 'Branches' ? 'Patients' : label}</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {branches.rows.map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        {row.is_primary && (
                                            <i className="ti ti-star-filled db-primary" title="Your branch" />
                                        )}
                                        <Link to={`/locations/${row.id}/edit`}>{row.name}</Link>
                                    </td>
                                    <td className="is-muted">
                                        {[row.city, row.state].filter(Boolean).join(', ') || '—'}
                                    </td>
                                    <td className="is-num">{row.staff}</td>
                                    <td className="is-num">{row.patients.toLocaleString()}</td>
                                    <td>
                                        <span
                                            className={`db-status is-${row.is_active ? 'on' : 'off'}`}
                                        >
                                            {row.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {branches.licences_needing_attention.length > 0 && (
                <ul className="db-licences">
                    {branches.licences_needing_attention.map((branch) => (
                        <li key={branch.id} className={branch.expired ? 'is-expired' : ''}>
                            <i className={branch.expired ? 'ti ti-alert-triangle' : 'ti ti-clock'} />
                            <span>{branch.name}</span>
                            <em>
                                {branch.expired ? 'Licence expired ' : 'Licence expires '}
                                {formatDate(branch.expires_at)}
                            </em>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** Who is still coming today, soonest first. */
function Upcoming({ appointments }: { appointments: AppointmentsPanel }) {
    return (
        <Card
            className="chart-card"
            title="Still expected today"
            icon="ti ti-clock-hour-4"
            description={formatDate(appointments.date)}
            actions={<More to="/queue">View all</More>}
        >
            {appointments.upcoming.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-circle-check" />
                    {appointments.waiting + appointments.seen > 0
                        ? 'Everybody booked today has arrived.'
                        : 'Nothing booked for today.'}
                </p>
            ) : (
                <div className="db-scroll">
                    <table className="db-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Patient</th>
                                <th>Branch</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            {appointments.upcoming.map((row) => (
                                <tr key={row.id}>
                                    <td className="is-num">{row.time ?? '—'}</td>
                                    <td>{row.patient}</td>
                                    <td className="is-muted">{row.branch ?? '—'}</td>
                                    <td>
                                        <span className={`db-type is-${row.type}`}>
                                            {row.type === 'walk_in' ? 'Walk-in' : 'Booked'}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

/**
 * Departments — a module with no table, model or routes yet.
 *
 * Present only while demo data is on, which is why it is optional like every
 * other panel: when the module ships it arrives from DashboardSummary instead
 * and nothing here changes.
 */
function Departments({ departments }: { departments: Department[] }) {
    return (
        <Card
            className="chart-card"
            title="Departments"
            icon="ti ti-layout-grid"
            description="How the work is organised"
        >
            <div className="db-scroll">
                <table className="db-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Head</th>
                            <th className="is-num">Staff</th>
                            <th className="is-num">Patients</th>
                        </tr>
                    </thead>
                    <tbody>
                        {departments.map((department) => (
                            <tr key={department.id}>
                                <td>{department.name}</td>
                                <td className="is-muted">{department.head}</td>
                                <td className="is-num">{department.staff}</td>
                                <td className="is-num">
                                    {department.patients > 0
                                        ? department.patients.toLocaleString()
                                        : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

/** Four figures about the month — two measured, two waiting on their module. */
function Insights({ insights }: { insights: Insight[] }) {
    return (
        <Card
            className="chart-card"
            title="Organization insights"
            icon="ti ti-chart-bar"
            description="Key metrics for this month"
        >
            <div className="db-insights">
                {insights.map((insight) => (
                    <article className="db-insight" key={insight.key}>
                        <span className="db-insight-icon">
                            <i className={insight.icon} />
                        </span>

                        <span className="db-insight-body">
                            <span className="db-insight-label">{insight.label}</span>

                            <span className="db-insight-value">
                                <b>{insight.value}</b>
                                {typeof insight.change === 'number' && (
                                    <Delta change={insight.change} />
                                )}
                            </span>
                        </span>
                    </article>
                ))}
            </div>
        </Card>
    );
}

/* ---- the rail ------------------------------------------------------------ */

/**
 * The four things somebody most often opens this screen to start.
 *
 * Each is capability-gated by the panel it links to being present — an action
 * that would answer 403 is not offered.
 */
function QuickActions({ summary }: { summary: DashboardSummary }) {
    const actions = [
        summary.branches && {
            to: '/locations/create',
            icon: 'ti ti-building-plus',
            title: 'Add a branch',
            hint: 'Somewhere new to work from',
        },
        summary.headline?.some((card) => card.key === 'staff') && {
            to: '/people/create',
            icon: 'ti ti-user-plus',
            title: 'Add somebody',
            hint: 'A new member of staff',
        },
        summary.patients && {
            to: '/customers/create',
            icon: 'ti ti-user-heart',
            title: 'Register a patient',
            hint: 'Take their details',
        },
        summary.appointments && {
            to: '/queue',
            icon: 'ti ti-calendar-plus',
            title: 'Book or walk-in',
            hint: 'Into today’s queue',
        },
    ].filter(Boolean) as { to: string; icon: string; title: string; hint: string }[];

    if (actions.length === 0) {
        return null;
    }

    return (
        <Card className="chart-card" title="Quick actions" icon="ti ti-bolt">
            <ul className="db-actions">
                {actions.map((action) => (
                    <li key={action.to}>
                        <Link to={action.to}>
                            <span className="db-action-icon">
                                <i className={action.icon} />
                            </span>
                            <span className="db-action-text">
                                <b>{action.title}</b>
                                <small>{action.hint}</small>
                            </span>
                            <i className="ti ti-chevron-right" />
                        </Link>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/**
 * What this organization has been sold.
 *
 * The reference design had a subscription tier here. There are no tiers — a
 * super admin assigns modules one at a time — so this says the true thing in
 * the same place rather than inventing a plan name.
 */
function Plan({ plan }: { plan: PlanPanel }) {
    return (
        <div className="db-plan">
            <i className="ti ti-package" />

            <div>
                <b>
                    {plan.modules} module{plan.modules === 1 ? '' : 's'} enabled
                </b>
                <p>{plan.names.join(' · ')}</p>
                <small>Your administrator manages which modules you have.</small>
            </div>
        </div>
    );
}

/** What has been happening, for whoever may read the log. */
function Activity({ entries }: { entries: ActivityEntry[] }) {
    return (
        <Card
            className="chart-card"
            title="Recent activity"
            icon="ti ti-history"
            actions={<More to="/activity">View all</More>}
        >
            {entries.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-history" />
                    Nothing recorded yet.
                </p>
            ) : (
                <ul className="db-activity">
                    {entries.map((entry) => (
                        <li key={entry.id}>
                            <span className={`db-dot is-${entry.action}`}>
                                <i
                                    className={
                                        entry.action === 'created'
                                            ? 'ti ti-plus'
                                            : entry.action === 'deleted'
                                              ? 'ti ti-minus'
                                              : 'ti ti-pencil'
                                    }
                                />
                            </span>

                            <span className="db-activity-what">
                                <b>{entry.entity_label ?? entry.entity_type}</b>
                                {entry.detail && <span>{entry.detail}</span>}
                                <small>
                                    {entry.actor_name ? `${entry.actor_name} · ` : ''}
                                    {entry.created_at ? formatRelative(entry.created_at) : ''}
                                </small>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/* -------------------------------------------------------------------------- */

/**
 * The cards, in the order a clinic reads them.
 *
 * The main column carries what changes through the day; the rail carries what
 * somebody acts on. Inventory, billing and prescriptions slot in here when
 * their modules ship.
 */
export function dashboardPanels(labels: Record<string, string>): PanelDefinition[] {
    const patients = labels.customer ?? 'Patients';
    const branches = labels.location ?? 'Branches';

    return [
        {
            key: 'appointments-overview',
            column: 'main',
            render: (summary) =>
                summary.appointments ? (
                    <AppointmentsOverview appointments={summary.appointments} />
                ) : null,
        },
        {
            key: 'patients-by-branch',
            column: 'main',
            render: (summary) =>
                summary.patients && summary.patients.by_branch.length > 0 ? (
                    <PatientsByBranch patients={summary.patients} label={patients} />
                ) : null,
        },
        {
            key: 'branches',
            column: 'main',
            render: (summary) =>
                summary.branches ? (
                    <Branches branches={summary.branches} label={branches} />
                ) : null,
        },
        {
            key: 'upcoming',
            column: 'main',
            render: (summary) =>
                summary.appointments ? <Upcoming appointments={summary.appointments} /> : null,
        },
        {
            key: 'departments',
            column: 'main',
            render: (summary) =>
                summary.departments?.length ? (
                    <Departments departments={summary.departments} />
                ) : null,
        },
        {
            key: 'insights',
            column: 'main',
            render: (summary) =>
                summary.insights ? <Insights insights={summary.insights} /> : null,
        },
        {
            key: 'quick-actions',
            column: 'rail',
            render: (summary) => <QuickActions summary={summary} />,
        },
        {
            key: 'plan',
            column: 'rail',
            render: (summary) => (summary.plan ? <Plan plan={summary.plan} /> : null),
        },
        {
            key: 'activity',
            column: 'rail',
            render: (summary) =>
                summary.activity ? <Activity entries={summary.activity} /> : null,
        },
    ];
}
