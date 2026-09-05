import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { TrendBars } from '@/shared/components/ui/TrendBars';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { formatRelative } from '@/shared/utils/format';
import type { DashboardSummary } from './api';
import type { PanelDefinition } from './panels';

/** Rupees, grouped the Indian way — 12,48,500 rather than 1,248,500. */
function money(amount: number): string {
    return `₹${amount.toLocaleString('en-IN')}`;
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

/**
 * Arrivals by the hour.
 *
 * Hourly rather than daily, because at a branch the useful question is when
 * the rush is — something a day-by-day chart cannot answer.
 */
function Visits({ summary }: { summary: DashboardSummary }) {
    const points = summary.visits?.points ?? [];

    return (
        <Card
            className="chart-card"
            title="Patient visits"
            icon="ti ti-chart-bar"
            description="Arrivals through the day"
        >
            {points.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-clock" />
                    Nobody has arrived yet today.
                </p>
            ) : (
                <TrendBars points={points} />
            )}
        </Card>
    );
}

/** What today's appointments were for. */
function ByType({ summary }: { summary: DashboardSummary }) {
    const slices = summary.by_type ?? [];

    return (
        <Card
            className="chart-card"
            title="Appointments by type"
            icon="ti ti-chart-donut"
            description="Today"
        >
            {slices.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-calendar" />
                    Nothing booked today yet.
                </p>
            ) : (
                <div className="db-donut">
                    <DonutChart
                        slices={slices.map((slice) => ({
                            label: slice.label,
                            value: slice.total,
                            muted: slice.muted,
                        }))}
                        centreLabel="Appointments"
                    />
                </div>
            )}
        </Card>
    );
}

