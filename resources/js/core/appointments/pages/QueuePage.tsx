import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useEntityLabel } from '@/core/field-settings/api';
import { useOpdContext } from '@/core/opd/useOpdContext';
import { OpdToolbar } from '@/core/opd/components/OpdToolbar';
import { StatusBadge, WaitBadge } from '@/core/opd/components/Badges';
import { WaitTrend, type WaitPoint } from '../components/WaitTrend';
import { BookDialog } from '../components/BookDialog';
import { useMoveAppointment, useQueue } from '../api';
import type { Appointment, AppointmentStatus } from '../types';

/**
 * Each move: what it is called, and the capability the server demands for it.
 *
 * The capability is here rather than checked at the call site so the two can
 * never drift. A button whose capability this person does not hold is not
 * rendered greyed out — it is not rendered, because the route behind it
 * answers 403 and offering it is worse than offering nothing.
 */
const ACTIONS: Record<
    string,
    { label: string; icon: string; action: string; needs: string; danger?: boolean }
> = {
    checked_in: {
        label: 'Check in',
        icon: 'ti ti-login',
        action: 'check-in',
        needs: 'appointments.queue',
    },
    in_consultation: {
        label: 'Call in',
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
    no_show: {
        label: 'No-show',
        icon: 'ti ti-user-x',
        action: 'no-show',
        needs: 'appointments.cancel',
        danger: true,
    },
    cancelled: {
        label: 'Cancel',
        icon: 'ti ti-x',
        action: 'cancel',
        needs: 'appointments.cancel',
        danger: true,
    },
};

/**
 * Rows on a page.
 *
 * Twenty is about a screen and a half on a laptop, which is the right amount
 * to scroll looking for somebody. A busy branch runs to eighty or a hundred in
 * a day, and rendering all of them at once made the tab counts the only usable
 * way to find anything.
 */
const PER_PAGE = 20;

/**
 * The status filters over the day's list.
 *
 * Every one of these is a state the appointment table can actually be in.
 * There is deliberately no "With nurse": the state machine has no such status,
 * and a tab that can only ever read zero is a promise the product does not
 * keep. It arrives with the visit work that gives nurses something to record.
 */
const STATUS_TABS: { key: string; label: string; match: AppointmentStatus[] }[] = [
    { key: 'all', label: 'All', match: [] },
    { key: 'checked_in', label: 'Waiting', match: ['checked_in'] },
    { key: 'in_consultation', label: 'With doctor', match: ['in_consultation'] },
    { key: 'booked', label: 'Expected', match: ['booked'] },
    { key: 'completed', label: 'Completed', match: ['completed'] },
    { key: 'no_show', label: 'No show', match: ['no_show', 'cancelled'] },
];

/**
 * A stable hue per doctor, from the id rather than the row's position.
 *
 * Tokens are unique per doctor per day, so a branch-wide list shows three
 * doctors each starting at 1 — a column of "1, 1, 1, 2, 2, 2" that reads as a
 * bug. Tinting the badge by doctor makes the repeat legible without inventing
 * a compound number nobody would recognise when it is called out.
 *
 * Keyed on the doctor, never on their place in the list: a filter that removes
 * one must not repaint the others.
 */
function doctorHue(id: number): number {
    return ((id * 7) % 6) + 1;
}

/** Age in years, when there is a date of birth to work it out from. */
function ageFrom(dob: string | null | undefined): number | null {
    if (!dob) {
        return null;
    }

    const born = new Date(dob);
    const now = new Date();
    let years = now.getFullYear() - born.getFullYear();
    const month = now.getMonth() - born.getMonth();

    if (month < 0 || (month === 0 && now.getDate() < born.getDate())) {
        years -= 1;
    }

    return years >= 0 && years < 150 ? years : null;
}

/**
 * The OPD queue: one branch, one day, every doctor.
 *
 * Branch-wide rather than per-doctor. Somebody at the desk is looking for a
 * patient, and having to guess which of six doctors they belong to before the
 * list appears was the wrong question — the doctor is a filter now.
 *
 * Booked patients and walk-ins are one list in arrival order. A booked patient
 * is **not** floated to the top: their time is shown so the desk can call
 * somebody out of turn on purpose, which is a person's judgement rather than a
 * rule applied behind their back.
 */
export default function QueuePage() {
    const confirm = useConfirm();
    const { can, doctorId: mine } = useTenantAuth();
    const patients = useEntityLabel('customer');

    const context = useOpdContext();
    const { branchId, date, branch, branchesLoading, hasNoBranch, isToday } = context;

    /*
     * A doctor's own account opens on their own list.
     *
     * `mine` is set only when this login belongs to a doctor, which is almost
     * nobody — so for the desk this is the whole branch, as before. For a
     * doctor it is the one thing standing between them and the screen they
     * actually want, and the filter chips are still there to widen it.
     */
    const [doctorId, setDoctorId] = useState<number | ''>(mine ?? '');
    const [booking, setBooking] = useState(false);

    /*
     * Two readings of one list.
     *
     * The table is for scanning a column — every wait down one edge, every
     * department down another. The cards are for reading one person, with the
     * name larger and nothing truncated to hold a grid together. Which is
     * right depends on whether somebody is looking FOR a patient or AT the
     * department, and that changes several times an hour, so it is a control
     * rather than a decision made once in the code.
     */
    const [view, setView] = useState<'table' | 'cards'>('table');
    const [statusTab, setStatusTab] = useState('all');
    const [page, setPage] = useState(1);
    const [find, setFind] = useState('');

    /*
     * Somebody just registered on the patient form and came back.
     *
     * The dialog reopens with them chosen, so the round trip ends where it
     * started rather than at an empty search box. The parameter is cleared as
     * soon as it is used — a refresh should not reopen a booking that was
     * already made.
     */
    const [params, setParams] = useSearchParams();
    const [returned, setReturned] = useState<number | null>(null);

    useEffect(() => {
        const id = Number(params.get('patient'));

        if (!id) {
            return;
        }

        setReturned(id);
        setBooking(true);

        /*
         * Cleared as soon as it is used. Left in the URL, a refresh would
         * reopen a booking that has already been made.
         */
        const next = new URLSearchParams(params);
        next.delete('patient');
        setParams(next, { replace: true });
    }, [params, setParams]);
    const [menuFor, setMenuFor] = useState<number | null>(null);

    const { data, isLoading, isError, refetch } = useQueue(doctorId, branchId, date);
    const move = useMoveAppointment();

    /*
     * Any change to what is being listed starts at the top of it.
     *
     * Staying on page three after switching to a tab with one page shows an
     * empty table under a count that says otherwise — which reads as a bug
     * rather than as the end of a list.
     */
    useEffect(() => setPage(1), [statusTab, find, doctorId, branchId, date]);

    // A doctor filter is meaningless at another branch or on another day, and
    // silently keeping it makes the queue look empty for no stated reason.
    // A doctor falls back to their own list rather than to everybody.
    useEffect(() => setDoctorId(mine ?? ''), [branchId, date, mine]);

    const menuBox = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (menuFor === null) {
            return;
        }

        function onOutside(event: MouseEvent) {
            if (menuBox.current && !menuBox.current.contains(event.target as Node)) {
                setMenuFor(null);
            }
        }

        // Escape closes it. A menu holding only destructive moves must have a
        // way out that is not "click one of them".
        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setMenuFor(null);
            }
        }

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [menuFor]);

    async function onMove(appointment: Appointment, next: AppointmentStatus) {
        const action = ACTIONS[next];

        setMenuFor(null);

        if (!action) {
            return;
        }

        /*
         * Three of the five are asked about, for two different reasons.
         *
         * Cancel and no-show cannot be undone and are about a person. "Done"
         * cannot be undone either — `completed` has no transitions out of it,
         * so nobody, not even the owner, can reopen a consultation closed by a
         * mis-click. Check-in and call-in are the ordinary rhythm of a busy
         * desk and must not cost a click each.
         */
        const asks: Partial<Record<AppointmentStatus, { title: string; message: string }>> = {
            cancelled: {
                title: 'Cancel this appointment?',
                message: `${appointment.customer_name}'s slot goes back into the day. Their token, if issued, is not reused.`,
            },
            no_show: {
                title: 'Mark as a no-show?',
                message: `${appointment.customer_name} will be recorded as not having come.`,
            },
            completed: {
                title: 'Finish this consultation?',
                message: `${appointment.customer_name}'s visit is closed. This cannot be reopened.`,
            },
        };

        const ask = asks[next];

        if (ask) {
            const confirmed = await confirm({
                ...ask,
                confirmLabel: action.label,
                danger: next !== 'completed',
            });

            if (!confirmed) {
                return;
            }
        }

        move.mutate({ id: appointment.id, action: action.action as never });
    }

    /** What this person may actually do to this row, split by prominence. */
    function movesFor(row: Appointment) {
        const allowed = row.next_states.filter((next) => {
            const action = ACTIONS[next];

            return action && can(action.needs);
        });

        return {
            primary: allowed.find((next) => !ACTIONS[next].danger) ?? null,
            /*
             * Destructive moves live behind a menu rather than beside the
             * primary one. On a tablet the two would be a thumb's width apart,
             * and cancelling somebody who is already with the doctor is a
             * mis-click, never an intention.
             */
            rest: allowed.filter((next) => ACTIONS[next].danger),
        };
    }

    const canBook = can('appointments.book');

    if (branchesLoading) {
        return <LoadingBlock label="Loading…" />;
    }

    if (hasNoBranch) {
        return (
            <>
                <PageHeader
                    title="Queue"
                    subtitle="Booked patients and walk-ins, in the order they arrived."
                    icon="ti ti-list-check"
                    tone="sky"
                    crumbs={[{ label: 'OPD', to: '/opd' }, { label: 'Queue' }]}
                />

                <Card>
                    <div className="org-pending">
                        <i className="ti ti-map-pin-off" />
                        <h6>You are not attached to a branch</h6>
                        <p>
                            A queue belongs to a branch. Ask whoever manages your organization to
                            add you to the one you work at.
                        </p>
                    </div>
                </Card>
            </>
        );
    }

    const all = data?.queue ?? [];

    /*
     * The search box narrows what is already here rather than asking the
     * server again: the day's list is tens of rows, not thousands, and a
     * round trip per keystroke would make the one thing done most often at
     * this desk the slowest.
     */
    const term = find.trim().toLowerCase();

    /* How many sit behind each tab, counted from the whole day so the numbers
       on them do not change when one is pressed. */
    const tabCounts = Object.fromEntries(
        STATUS_TABS.map((tab) => [
            tab.key,
            tab.match.length === 0
                ? all.filter((row) => row.status !== 'cancelled').length
                : all.filter((row) => tab.match.includes(row.status)).length,
        ]),
    );

    const wanted = STATUS_TABS.find((tab) => tab.key === statusTab)?.match ?? [];

    const shown =
        wanted.length === 0 ? all.filter((row) => row.status !== 'cancelled') : all.filter((row) => wanted.includes(row.status));

    const matching = term
        ? shown.filter(
              (row) =>
                  row.customer_name?.toLowerCase().includes(term) ||
                  row.customer_code?.toLowerCase().includes(term) ||
                  row.customer_phone?.includes(term) ||
                  row.doctor_name?.toLowerCase().includes(term) ||
                  row.doctor_specialisation?.toLowerCase().includes(term) ||
                  String(row.token_no ?? '').includes(term),
          )
        : shown;

    const pageCount = Math.max(1, Math.ceil(matching.length / PER_PAGE));

    /*
     * Clamped rather than trusted.
     *
     * The page is reset when the filters change, but a row moving status under
     * a live refresh can shrink the list on its own — and a page number past
     * the end would then render nothing at all.
     */
    const current = Math.min(page, pageCount);
    const from = (current - 1) * PER_PAGE;

    const rows = matching.slice(from, from + PER_PAGE);

    /*
     * Every doctor's own share of the queue.
     *
     * Computed here rather than fetched: the endpoint already sends every row
     * for the day with its doctor and status on it, so a second request would
     * be asking for something already in hand — and could disagree with the
     * list underneath it.
     */
    const byDoctor = (data?.doctors ?? [])
        .map((doctor) => {
            const theirs = all.filter((row) => row.doctor_id === doctor.id);

            return {
                id: doctor.id,
                name: doctor.name,
                waiting: theirs.filter((row) => row.status === 'checked_in').length,
                inConsult: theirs.filter((row) => row.status === 'in_consultation').length,
                seen: theirs.filter((row) => row.status === 'completed').length,
            };
        })
        .sort((a, b) => b.waiting - a.waiting);

    const busiest = Math.max(1, ...byDoctor.map((doctor) => doctor.waiting));

    /*
     * The average wait, hour by hour, from the two timestamps on each row.
     *
     * Only people who have actually been called in count: somebody still
     * standing there has not finished waiting, and folding their running total
     * into the average makes it climb for as long as they stand there.
     */
    const waitByHour: WaitPoint[] = (() => {
        const buckets = new Map<number, number[]>();

        for (const row of all) {
            if (!row.checked_in_at || !row.started_at) {
                continue;
            }

            const arrived = new Date(row.checked_in_at);
            const called = new Date(row.started_at);
            const minutes = Math.max(0, Math.round((called.getTime() - arrived.getTime()) / 60000));

            const hour = arrived.getHours();

            buckets.set(hour, [...(buckets.get(hour) ?? []), minutes]);
        }

        return [...buckets.entries()]
            .sort(([a], [b]) => a - b)
            .map(([hour, waits]) => ({
                label: `${hour % 12 === 0 ? 12 : hour % 12} ${hour < 12 ? 'AM' : 'PM'}`,
                minutes: Math.round(waits.reduce((sum, at) => sum + at, 0) / waits.length),
                of: waits.length,
            }));
    })();

    /*
     * Who "Call next" calls.
     *
     * The longest wait among people actually checked in — not the lowest token,
     * which would call somebody who booked for later ahead of a walk-in who has
     * been sitting there half an hour.
     */
    const nextUp = all
        .filter((row) => row.status === 'checked_in')
        .reduce<Appointment | null>(
            (worst, row) =>
                worst === null || (row.waiting_minutes ?? 0) > (worst.waiting_minutes ?? 0)
                    ? row
                    : worst,
            null,
        );

    /* The figures above the list, read off the same day the list came from. */
    const counts = {
        total: all.filter((row) => row.status !== 'cancelled').length,
        noShow: all.filter((row) => row.status === 'no_show').length,
        longest: all.reduce((worst, row) => Math.max(worst, row.waiting_minutes ?? 0), 0),
    };

    return (
        <>
            {/*
                The day, and what to do about it, in the page's own header.

                The date used to sit in a bar of its own under the title and the
                doctor filter beside it — a second full-width band before any of
                the day's numbers. Both belong to the heading: which day this is
                and who is being looked at are what the title is describing.
            */}
            <PageHeader
                title="Queue Management"
                subtitle={
                    branch
                        ? `Manage ${isToday ? "today's" : "the day's"} OPD queue at ${branch.name}, call patients, and track live status.`
                        : "Manage today's OPD queue, call patients, and track live status."
                }
                icon="ti ti-list-check"
                tone="sky"
                crumbs={[{ label: 'OPD', to: '/opd' }, { label: 'Queue' }]}
                actions={
                    <div className="q-head-acts">
                        <OpdToolbar context={context} compact />

                        {canBook && (
                            <Button
                                variant="light"
                                icon="ti ti-user-plus"
                                disabled={!branchId}
                                onClick={() => setBooking(true)}
                            >
                                Walk-in
                            </Button>
                        )}

                        {/*
                            The single most repeated action at an OPD desk, and
                            until now it took finding the right row first. It
                            calls in whoever has waited longest — which is what
                            somebody does by hand every time, only slower and
                            occasionally wrong.
                        */}
                        {can('appointments.queue') && isToday && (
                            <Button
                                icon="ti ti-bell-ringing"
                                disabled={!nextUp || move.isPending}
                                onClick={() => nextUp && onMove(nextUp, 'in_consultation')}
                            >
                                Call next
                            </Button>
                        )}
                    </div>
                }
            />

            {/*
                The day in six figures, in the same cards the department board
                uses.

                This was a thin strip of "6 waiting · 1 with a doctor" set in
                the body text — accurate, and completely unreadable from more
                than a foot away. The queue is looked at from across a desk all
                morning; the numbers on it should carry at that distance.
            */}
            <div className="opd-stats is-queue">
                <article className="opd-stat is-sky">
                    <span className="opd-stat-icon">
                        <i className="ti ti-users" aria-hidden="true" />
                    </span>
                    <b>{isLoading ? '—' : counts.total}</b>
                    <span className="opd-stat-name">Total {patients.plural.toLowerCase()}</span>
                    <small>on this day</small>
                </article>

                <article className="opd-stat is-violet">
                    <span className="opd-stat-icon">
                        <i className="ti ti-hourglass" aria-hidden="true" />
                    </span>
                    <b>{isLoading ? '—' : (data?.waiting ?? 0)}</b>
                    <span className="opd-stat-name">Waiting</span>
                    <small>{counts.longest ? `Longest ${counts.longest} min` : 'Nobody waiting'}</small>
                </article>

                <article className="opd-stat is-teal">
                    <span className="opd-stat-icon">
                        <i className="ti ti-stethoscope" aria-hidden="true" />
                    </span>
                    <b>{isLoading ? '—' : (data?.with_doctor ?? 0)}</b>
                    <span className="opd-stat-name">With a doctor</span>
                    <small>in a room now</small>
                </article>

                <article className="opd-stat is-amber">
                    <span className="opd-stat-icon">
                        <i className="ti ti-circle-check" aria-hidden="true" />
                    </span>
                    <b>{isLoading ? '—' : (data?.seen ?? 0)}</b>
                    <span className="opd-stat-name">Completed</span>
                    <small>of {counts.total} on the list</small>
                </article>

                <article className="opd-stat is-indigo">
                    <span className="opd-stat-icon">
                        <i className="ti ti-calendar-time" aria-hidden="true" />
                    </span>
                    <b>{isLoading ? '—' : (data?.expected ?? 0)}</b>
                    <span className="opd-stat-name">Expected</span>
                    <small>booked, not yet here</small>
                </article>

                <article className="opd-stat is-rose">
                    <span className="opd-stat-icon">
                        <i className="ti ti-ban" aria-hidden="true" />
                    </span>
                    <b>{isLoading ? '—' : counts.noShow}</b>
                    <span className="opd-stat-name">No show</span>
                    <small>did not come</small>
                </article>
            </div>

            {/*
                No card title. The page is called Queue Management and this is
                the only list on it, so a heading reading "The queue" bought a
                whole row to repeat the title — and pushed the filters, which
                are the thing actually used, below the fold on a laptop.
            */}
            <Card className="q-card">
                <div className="q-bar">
                    <div className="q-tabs" role="tablist">
                        {STATUS_TABS.map((tab) => (
                            <button
                                type="button"
                                key={tab.key}
                                role="tab"
                                aria-selected={statusTab === tab.key}
                                className={`q-tab${statusTab === tab.key ? ' is-on' : ''}`}
                                onClick={() => setStatusTab(tab.key)}
                            >
                                {tab.label}
                                <em>({tabCounts[tab.key] ?? 0})</em>
                            </button>
                        ))}
                    </div>

                    <div className="q-tools">
                        {/*
                            Built from the day's own list rather than every
                            doctor on the books: one with nobody booked is not a
                            filter anybody wants, and offering them returns an
                            empty queue that reads as a fault.
                        */}
                        {(data?.doctors ?? []).length > 1 && (
                            <select
                                className="q-doctor-pick"
                                value={doctorId}
                                onChange={(event) =>
                                    setDoctorId(
                                        event.target.value === '' ? '' : Number(event.target.value),
                                    )
                                }
                                aria-label="Filter by doctor"
                            >
                                <option value="">All doctors</option>
                                {(data?.doctors ?? []).map((doctor) => (
                                    <option key={doctor.id} value={doctor.id}>
                                        {doctor.name}
                                    </option>
                                ))}
                            </select>
                        )}

                        <label className="opd-find">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="search"
                                placeholder="Search in queue…"
                                value={find}
                                onChange={(event) => setFind(event.target.value)}
                            />
                        </label>

                        {/*
                            Two readings of the same list, not two lists.

                            The table is for scanning a column — every wait
                            time down one edge, every department down another.
                            The cards are for reading one person: the name is
                            larger, the identifiers sit under it, and nothing
                            is truncated to hold a grid together. Which one is
                            right depends on whether somebody is looking for a
                            patient or looking at the department, and that
                            changes several times an hour.
                        */}
                        <div className="q-views" role="group" aria-label="How to show the queue">
                            <button
                                type="button"
                                className={`q-view${view === 'table' ? ' is-on' : ''}`}
                                aria-pressed={view === 'table'}
                                onClick={() => setView('table')}
                            >
                                <i className="ti ti-table" aria-hidden="true" />
                                Table
                            </button>

                            <button
                                type="button"
                                className={`q-view${view === 'cards' ? ' is-on' : ''}`}
                                aria-pressed={view === 'cards'}
                                onClick={() => setView('cards')}
                            >
                                <i className="ti ti-layout-list" aria-hidden="true" />
                                Cards
                            </button>
                        </div>

                        <Link
                            className="q-board"
                            to={`/opd?branch=${branchId}&date=${date}`}
                        >
                            <i className="ti ti-layout-dashboard" aria-hidden="true" />
                            Board
                        </Link>
                    </div>
                </div>


                {isLoading ? (
                    <LoadingBlock label="Loading the queue…" />
                ) : isError ? (
                    <ErrorState message="Unable to load the queue." onRetry={() => refetch()} />
                ) : rows.length === 0 ? (
                    <div className="org-pending">
                        <i className="ti ti-users" />
                        <h6>{isToday ? 'Nobody in the queue' : 'Nothing on this day'}</h6>
                        <p>
                            {doctorId !== ''
                                ? 'Nobody is with this doctor here. Clear the filter to see the whole branch.'
                                : isToday
                                  ? `No ${patients.plural.toLowerCase()} are booked or waiting here today.`
                                  : 'Nothing was booked here on this day.'}
                        </p>

                        {doctorId !== '' ? (
                            <button
                                type="button"
                                className="btn-tone btn-tone--solid"
                                onClick={() => setDoctorId('')}
                            >
                                <i className="ti ti-filter-off" />
                                Show every doctor
                            </button>
                        ) : (
                            canBook &&
                            isToday && (
                                <button
                                    type="button"
                                    className="btn-tone btn-tone--solid"
                                    onClick={() => setBooking(true)}
                                >
                                    <i className="ti ti-plus" />
                                    Take a walk-in
                                </button>
                            )
                        )}
                    </div>
                ) : view === 'table' ? (
                    <div className="q-table-scroll">
                        <table className="q-table">
                            <thead>
                                <tr>
                                    <th className="q-th-n">#</th>
                                    <th>Token</th>
                                    <th>{patients.singular}</th>
                                    <th>Age / Sex</th>
                                    <th>Doctor</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Wait</th>
                                    <th className="q-th-act">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                {rows.map((row, index) => {
                                    const { primary, rest } = movesFor(row);
                                    const age = ageFrom(row.customer_dob);

                                    return (
                                        <tr key={row.id}>
                                            <td className="q-n">{index + 1}</td>

                                            <td>
                                                <span
                                                    className={`q-token${row.token_no === null ? ' is-empty' : ''}`}
                                                    style={
                                                        row.token_no === null
                                                            ? undefined
                                                            : {
                                                                  color: `var(--cat-${doctorHue(row.doctor_id)})`,
                                                              }
                                                    }
                                                >
                                                    {row.token_no ?? '—'}
                                                </span>
                                            </td>

                                            <td>
                                                <span className="q-person">
                                                    <span className="q-face" aria-hidden="true">
                                                        {(row.customer_name ?? '?').charAt(0)}
                                                    </span>

                                                    <span className="q-person-text">
                                                        <b>{row.customer_name}</b>
                                                        {row.customer_code && (
                                                            <code>{row.customer_code}</code>
                                                        )}
                                                    </span>
                                                </span>
                                            </td>

                                            <td className="q-dim">
                                                {age !== null ? age : '—'}
                                                {row.customer_gender
                                                    ? ` / ${row.customer_gender.charAt(0).toUpperCase()}`
                                                    : ''}
                                            </td>

                                            <td className="q-dim">{row.doctor_name}</td>

                                            <td>
                                                {row.doctor_specialisation ? (
                                                    <span className="q-dept">
                                                        {row.doctor_specialisation}
                                                    </span>
                                                ) : (
                                                    <span className="q-dim">—</span>
                                                )}
                                            </td>

                                            <td>
                                                <StatusBadge status={row.status} />
                                            </td>

                                            <td>
                                                <WaitBadge
                                                    minutes={row.waiting_minutes}
                                                    warn={data?.thresholds.warn ?? 10}
                                                    critical={data?.thresholds.critical ?? 20}
                                                />
                                            </td>

                                            <td className="q-row-act">
                                                <div className="q-actions">
                                                    {primary && (
                                                        <button
                                                            type="button"
                                                            className="q-act is-primary"
                                                            disabled={move.isPending}
                                                            onClick={() => onMove(row, primary)}
                                                        >
                                                            <i className={ACTIONS[primary].icon} />
                                                            {ACTIONS[primary].label}
                                                        </button>
                                                    )}

                                                    {rest.length > 0 && (
                                                        <div
                                                            className="q-more"
                                                            ref={
                                                                menuFor === row.id
                                                                    ? menuBox
                                                                    : undefined
                                                            }
                                                        >
                                                            <button
                                                                type="button"
                                                                className="q-act is-icon"
                                                                aria-label={`More actions for ${row.customer_name}`}
                                                                aria-haspopup="menu"
                                                                aria-expanded={menuFor === row.id}
                                                                onClick={() =>
                                                                    setMenuFor(
                                                                        menuFor === row.id
                                                                            ? null
                                                                            : row.id,
                                                                    )
                                                                }
                                                            >
                                                                <i className="ti ti-dots-vertical" />
                                                            </button>

                                                            {menuFor === row.id && (
                                                                <div className="q-menu" role="menu">
                                                                    {rest.map((next) => (
                                                                        <button
                                                                            type="button"
                                                                            key={next}
                                                                            role="menuitem"
                                                                            className="q-menu-item is-danger"
                                                                            onClick={() =>
                                                                                onMove(row, next)
                                                                            }
                                                                        >
                                                                            <i
                                                                                className={
                                                                                    ACTIONS[next]
                                                                                        .icon
                                                                                }
                                                                            />
                                                                            {ACTIONS[next].label}
                                                                        </button>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}

                                                    {!primary && rest.length === 0 && (
                                                        <span className="q-done">
                                                            {row.next_states.length === 0
                                                                ? 'Closed'
                                                                : 'View only'}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <ul className="q">
                        {rows.map((row) => {
                            const { primary, rest } = movesFor(row);
                            const age = ageFrom(row.customer_dob);

                            return (
                                <li className={`q-row is-${row.status}`} key={row.id}>
                                    <span
                                        className={`q-token${row.token_no === null ? ' is-empty' : ''}`}
                                        style={
                                            row.token_no === null
                                                ? undefined
                                                : {
                                                      color: `var(--cat-${doctorHue(row.doctor_id)})`,
                                                  }
                                        }
                                        title={
                                            row.token_no === null
                                                ? 'No token yet — not checked in'
                                                : `Token ${row.token_no} for ${row.doctor_name}`
                                        }
                                    >
                                        {row.token_no ?? '—'}
                                    </span>

                                    <div className="q-who">
                                        <b>{row.customer_name}</b>
                                        <span>
                                            {/* How this Rahul Sharma is told
                                                apart from the other one. */}
                                            {row.customer_code && (
                                                <code className="q-pid">{row.customer_code}</code>
                                            )}
                                            {age !== null && <em>{age}y</em>}
                                            {row.customer_phone && (
                                                <code>{row.customer_phone}</code>
                                            )}
                                            {row.type === 'walk_in' ? (
                                                <em className="q-walkin">walk-in</em>
                                            ) : (
                                                row.slot_at && <em>booked {row.slot_at}</em>
                                            )}
                                        </span>
                                    </div>

                                    {/* Only worth a column when the list holds
                                        more than one doctor's patients. */}
                                    <span className="q-doctor">{row.doctor_name}</span>

                                    <StatusBadge status={row.status} />

                                    <WaitBadge
                                        minutes={row.waiting_minutes}
                                        warn={data?.thresholds.warn ?? 10}
                                        critical={data?.thresholds.critical ?? 20}
                                    />

                                    <div className="q-actions">
                                        {primary && (
                                            <button
                                                type="button"
                                                className="q-act is-primary"
                                                disabled={move.isPending}
                                                onClick={() => onMove(row, primary)}
                                            >
                                                <i className={ACTIONS[primary].icon} />
                                                {ACTIONS[primary].label}
                                            </button>
                                        )}

                                        {rest.length > 0 && (
                                            <div
                                                className="q-more"
                                                ref={menuFor === row.id ? menuBox : undefined}
                                            >
                                                <button
                                                    type="button"
                                                    className="q-act is-icon"
                                                    aria-label={`More actions for ${row.customer_name}`}
                                                    aria-haspopup="menu"
                                                    aria-expanded={menuFor === row.id}
                                                    onClick={() =>
                                                        setMenuFor(
                                                            menuFor === row.id ? null : row.id,
                                                        )
                                                    }
                                                >
                                                    <i className="ti ti-dots-vertical" />
                                                </button>

                                                {menuFor === row.id && (
                                                    <div className="q-menu" role="menu">
                                                        {rest.map((next) => (
                                                            <button
                                                                type="button"
                                                                key={next}
                                                                role="menuitem"
                                                                className="q-menu-item is-danger"
                                                                onClick={() => onMove(row, next)}
                                                            >
                                                                <i className={ACTIONS[next].icon} />
                                                                {ACTIONS[next].label}
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Says why there is nothing to do,
                                            rather than leaving a blank column
                                            that reads as a rendering fault. */}
                                        {!primary && rest.length === 0 && (
                                            <span className="q-done">
                                                {row.next_states.length === 0
                                                    ? 'Closed'
                                                    : 'View only'}
                                            </span>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {/*
                    Under both views, because it pages the same list — the
                    table and the cards are two readings of one set of rows.
                */}
                {!isLoading && !isError && matching.length > 0 && (
                    <Pagination
                        page={current}
                        pageCount={pageCount}
                        total={matching.length}
                        perPage={PER_PAGE}
                        onChange={setPage}
                    />
                )}
            </Card>

            {/*
                The two questions the list itself cannot answer: whose queue is
                the problem, and whether the wait is growing.

                Both are worked out from the rows already on the page. A second
                request would be asking the server for something in hand, and
                could come back describing a different moment than the list
                above it.
            */}
            <div className="q-panels">
                <Card
                    title={
                        <span className="opd-card-title">
                            <i className="ti ti-users-group" aria-hidden="true" />
                            Live queue by doctor
                        </span>
                    }
                >
                    {isLoading ? (
                        <LoadingBlock label="Loading…" />
                    ) : byDoctor.length === 0 ? (
                        <div className="opd-quiet">
                            <i className="ti ti-calendar-off" aria-hidden="true" />
                            <p>Nobody is booked with anyone here.</p>
                        </div>
                    ) : (
                        <ul className="q-load">
                            {byDoctor.map((doctor) => (
                                <li key={doctor.id}>
                                    <span className="q-load-who">
                                        <span className="q-face" aria-hidden="true">
                                            {(doctor.name ?? '?').replace(/^Dr\.?\s*/i, '').charAt(0)}
                                        </span>
                                        <b>{doctor.name}</b>
                                    </span>

                                    {/*
                                        The bar is scaled to the busiest list,
                                        not to a fixed maximum: the question is
                                        which doctor is furthest behind, and
                                        that is a comparison between them.
                                    */}
                                    <span className="q-load-bar">
                                        <span
                                            className="q-load-fill"
                                            style={{
                                                width: `${(doctor.waiting / busiest) * 100}%`,
                                                background: `var(--cat-${doctorHue(doctor.id)})`,
                                            }}
                                        />
                                        <em>{doctor.waiting} waiting</em>
                                    </span>

                                    <span className="q-load-chip">
                                        {doctor.inConsult} in consult
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card
                    title={
                        <span className="opd-card-title">
                            <i className="ti ti-clock-hour-4" aria-hidden="true" />
                            Average wait time
                        </span>
                    }
                    description="How long people waited before being called, by the hour they arrived."
                >
                    {isLoading ? (
                        <LoadingBlock label="Loading…" />
                    ) : (
                        <WaitTrend
                            points={waitByHour}
                            warn={data?.thresholds.warn ?? 10}
                            critical={data?.thresholds.critical ?? 20}
                        />
                    )}
                </Card>
            </div>


            {canBook && (
                <BookDialog
                    open={booking}
                    onClose={() => {
                        setBooking(false);
                        setReturned(null);
                    }}
                    preselect={returned}
                    locationId={branchId}
                    date={date}
                    branchName={branch?.name ?? ''}
                />
            )}
        </>
    );
}
