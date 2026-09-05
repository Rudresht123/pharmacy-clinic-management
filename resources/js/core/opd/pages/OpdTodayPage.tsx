import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useEntityLabel } from '@/core/field-settings/api';
import { BookDialog } from '@/core/appointments/components/BookDialog';
import { useMoveAppointment } from '@/core/appointments/api';
import type { AppointmentStatus } from '@/core/appointments/types';
import { useOpdToday } from '../api';
import { useOpdContext, today as todayString } from '../useOpdContext';
import { OpdToolbar } from '../components/OpdToolbar';
import { FlowChart } from '../components/FlowChart';
import { StatusBadge, WaitBadge } from '../components/Badges';
import type { OpdDoctor, OpdQueueRow, OpdTabs } from '../types';

/** What each doctor's state looks like, in words as well as colour. */
const DOCTOR_STATE: Record<OpdDoctor['state'], { label: string; tone: string }> = {
    with_patient: { label: 'In consultation', tone: 'amber' },
    free: { label: 'Available', tone: 'emerald' },
    clear: { label: 'List clear', tone: 'muted' },
};

/** The filters over the queue, in the order the day moves through them. */
const TABS: { key: keyof OpdTabs; label: string; status: AppointmentStatus | null }[] = [
    { key: 'all', label: 'Live', status: null },
    { key: 'checked_in', label: 'Waiting', status: 'checked_in' },
    { key: 'in_consultation', label: 'With doctor', status: 'in_consultation' },
    { key: 'booked', label: 'Expected', status: 'booked' },
    { key: 'completed', label: 'Completed', status: 'completed' },
];

/**
 * Everything the header's Actions menu offers.
 *
 * Each carries a line saying what it is for. A walk-in and a booking land in
 * the same dialog and differ by one thing — whether a time is promised — which
 * is exactly the distinction a bare pair of labels fails to make.
 */
const ACTIONS: {
    key: 'register' | 'walk_in' | 'booked' | 'queue';
    label: string;
    hint: string;
    icon: string;
    /** Hidden when looking at a past day, where it could do nothing useful. */
    todayOnly?: boolean;
}[] = [
    {
        key: 'register',
        label: 'Register a patient',
        hint: 'Add somebody new and put them straight in the queue',
        icon: 'ti ti-user-plus',
        todayOnly: true,
    },
    {
        key: 'walk_in',
        label: 'Walk-in',
        hint: 'They are here now — a token is issued at once',
        icon: 'ti ti-walk',
        todayOnly: true,
    },
    {
        key: 'booked',
        label: 'Book an appointment',
        hint: 'Give them a time with a doctor sitting today',
        icon: 'ti ti-calendar-plus',
        todayOnly: true,
    },
    {
        key: 'queue',
        label: 'Open the full queue',
        hint: 'Every patient on this day, not just the first few',
        icon: 'ti ti-list-check',
    },
];

/** The states somebody is still moving through, which is what "Live" means. */
const LIVE: AppointmentStatus[] = ['checked_in', 'in_consultation', 'booked'];

/** Each move: what it is called, and the capability the server demands. */
const MOVES: Record<string, { label: string; icon: string; action: string; needs: string }> = {
    checked_in: {
        label: 'Check in',
        icon: 'ti ti-login',
        action: 'check-in',
        needs: 'appointments.queue',
    },
    in_consultation: {
        label: 'Call',
        icon: 'ti ti-player-play',
        action: 'start',
        needs: 'appointments.queue',
    },
    completed: {
        label: 'Done',
        icon: 'ti ti-check',
        action: 'complete',
        needs: 'appointments.queue',
    },
};

/**
 * "A-023" — the number as it is called out and printed on a slip.
 *
 * The letter is the session. There is only ever one today, so it is fixed
 * until sittings get a label of their own; the padding is what makes the
 * column line up when the day passes ninety-nine.
 */
function token(value: number | null): string {
    return value === null ? '—' : `A-${String(value).padStart(3, '0')}`;
}