/** Who is coming, and where they have got to. */
function TodaysAppointments({ summary }: { summary: DashboardSummary }) {
    const rows = summary.appointments?.upcoming ?? [];

    return (
        <Card
            className="chart-card"
            title="Today’s appointments"
            icon="ti ti-calendar-event"
            actions={<More to="/queue">View all</More>}
        >
            {rows.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-circle-check" />
                    Nothing left in today’s book.
                </p>
            ) : (
                <div className="db-scroll">
                    <table className="db-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Patient</th>
                                <th>Type</th>
                                <th>Doctor</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td className="is-num">{row.time ?? '—'}</td>
                                    <td>{row.patient}</td>
                                    <td className="is-muted">{row.type}</td>
                                    <td className="is-muted">{row.doctor ?? '—'}</td>
                                    <td>
                                        <span className={`db-state is-${row.status ?? 'booked'}`}>
                                            {row.status === 'checked_in'
                                                ? 'Checked in'
                                                : row.status === 'waiting'
                                                  ? 'Waiting'
                                                  : 'Scheduled'}
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

/** The last few people through the door. */
function RecentPatients({ summary }: { summary: DashboardSummary }) {
    const rows = summary.recent_patients ?? [];

    return (
        <Card
            className="chart-card"
            title="Recent patients"
            icon="ti ti-users"
            actions={<More to="/customers">View all</More>}
        >
            {rows.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-user-plus" />
                    Nobody registered here yet.
                </p>
            ) : (
                <div className="db-scroll">
                    <table className="db-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th className="is-num">Age</th>
                                <th>Type</th>
                                <th>Visit date</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        <span className="db-avatar">
                                            {row.name
                                                .split(' ')
                                                .slice(0, 2)
                                                .map((part) => part.charAt(0))
                                                .join('')
                                                .toUpperCase()}
                                        </span>
                                        {row.name}
                                    </td>
                                    <td className="is-num">{row.age ?? '—'}</td>
                                    <td className="is-muted">{row.type ?? '—'}</td>
                                    <td className="is-muted">
                                        {/* Short: this is the widest column in
                                            the narrowest card, and the year is
                                            the same for every row. */}
                                        {row.joined_at
                                            ? new Date(row.joined_at).toLocaleDateString(
                                                  undefined,
                                                  { day: 'numeric', month: 'short' },
                                              )
                                            : '—'}
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

/** What is running out. */
function StockAlerts({ summary }: { summary: DashboardSummary }) {
    const rows = summary.stock ?? [];

    if (rows.length === 0) {
        return null;
    }

    return (
        <Card
            className="chart-card"
            title="Stock alerts"
            icon="ti ti-alert-triangle"
            actions={<More to="/dashboard">View all</More>}
        >
            <div className="db-scroll">
                <table className="db-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th className="is-num">Stock</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.name}>
                                <td>{row.name}</td>
                                <td className="is-num">{row.stock}</td>
                                <td>
                                    {/* Out is a different problem from low, and
                                        the two must not read the same. */}
                                    <span className={`db-stock is-${row.status}`}>
                                        {row.status === 'out' ? 'Out of stock' : 'Low stock'}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

/** Takings, day by day. */
function Revenue({ summary }: { summary: DashboardSummary }) {
    const panel = summary.revenue;

    if (!panel || panel.rows.length === 0) {
        return null;
    }

    return (
        <Card className="chart-card" title="Revenue overview" icon="ti ti-chart-bar">
            <div className="db-scroll">
                <table className="db-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th className="is-num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {panel.rows.map((row) => (
                            <tr key={row.label} className={row.today ? 'is-today' : ''}>
                                <td>{row.label}</td>
                                <td className="is-num">{money(row.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td className="is-num">{money(panel.total)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    );
}

/** What has to be done before closing. */
function Tasks({ summary }: { summary: DashboardSummary }) {
    const rows = summary.tasks ?? [];

    if (rows.length === 0) {
        return null;
    }

    return (
        <Card className="chart-card" title="Tasks & reminders" icon="ti ti-checkbox">
            <ul className="db-tasks">
                {rows.map((task) => (
                    <li key={task.label}>
                        <label>
                            <input type="checkbox" className="form-check-input" />
                            <span>{task.label}</span>
                        </label>

                        {task.badge && (
                            <em className={`db-task-badge is-${task.tone ?? 'slate'}`}>
                                {task.badge}
                            </em>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/* ---- the rail ------------------------------------------------------------ */

/** The branch itself — the card a desk checks a phone number on. */
function BranchInfo({ summary }: { summary: DashboardSummary }) {
    const branch = summary.branch_info;

    if (!branch) {
        return null;
    }

    return (
        <Card
            className="chart-card"
            title="Branch info"
            icon="ti ti-building"
            actions={<More to={`/locations/${branch.id}/edit`}>Edit</More>}
        >
            <div className="db-branch">
                <b>{branch.name}</b>
                <span>{[branch.city, branch.state].filter(Boolean).join(', ')}</span>

                <ul>
                    {branch.phone && (
                        <li>
                            <i className="ti ti-phone" />
                            {branch.phone}
                        </li>
                    )}
                    {branch.email && (
                        <li>
                            <i className="ti ti-mail" />
                            {branch.email}
                        </li>
                    )}
                    {branch.address && (
                        <li>
                            <i className="ti ti-map-pin" />
                            {[branch.address, branch.pincode].filter(Boolean).join(' — ')}
                        </li>
                    )}
                </ul>
            </div>
        </Card>
    );
}

function QuickActions({ summary }: { summary: DashboardSummary }) {
    const actions = [
        summary.recent_patients && {
            to: '/customers/create',
            icon: 'ti ti-user-plus',
            title: 'Register new patient',
        },
        summary.appointments && {
            to: '/queue',
            icon: 'ti ti-calendar-plus',
            title: 'Book appointment',
        },
        summary.stock?.length && {
            to: '/dashboard',
            icon: 'ti ti-package',
            title: 'Add medicine stock',
        },
        summary.revenue?.rows.length && {
            to: '/dashboard',
            icon: 'ti ti-receipt',
            title: 'Generate invoice',
        },
    ].filter(Boolean) as { to: string; icon: string; title: string }[];

    if (actions.length === 0) {
        return null;
    }

    return (
        <Card className="chart-card" title="Quick actions" icon="ti ti-bolt">
            <ul className="db-actions">
                {actions.map((action) => (
                    <li key={action.title}>
                        <Link to={action.to}>
                            <span className="db-action-icon">
                                <i className={action.icon} />
                            </span>
                            <span className="db-action-text">
                                <b>{action.title}</b>
                            </span>
                            <i className="ti ti-chevron-right" />
                        </Link>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function Activity({ summary }: { summary: DashboardSummary }) {
    const entries = summary.activity ?? [];

    if (entries.length === 0) {
        return null;
    }

    return (
        <Card
            className="chart-card"
            title="Recent activity"
            icon="ti ti-history"
            actions={<More to="/activity">View all</More>}
        >
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
                                {entry.created_at ? formatRelative(entry.created_at) : ''}
                            </small>
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/* -------------------------------------------------------------------------- */

/**
 * One branch's day, in the order somebody standing in it reads.
 *
 * Not a narrower version of the organization dashboard — a different screen.
 * The questions here are who is arriving, what is running low and what has to
 * be done before closing, none of which mean anything summed across five
 * branches.
 */
export function branchPanels(): PanelDefinition[] {
    return [
        { key: 'visits', column: 'main', span: 7, render: (s) => <Visits summary={s} /> },
        { key: 'by_type', column: 'main', span: 5, render: (s) => <ByType summary={s} /> },
        {
            key: 'todays-appointments',
            column: 'main',
            span: 7,
            render: (s) => <TodaysAppointments summary={s} />,
        },
        {
            key: 'recent-patients',
            column: 'main',
            span: 5,
            render: (s) => <RecentPatients summary={s} />,
        },
        /*
         * Three across, below both columns rather than inside the main one.
         * Beside a 330px rail the main column leaves roughly 250px a card,
         * which is narrower than "Paracetamol 500mg · 12 · Low stock" needs —
         * the tables scrolled sideways inside their own cards. Given the full
         * width they are three readable thirds.
         */
        { key: 'stock', column: 'full', span: 4, render: (s) => <StockAlerts summary={s} /> },
        { key: 'revenue', column: 'full', span: 4, render: (s) => <Revenue summary={s} /> },
        { key: 'tasks', column: 'full', span: 4, render: (s) => <Tasks summary={s} /> },

        { key: 'quick-actions', column: 'rail', render: (s) => <QuickActions summary={s} /> },
        { key: 'branch-info', column: 'rail', render: (s) => <BranchInfo summary={s} /> },
        { key: 'activity', column: 'rail', render: (s) => <Activity summary={s} /> },
    ];
}