/** Morning, afternoon or evening — from the reader's own clock. */
function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 17 ? 'Good afternoon' : 'Good evening';
}

/** "10:24 AM", for the pill that says when this last came back. */
function clockTime(iso: string | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

/** "10 minutes ago", without pulling in a date library for one line. */
function ago(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 60) {
        return 'just now';
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    return `${Math.round(hours / 24)} day${Math.round(hours / 24) === 1 ? '' : 's'} ago`;
}

/**
 * A stable hue for a speciality, from its name rather than its position.
 *
 * Colour follows the entity, never its rank: a list that reorders when one
 * doctor finishes their morning must not repaint the specialities that are
 * left, or somebody who learnt that green means paediatrics has to learn it
 * again every hour.
 */
function hueFor(label: string): number {
    let sum = 0;

    for (let at = 0; at < label.length; at++) {
        sum = (sum * 31 + label.charCodeAt(at)) % 997;
    }

    return (sum % 6) + 1;
}

/**
 * What an audit row looks like on a feed rather than in a log.
 *
 * The icon follows the RECORD, the tone follows what happened to it. Keyed
 * only on the event, every row on a busy morning was the same blue plus sign —
 * because registering a patient, booking an appointment and finishing a
 * consultation are all "created", and the one thing the feed is for is telling
 * them apart at a glance.
 */
const ENTITY_ICON: Record<string, string> = {
    Customer: 'ti ti-user-plus',
    Appointment: 'ti ti-calendar-event',
    Doctor: 'ti ti-stethoscope',
    DoctorSchedule: 'ti ti-calendar-time',
    User: 'ti ti-user',
    Location: 'ti ti-building-store',
    Role: 'ti ti-shield-lock',
};

const ACTIVITY: Record<string, { tone: string; verb: string }> = {
    created: { tone: 'sky', verb: 'added' },
    updated: { tone: 'amber', verb: 'updated' },
    deleted: { tone: 'rose', verb: 'removed' },
};

/**
 * The OPD dashboard — the screen somebody opens at the start of the morning.
 *
 * Everything on it is real: the counts, the queue, the doctors, the flow and
 * the split by speciality all come from one request against one branch's day,
 * because eight endpoints would let the halves disagree and a shared screen
 * makes that the hardest kind of bug to notice.
 *
 * It reports and then offers the action each report implies — calling the next
 * patient in from the row that says they have waited half an hour. A board
 * that only reports is a poster.
 */
export default function OpdTodayPage() {
    const navigate = useNavigate();
    const confirm = useConfirm();
    const { can, user } = useTenantAuth();
    const patients = useEntityLabel('customer');
    const context = useOpdContext();

    /** Which of the three header buttons opened the dialog, or none. */
    const [booking, setBooking] = useState<'walk_in' | 'booked' | null>(null);
    const [tab, setTab] = useState<keyof OpdTabs>('all');
    const [actionsOpen, setActionsOpen] = useState(false);

    /* Coming back from the registration form with somebody new. */
    const [params, setParams] = useSearchParams();
    const [returned, setReturned] = useState<number | null>(null);

    useEffect(() => {
        const id = Number(params.get('patient'));

        if (!id) {
            return;
        }

        setReturned(id);
        setBooking('walk_in');

        const next = new URLSearchParams(params);
        next.delete('patient');
        setParams(next, { replace: true });
    }, [params, setParams]);
    const actionsBox = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState('');

    const { branchId, date, branch, branchesLoading, hasNoBranch, isToday } = context;
    const { data, isLoading, isFetching, isError, refetch } = useOpdToday(branchId, date);

    const move = useMoveAppointment();

    // Closes on an outside click or Escape, like every other menu here.
    useEffect(() => {
        if (!actionsOpen) {
            return;
        }

        function onOutside(event: MouseEvent) {
            if (actionsBox.current && !actionsBox.current.contains(event.target as Node)) {
                setActionsOpen(false);
            }
        }

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setActionsOpen(false);
            }
        }

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [actionsOpen]);

    const counts = data?.counts;
    const warn = data?.thresholds.warn ?? 10;
    const critical = data?.thresholds.critical ?? 20;

    const queueLink = `/opd/queue?branch=${branchId}&date=${date}`;

    const canBook = can('appointments.book');

    /** The chosen tab and the search box, applied to the rows on hand. */
    const rows = useMemo(() => {
        const wanted = TABS.find((entry) => entry.key === tab)?.status ?? null;
        const term = search.trim().toLowerCase();

        return (data?.queue ?? [])
            .filter((row) =>
                /*
                 * "Live" is the three states somebody is still moving through
                 * — not "everything that is not completed", which quietly swept
                 * in cancellations and no-shows and made the list disagree with
                 * the count on its own tab.
                 */
                wanted === null
                    ? LIVE.includes(row.status)
                    : row.status === wanted,
            )
            .filter(
                (row) =>
                    !term ||
                    row.customer_name?.toLowerCase().includes(term) ||
                    row.customer_code?.toLowerCase().includes(term) ||
                    row.doctor_name?.toLowerCase().includes(term) ||
                    String(row.token_no ?? '').includes(term),
            );
    }, [data, tab, search]);

    /*
     * How many of the day this tab is actually showing.
     *
     * The dashboard carries a slice of each status, not the whole day, so a
     * tab reading 24 with eight rows under it is correct rather than broken —
     * as long as it says so. Silence there is what made the Completed tab look
     * empty.
     */
    const inTab = data?.tabs[tab] ?? 0;
    const truncated = !search.trim() && inTab > rows.length;

    async function onMove(row: OpdQueueRow, next: AppointmentStatus) {
        const action = MOVES[next];

        if (!action) {
            return;
        }

        /*
         * "Done" cannot be undone — `completed` has no transitions out of it,
         * so nobody, not even the owner, can reopen a consultation closed by a
         * mis-click. Checking in and calling through are the ordinary rhythm
         * of a busy desk and must not cost a click each.
         */
        if (next === 'completed') {
            const yes = await confirm({
                title: 'Finish this consultation?',
                message: `${row.customer_name}'s visit is closed. This cannot be reopened.`,
                confirmLabel: 'Done',
            });

            if (!yes) {
                return;
            }
        }

        move.mutate({ id: row.id, action: action.action as never });
    }

    if (branchesLoading) {
        return <LoadingBlock label="Loading…" />;
    }

    /*
     * Head office, typically: somebody with no branch membership at all. Not
     * an error and not an empty dashboard — there is no department to show
     * them, and saying so is more use than a screen of zeroes.
     */
    if (hasNoBranch) {
        return (
            <div className="card">
                <div className="org-pending">
                    <i className="ti ti-map-pin-off" />
                    <h6>You are not attached to a branch</h6>
                    <p>
                        OPD is run at a branch. Ask whoever manages your organization to add you to
                        the one you work at, and this becomes your day's board.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="opd">
            {/* ------------------------------------------------ greeting */}
            <header className="opd-hero">
                <div className="opd-hero-said">
                    <span className="opd-hero-sun" aria-hidden="true">
                        <i className="ti ti-sun-high" />
                    </span>

                    <div>
                        <h1>
                            {greeting()}, {user?.name?.split(' ')[0] ?? 'there'}!
                        </h1>
                        <p>
                            {isToday ? "Here's today's OPD overview" : "Here's the OPD day"} at{' '}
                            {branch?.name ?? 'your branch'}.
                        </p>
                    </div>
                </div>

                <div className="opd-hero-tools">
                    <OpdToolbar context={context} compact />

                    {/*
                        A shared screen that refreshes itself has to say so.
                        Left silent, somebody watching a stale number has no way
                        to tell it apart from a quiet department.
                    */}
                    <button
                        type="button"
                        className={`opd-live${isFetching ? ' is-busy' : ''}`}
                        onClick={() => refetch()}
                        title="Refresh now"
                    >
                        <span className="opd-live-dot" aria-hidden="true" />
                        <span>
                            <b>{isFetching ? 'Updating' : 'Live'}</b>
                            <small>Last updated {clockTime(data?.updated_at)}</small>
                        </span>
                    </button>

                    {/*
                        One Actions menu rather than a row of buttons.

                        Four controls across the header made it a toolbar, and
                        the heading beside them read as the first item in it.
                        Behind one button the header is three things — when the
                        day is, whether the screen is live, and what you can do
                        — and the menu has room to say what each action means,
                        which a button the width of its own label never did.
                    */}
                    {canBook && (
                        <div className="opd-menu" ref={actionsBox}>
                            <button
                                type="button"
                                className="opd-cta"
                                disabled={!branchId}
                                aria-haspopup="menu"
                                aria-expanded={actionsOpen}
                                onClick={() => setActionsOpen((open) => !open)}
                            >
                                <i className="ti ti-bolt" aria-hidden="true" />
                                Actions
                                <i
                                    className={
                                        actionsOpen ? 'ti ti-chevron-up' : 'ti ti-chevron-down'
                                    }
                                    aria-hidden="true"
                                />
                            </button>

                            {actionsOpen && (
                                <div className="opd-menu-list" role="menu">
                                    {ACTIONS.filter((action) => !action.todayOnly || isToday).map(
                                        (action) => (
                                            <button
                                                type="button"
                                                key={action.key}
                                                role="menuitem"
                                                className="opd-menu-item"
                                                onClick={() => {
                                                    setActionsOpen(false);

                                                    if (action.key === 'queue') {
                                                        navigate(queueLink);

                                                        return;
                                                    }

                                                    setBooking(
                                                        action.key === 'booked'
                                                            ? 'booked'
                                                            : 'walk_in',
                                                    );
                                                }}
                                            >
                                                <span className="opd-menu-icon">
                                                    <i className={action.icon} aria-hidden="true" />
                                                </span>

                                                <span className="opd-menu-text">
                                                    <b>
                                                        {action.key === 'register'
                                                            ? `Register a ${patients.singular.toLowerCase()}`
                                                            : action.label}
                                                    </b>
                                                    <small>{action.hint}</small>
                                                </span>
                                            </button>
                                        ),
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </header>

            {isError ? (
                <Card>
                    <ErrorState
                        message="Unable to load the department's day."
                        onRetry={() => refetch()}
                    />
                </Card>
            ) : (
                <>
                    {/* ------------------------------------------ the five */}
                    <div className="opd-stats">
                        <article className="opd-stat is-sky">
                            <span className="opd-stat-icon">
                                <i className="ti ti-users" aria-hidden="true" />
                            </span>

                            {counts?.total.delta_pct !== null &&
                                counts?.total.delta_pct !== undefined && (
                                    <span
                                        className={`opd-stat-chip${counts.total.delta < 0 ? ' is-down' : ''}`}
                                    >
                                        <i
                                            className={
                                                counts.total.delta < 0
                                                    ? 'ti ti-arrow-down'
                                                    : 'ti ti-arrow-up'
                                            }
                                            aria-hidden="true"
                                        />
                                        {Math.abs(counts.total.delta_pct)}%
                                    </span>
                                )}

                            <b>{isLoading ? '—' : (counts?.total.value ?? 0)}</b>
                            <span className="opd-stat-name">Total OPD {patients.plural}</span>
                            <small>
                                {counts
                                    ? `${counts.total.delta >= 0 ? '+' : ''}${counts.total.delta} from yesterday`
                                    : ' '}
                            </small>
                        </article>

                        <article className="opd-stat is-violet">
                            <span className="opd-stat-icon">
                                <i className="ti ti-hourglass" aria-hidden="true" />
                            </span>

                            {!!counts?.waiting.average_wait && (
                                <span
                                    className={`opd-stat-chip is-time${
                                        counts.waiting.average_wait >= critical ? ' is-down' : ''
                                    }`}
                                >
                                    <i className="ti ti-clock" aria-hidden="true" />
                                    {counts.waiting.average_wait} min
                                </span>
                            )}

                            <b>{isLoading ? '—' : (counts?.waiting.value ?? 0)}</b>
                            <span className="opd-stat-name">Waiting</span>
                            <small>
                                {counts?.waiting.value
                                    ? `Longest ${counts.waiting.longest_wait} min`
                                    : 'Nobody in the room'}
                            </small>
                        </article>

                        <article className="opd-stat is-teal">
                            <span className="opd-stat-icon">
                                <i className="ti ti-stethoscope" aria-hidden="true" />
                            </span>

                            <b>{isLoading ? '—' : (counts?.in_consultation.value ?? 0)}</b>
                            <span className="opd-stat-name">In consultation</span>
                            <small>
                                {counts?.in_consultation.doctors
                                    ? `${counts.in_consultation.doctors} doctor${counts.in_consultation.doctors === 1 ? '' : 's'}`
                                    : 'No rooms in use'}
                            </small>
                        </article>

                        <article className="opd-stat is-amber">
                            <span className="opd-stat-icon">
                                <i className="ti ti-circle-check" aria-hidden="true" />
                            </span>

                            {counts?.completed.delta_pct !== null &&
                                counts?.completed.delta_pct !== undefined && (
                                    <span
                                        className={`opd-stat-chip${counts.completed.delta < 0 ? ' is-down' : ''}`}
                                    >
                                        <i
                                            className={
                                                counts.completed.delta < 0
                                                    ? 'ti ti-arrow-down'
                                                    : 'ti ti-arrow-up'
                                            }
                                            aria-hidden="true"
                                        />
                                        {Math.abs(counts.completed.delta_pct)}%
                                    </span>
                                )}

                            <b>{isLoading ? '—' : (counts?.completed.value ?? 0)}</b>
                            <span className="opd-stat-name">Completed</span>
                            <small>
                                {counts?.completed.average_minutes
                                    ? `${counts.completed.average_minutes} min average`
                                    : `of ${counts?.completed.of_total ?? 0} on the list`}
                            </small>
                        </article>

                        <article className="opd-stat is-rose">
                            <span className="opd-stat-icon">
                                <i className="ti ti-ban" aria-hidden="true" />
                            </span>

                            <b>{isLoading ? '—' : (counts?.no_show.value ?? 0)}</b>
                            <span className="opd-stat-name">No show</span>

                            {/*
                                About no-shows, not about who is running late.
                                The sub-line read "N past their time" off the
                                Expected count, so a card reporting three
                                no-shows explained itself with a number that
                                belonged to the card beside it.
                            */}
                            <small>of {counts?.no_show.of_total ?? 0} on the list</small>
                        </article>
                    </div>

                    {/*
                        The overdue count lives on the Waiting card's own
                        sub-line — "Longest 50 min" — rather than in a banner
                        of its own. A full-width red bar above the numbers
                        pushed the whole dashboard down a row to repeat
                        something already on screen, and a warning that is
                        there most mornings stops being read by the second
                        week.
                    */}

                    <div className="opd-main">
                        {/* ------------------------------------------ left */}
                        {/*
                            Two rows of two, rather than two columns.

                            The cards used to be one tall column beside another and
                            were lined up by arithmetic — a 460px queue against two
                            224s and a 12px gap. That holds exactly until any
                            padding changes, and then nothing says which of the four
                            numbers is now wrong.

                            As a grid the rows match by construction: the queue and
                            the two cards beside it are one row, the charts and the
                            activity feed are the next, and each row is as tall as
                            its tallest cell whatever is in it.
                        */}
                            <Card
                                className="opd-queue-card"
                                title={
                                    <span className="opd-card-title">
                                        <i className="ti ti-users-group" aria-hidden="true" />
                                        {isToday ? "Today's OPD queue" : 'OPD queue'}
                                    </span>
                                }
                                actions={
                                    <div className="opd-queue-tools">
                                        <label className="opd-find">
                                            <i className="ti ti-search" aria-hidden="true" />
                                            <input
                                                type="search"
                                                placeholder="Search in queue…"
                                                value={search}
                                                onChange={(event) =>
                                                    setSearch(event.target.value)
                                                }
                                            />
                                        </label>

                                        <button
                                            type="button"
                                            className="opd-icon-btn"
                                            title="Open the full queue"
                                            onClick={() => navigate(queueLink)}
                                        >
                                            <i className="ti ti-arrows-diagonal" />
                                        </button>
                                    </div>
                                }
                            >
                                <div className="opd-tabs" role="tablist">
                                    {TABS.map((entry) => (
                                        <button
                                            type="button"
                                            key={entry.key}
                                            role="tab"
                                            aria-selected={tab === entry.key}
                                            className={`opd-tab${tab === entry.key ? ' is-on' : ''}`}
                                            onClick={() => setTab(entry.key)}
                                        >
                                            {entry.label}
                                            <em>({data?.tabs[entry.key] ?? 0})</em>
                                        </button>
                                    ))}
                                </div>

                                {isLoading ? (
                                    <LoadingBlock label="Loading the queue…" />
                                ) : rows.length === 0 ? (
                                    <div className="opd-quiet">
                                        <i className="ti ti-users" aria-hidden="true" />
                                        <p>
                                            {search.trim()
                                                ? `Nobody in the queue matches “${search.trim()}”.`
                                                : 'Nobody is in this part of the queue.'}
                                        </p>
                                    </div>
                                ) : (
                                    <div className="opd-table-scroll">
                                        <table className="opd-table">
                                            <thead>
                                                <tr>
                                                    <th className="opd-th-n">#</th>
                                                    <th>Token</th>
                                                    <th>{patients.singular}</th>
                                                    <th>Age / Sex</th>
                                                    <th>Doctor</th>
                                                    <th>Status</th>
                                                    <th>Wait time</th>
                                                    <th className="opd-th-act">Actions</th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                {rows.map((row, index) => {
                                                    const next = row.next_states.find(
                                                        (state) =>
                                                            MOVES[state] &&
                                                            can(MOVES[state].needs),
                                                    );

                                                    return (
                                                        <tr key={row.id}>
                                                            <td className="opd-n">{index + 1}</td>

                                                            <td>
                                                                <span className="opd-tok">
                                                                    {token(row.token_no)}
                                                                </span>
                                                            </td>

                                                            <td>
                                                                <span className="opd-person">
                                                                    <b>{row.customer_name}</b>
                                                                    {row.customer_code && (
                                                                        <code>
                                                                            {row.customer_code}
                                                                        </code>
                                                                    )}
                                                                </span>
                                                            </td>

                                                            <td className="opd-dim">
                                                                {row.age !== null
                                                                    ? `${row.age}`
                                                                    : '—'}
                                                                {row.gender
                                                                    ? ` / ${row.gender.charAt(0).toUpperCase()}`
                                                                    : ''}
                                                            </td>

                                                            <td className="opd-dim">
                                                                {row.doctor_name}
                                                            </td>

                                                            <td>
                                                                <StatusBadge status={row.status} />
                                                            </td>

                                                            <td>
                                                                <WaitBadge
                                                                    minutes={row.waiting_minutes}
                                                                    warn={warn}
                                                                    critical={critical}
                                                                />
                                                            </td>

                                                            <td className="opd-row-act">
                                                                {next ? (
                                                                    <button
                                                                        type="button"
                                                                        className="opd-act"
                                                                        disabled={move.isPending}
                                                                        onClick={() =>
                                                                            onMove(row, next)
                                                                        }
                                                                    >
                                                                        <i
                                                                            className={
                                                                                MOVES[next].icon
                                                                            }
                                                                            aria-hidden="true"
                                                                        />
                                                                        {MOVES[next].label}
                                                                    </button>
                                                                ) : (
                                                                    <Link
                                                                        className="opd-act is-quiet"
                                                                        to={queueLink}
                                                                    >
                                                                        View
                                                                    </Link>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                <div className="opd-queue-foot">
                                    {truncated && (
                                        <span className="opd-showing">
                                            Showing {rows.length} of {inTab}
                                        </span>
                                    )}

                                    <Link className="opd-more" to={queueLink}>
                                        View the whole queue
                                        <i className="ti ti-arrow-right" aria-hidden="true" />
                                    </Link>
                                </div>
                            </Card>


                        <div className="opd-stack">
                            <Card
                                title={
                                    <span className="opd-card-title">
                                        <i className="ti ti-user-heart" aria-hidden="true" />
                                        Doctors today
                                    </span>
                                }
                                actions={
                                    <Link className="opd-viewall" to="/doctors">
                                        View all
                                    </Link>
                                }
                            >
                                {isLoading ? (
                                    <LoadingBlock label="Loading…" />
                                ) : (data?.doctors ?? []).length === 0 ? (
                                    <div className="opd-quiet">
                                        <i className="ti ti-calendar-off" aria-hidden="true" />
                                        <p>Nobody is booked with anyone here.</p>
                                    </div>
                                ) : (
                                    <ul className="opd-list">
                                        {(data?.doctors ?? []).map((doctor) => {
                                            const state = DOCTOR_STATE[doctor.state];

                                            return (
                                                <li key={doctor.id} className="opd-doc">
                                                    <span
                                                        className="opd-avatar"
                                                        aria-hidden="true"
                                                    >
                                                        {(doctor.name ?? '?')
                                                            .replace(/^Dr\.?\s*/i, '')
                                                            .charAt(0)}
                                                    </span>

                                                    <span className="opd-doc-who">
                                                        <b>
                                                            {doctor.name}
                                                            <em
                                                                className={`opd-pin is-${state.tone}`}
                                                            >
                                                                {state.label}
                                                            </em>
                                                        </b>
                                                        <small>
                                                            {doctor.waiting + doctor.seen}{' '}
                                                            {patients.plural.toLowerCase()}
                                                            {doctor.waiting > 0
                                                                ? ` · ${doctor.waiting} waiting`
                                                                : ''}
                                                        </small>
                                                    </span>

                                                    {/*
                                                        How long they are taking, or — when nothing
                                                        has finished yet — how long the person at
                                                        the front of their list has been sitting
                                                        there. Either way it is the number that
                                                        says whether to walk over.
                                                    */}
                                                    <span className="opd-doc-avg">
                                                        {doctor.average_minutes ? (
                                                            <>
                                                                <b>~{doctor.average_minutes} min</b>
                                                                <small>Avg. time</small>
                                                            </>
                                                        ) : doctor.longest_wait > 0 ? (
                                                            <>
                                                                <b>{doctor.longest_wait} min</b>
                                                                <small>Longest wait</small>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <b>—</b>
                                                                <small>No data</small>
                                                            </>
                                                        )}
                                                    </span>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </Card>

                            <Card
                                title={
                                    <span className="opd-card-title">
                                        <i className="ti ti-calendar-event" aria-hidden="true" />
                                        Upcoming appointments
                                    </span>
                                }
                                actions={
                                    <Link className="opd-viewall" to={queueLink}>
                                        View all
                                    </Link>
                                }
                            >
                                {isLoading ? (
                                    <LoadingBlock label="Loading…" />
                                ) : (data?.upcoming ?? []).length === 0 ? (
                                    <div className="opd-quiet">
                                        <i className="ti ti-calendar-check" aria-hidden="true" />
                                        <p>Nobody else is expected.</p>
                                    </div>
                                ) : (
                                    <ul className="opd-list">
                                        {(data?.upcoming ?? []).map((row) => (
                                            <li key={row.id} className="opd-next">
                                                <b className="opd-next-at">{row.slot_at}</b>

                                                <span className="opd-next-who">
                                                    <b>{row.customer_name}</b>
                                                </span>

                                                <span className="opd-next-doc">
                                                    {row.doctor_name}
                                                </span>

                                                {/* Their time has passed and they
                                                    are still not here — the row
                                                    somebody should ring. */}
                                                {row.overdue ? (
                                                    <span className="opd-pin is-rose">Overdue</span>
                                                ) : row.specialisation ? (
                                                    <span className="opd-kind">
                                                        <i
                                                            aria-hidden="true"
                                                            style={{
                                                                background: `var(--cat-${hueFor(row.specialisation)})`,
                                                            }}
                                                        />
                                                        {row.specialisation}
                                                    </span>
                                                ) : (
                                                    <span className="opd-dim">Consultation</span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        </div>

                            <div className="opd-charts">
                                <Card
                                    title={
                                        <span className="opd-card-title">
                                            <i className="ti ti-chart-histogram" aria-hidden="true" />
                                            {patients.singular} flow
                                        </span>
                                    }
                                    description="Arrivals each hour, new against returning."
                                >
                                    {isLoading ? (
                                        <LoadingBlock label="Loading…" />
                                    ) : (data?.flow ?? []).length === 0 ? (
                                        <div className="opd-quiet">
                                            <i className="ti ti-clock-off" aria-hidden="true" />
                                            <p>Nobody has checked in yet.</p>
                                        </div>
                                    ) : (
                                        <FlowChart points={data?.flow ?? []} />
                                    )}
                                </Card>

                                <Card
                                    title={
                                        <span className="opd-card-title">
                                            <i className="ti ti-chart-pie" aria-hidden="true" />
                                            By speciality
                                        </span>
                                    }
                                    description="Today's list, by what the doctor does."
                                >
                                    {isLoading ? (
                                        <LoadingBlock label="Loading…" />
                                    ) : (
                                        <DonutChart
                                            slices={(data?.departments ?? []).map((entry) => ({
                                                label: entry.label,
                                                value: entry.value,
                                            }))}
                                            centreLabel={patients.plural}
                                            empty="Nobody on the list yet."
                                        />
                                    )}
                                </Card>
                            </div>

                            <Card
                                title={
                                    <span className="opd-card-title">
                                        <i className="ti ti-history" aria-hidden="true" />
                                        Recent activity
                                    </span>
                                }
                                actions={
                                    can('settings.audit') && (
                                        <Link className="opd-viewall" to="/activity">
                                            View all
                                        </Link>
                                    )
                                }
                            >
                                {isLoading ? (
                                    <LoadingBlock label="Loading…" />
                                ) : (data?.activity ?? []).length === 0 ? (
                                    <div className="opd-quiet">
                                        <i className="ti ti-history-off" aria-hidden="true" />
                                        <p>Nothing has happened yet.</p>
                                    </div>
                                ) : (
                                    <ul className="opd-list">
                                        {(data?.activity ?? []).map((row) => {
                                            const kind = ACTIVITY[row.event] ?? ACTIVITY.updated;

                                            return (
                                                <li key={row.id} className="opd-feed">
                                                    <span
                                                        className={`opd-feed-icon is-${kind.tone}`}
                                                        aria-hidden="true"
                                                    >
                                                        <i
                                                            className={
                                                                ENTITY_ICON[row.entity_type] ??
                                                                'ti ti-point'
                                                            }
                                                        />
                                                    </span>

                                                    <span className="opd-feed-what">
                                                        <b>
                                                            {row.entity_type} {kind.verb}
                                                        </b>
                                                        <small>{row.entity_label}</small>
                                                        <em>
                                                            {ago(row.created_at)}
                                                            {row.actor_name
                                                                ? ` · ${row.actor_name}`
                                                                : ''}
                                                        </em>
                                                    </span>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </Card>
                    </div>
                </>
            )}

            {canBook && (
                <BookDialog
                    open={booking !== null}
                    onClose={() => {
                        setBooking(null);
                        setReturned(null);
                    }}
                    preselect={returned}
                    locationId={branchId}
                    date={isToday ? date : todayString()}
                    branchName={branch?.name ?? ''}
                    defaultType={booking ?? 'walk_in'}
                />
            )}
        </div>
    );
}
