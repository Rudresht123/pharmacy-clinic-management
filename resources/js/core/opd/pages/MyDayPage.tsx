import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveAppointment } from '@/core/appointments/api';
import { useMyDay } from '../api';
import { ConsultationPanel, type ConsultationTab } from '../components/ConsultationPanel';
import { PatientAside } from '../components/PatientAside';
import type { MyDay } from '../types';

/** Morning, afternoon or evening — as the person reading it would say it. */
function partOfDay(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';
}

/** "09:00" → "9:00 AM", for reading rather than editing. */
function spoken(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}:${String(minutes).padStart(2, '0')} ${
        hours < 12 ? 'AM' : 'PM'
    }`;
}

/**
 * A doctor's own screen.
 *
 * The OPD board answers "how is the clinic going", which is a manager's
 * question — every consulting room, every doctor, the flow through the door.
 * This answers the two a doctor asks between patients: who is in front of me,
 * and who is next. A doctor signing in and landing on the board would be
 * reading somebody else's job.
 *
 * The write-up beside the patient rather than behind a button: a doctor types
 * the complaint while the person is still saying it, and a consultation that
 * opened on its own screen would be a screen nobody fills in until afterwards,
 * from memory.
 */
export default function MyDayPage() {
    const { activeBranch } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);
    const move = useMoveAppointment();

    const [filter, setFilter] = useState<'all' | 'new' | 'returning'>('all');
    const [term, setTerm] = useState('');

    const shown = useMemo(() => {
        const rows = (data?.queue ?? []).filter((row) => {
            if (filter === 'new') return row.is_new;
            if (filter === 'returning') return !row.is_new;

            return true;
        });

        const needle = term.trim().toLowerCase();

        if (!needle) return rows;

        return rows.filter(
            (row) =>
                row.customer_name?.toLowerCase().includes(needle) ||
                row.customer_code?.toLowerCase().includes(needle) ||
                String(row.token_no ?? '').includes(needle),
        );
    }, [data?.queue, filter, term]);

    if (isLoading) return <LoadingBlock label="Loading your day…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    const { counts, doctor } = data;

    return (
        <>
            {/* Who, and how the day stands — one line before any of the work. */}
            <div className="md-hero">
                <div className="md-who">
                    <PersonPhoto src={doctor.photo_url} name={doctor.name} className="md-face" />

                    <div>
                        <h1>
                            Good {partOfDay()}, {doctor.name}
                            <span aria-hidden="true"> 👋</span>
                        </h1>
                        <p>{doctor.specialisation ?? 'General'}</p>
                    </div>
                </div>

                <div className="md-stats">
                    {(
                        [
                            ["Today's appointments", counts.total, 'ti ti-calendar-event', 'blue'],
                            ['Patients seen', counts.seen, 'ti ti-checks', 'green'],
                            ['In queue', counts.waiting, 'ti ti-users', 'amber'],
                            /*
                                "Yet to arrive", not "follow-ups due".
                                Nothing records that a follow-up was asked for,
                                so a count of them would be a number with no
                                source behind it. This one is the difference
                                between a quiet morning and one about to arrive
                                all at once.
                            */
                            ['Yet to arrive', counts.expected, 'ti ti-clock', 'violet'],
                        ] as const
                    ).map(([label, value, icon, tone]) => (
                        <div className={`md-stat is-${tone}`} key={label}>
                            <i className={icon} aria-hidden="true" />
                            <span>
                                <b>{value}</b>
                                <small>{label}</small>
                            </span>
                        </div>
                    ))}
                </div>
            </div>

            <div className="md-split">
                <div className="md-main">
                    <Card
                        title={
                            <span className="opd-card-title">
                                Today&rsquo;s queue
                                {counts.waiting > 0 && (
                                    <em className="md-waiting">{counts.waiting} waiting</em>
                                )}
                            </span>
                        }
                        actions={
                            <div className="md-tools">
                                <div className="md-tabs" role="group" aria-label="Filter the queue">
                                    {(
                                        [
                                            ['all', `All (${data.queue.length})`],
                                            ['new', `New (${counts.new})`],
                                            ['returning', `Follow-up (${counts.returning})`],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <button
                                            type="button"
                                            key={key}
                                            className={`md-tab${filter === key ? ' is-on' : ''}`}
                                            aria-pressed={filter === key}
                                            onClick={() => setFilter(key)}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>

                                <label className="md-search">
                                    <i className="ti ti-search" aria-hidden="true" />
                                    <input
                                        type="text"
                                        value={term}
                                        placeholder="Search in queue…"
                                        aria-label="Search this queue"
                                        onChange={(event) => setTerm(event.target.value)}
                                    />
                                </label>
                            </div>
                        }
                    >
                        {shown.length === 0 ? (
                            <div className="org-pending">
                                <i className="ti ti-mood-check" />
                                <h6>Nothing waiting</h6>
                                <p>
                                    {term || filter !== 'all'
                                        ? 'Nothing here matches that.'
                                        : 'Your list is clear for now.'}
                                </p>
                            </div>
                        ) : (
                            <div className="md-scroll tbl-cards-scroll">
                                <table className="md-queue tbl-cards">
                                    <thead>
                                        <tr>
                                            <th className="md-no">#</th>
                                            <th>Token</th>
                                            <th>Patient name</th>
                                            <th>Age / gender</th>
                                            <th>Visit type</th>
                                            <th>Wait time</th>
                                            <th aria-label="Action" />
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {shown.map((row, index) => (
                                            <tr
                                                key={row.id}
                                                className={
                                                    row.status === 'in_consultation'
                                                        ? 'is-in'
                                                        : undefined
                                                }
                                            >
                                                <td className="md-no md-dim" data-label="">{index + 1}</td>

                                                <td data-label="Token">
                                                    <span className="md-token">
                                                        {row.token_no ?? row.slot_at ?? '—'}
                                                    </span>
                                                </td>

                                                <td data-label="Patient">
                                                    <b>{row.customer_name ?? 'Unnamed'}</b>
                                                    {row.customer_code && (
                                                        <small>{row.customer_code}</small>
                                                    )}
                                                </td>

                                                <td className="md-dim" data-label="Age / gender">
                                                    {row.age !== null ? `${row.age}` : '—'}
                                                    {row.gender
                                                        ? ` / ${row.gender.charAt(0).toUpperCase()}`
                                                        : ''}
                                                </td>

                                                <td data-label="Visit type">
                                                    {/*
                                                        New or returning, worked out
                                                        from whether this patient has
                                                        been seen before — not from how
                                                        the appointment was made, which
                                                        is a different question nobody
                                                        asks in a queue.
                                                    */}
                                                    <span
                                                        className={`md-tag is-${
                                                            row.is_new ? 'new' : 'again'
                                                        }`}
                                                    >
                                                        {row.is_new ? 'New' : 'Follow-up'}
                                                    </span>
                                                </td>

                                                <td className="md-dim" data-label="Wait time">
                                                    {row.waiting_minutes !== null
                                                        ? `${row.waiting_minutes} min`
                                                        : row.status === 'in_consultation'
                                                          ? 'In the room'
                                                          : '—'}
                                                </td>

                                                <td className="md-act" data-label="">
                                                    {/*
                                                        Only the moves the
                                                        server says are legal
                                                        from here — it owns the
                                                        transitions and hands
                                                        back what is next.

                                                        A patient who has not
                                                        arrived is checked in
                                                        first; one who is
                                                        waiting is called; a
                                                        finished visit can be
                                                        put back in the room on
                                                        the day it happened.
                                                    */}
                                                    {row.next_states.includes('checked_in') && (
                                                        <button
                                                            type="button"
                                                            className="md-done"
                                                            disabled={move.isPending}
                                                            onClick={() =>
                                                                move.mutate({
                                                                    id: row.id,
                                                                    action: 'check-in',
                                                                })
                                                            }
                                                        >
                                                            Arrived
                                                        </button>
                                                    )}

                                                    {row.next_states.includes(
                                                        'in_consultation',
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            className={
                                                                row.status === 'completed'
                                                                    ? 'md-done'
                                                                    : 'md-call'
                                                            }
                                                            disabled={move.isPending}
                                                            onClick={() =>
                                                                move.mutate({
                                                                    id: row.id,
                                                                    action:
                                                                        row.status === 'completed'
                                                                            ? 'reopen'
                                                                            : 'start',
                                                                })
                                                            }
                                                        >
                                                            {row.status === 'completed'
                                                                ? 'Reopen'
                                                                : 'Call'}
                                                        </button>
                                                    )}

                                                    {row.next_states.includes('completed') && (
                                                        <button
                                                            type="button"
                                                            className="md-done"
                                                            disabled={move.isPending}
                                                            onClick={() =>
                                                                move.mutate({
                                                                    id: row.id,
                                                                    action: 'complete',
                                                                })
                                                            }
                                                        >
                                                            Finish
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <CurrentPatient
                        current={data.current}
                        advancing={move.isPending}
                        onDone={(id) => move.mutate({ id, action: 'complete' })}
                    />
                </div>

                <aside className="md-side">
                    <Card
                        title={
                            <span className="opd-card-title">
                                My schedule today
                            </span>
                        }
                        actions={
                            <Link className="md-viewall" to="/my-schedule">
                                View full schedule
                            </Link>
                        }
                    >
                        {data.schedule.length === 0 ? (
                            <p className="md-quiet">No sitting here today.</p>
                        ) : (
                            <ul className="md-sched">
                                {data.schedule.map((session, index) => (
                                    <li key={index} className={`is-${session.state}`}>
                                        <i aria-hidden="true" />

                                        <span>
                                            <b>
                                                {spoken(session.starts_at)} –{' '}
                                                {spoken(session.ends_at)}
                                            </b>
                                            <small>
                                                {session.name ?? 'OPD'}
                                                {session.location_name
                                                    ? ` · ${session.location_name}`
                                                    : ''}
                                            </small>
                                        </span>

                                        {session.state === 'now' && (
                                            <em className="md-now">Ongoing</em>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card
                        title={
                            <span className="opd-card-title">
                                Upcoming appointments
                            </span>
                        }
                        actions={
                            <Link className="md-viewall" to="/my-queue">
                                View all
                            </Link>
                        }
                    >
                        {data.upcoming.length === 0 ? (
                            <p className="md-quiet">Nothing booked for later.</p>
                        ) : (
                            <ul className="md-later">
                                {data.upcoming.map((row) => (
                                    <li key={row.id}>
                                        <b>{spoken(row.slot_at)}</b>

                                        <span>
                                            <i className="ti ti-user" aria-hidden="true" />
                                            {row.customer_name ?? 'Unnamed'}
                                        </span>

                                        <em
                                            className={`md-tag is-${row.is_new ? 'new' : 'again'}`}
                                        >
                                            {row.is_new ? 'New' : 'Follow-up'}
                                        </em>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card
                        title={
                            <span className="opd-card-title">
                                Quick actions
                            </span>
                        }
                    >
                        <div className="md-quick">
                            {(
                                [
                                    ['/customers/create', 'ti ti-user-plus', 'New patient', 'blue'],
                                    ['/customers', 'ti ti-users', 'View patients', 'violet'],
                                    ['/my-queue', 'ti ti-list-numbers', 'My queue', 'green'],
                                    ['/my-schedule', 'ti ti-clock-hour-4', 'My schedule', 'amber'],
                                    ['/opd/queue', 'ti ti-clipboard-list', 'Department queue', 'blue'],
                                    ['/availability', 'ti ti-calendar-time', 'Availability', 'violet'],
                                ] as const
                            ).map(([to, icon, label, tone]) => (
                                <Link className={`md-quick-one is-${tone}`} to={to} key={to}>
                                    <i className={icon} aria-hidden="true" />
                                    {label}
                                </Link>
                            ))}
                        </div>
                    </Card>
                </aside>
            </div>
        </>
    );
}

/**
 * Whoever is in the room.
 *
 * The reference for this screen shows a clinical panel here — chief complaint,
 * diagnosis, a prescription, investigations. None of those have a table behind
 * them yet: the consultation is a later phase, and a form that collected them
 * into nothing would lose a doctor's notes silently. So this shows the patient
 * and the moves that are real, and says plainly what is coming.
 */
function CurrentPatient({
    current,
    advancing,
    onDone,
}: {
    current: MyDay['current'];
    advancing: boolean;
    onDone: (id: number) => void;
}) {
    /*
     * Which tab, held here rather than in the panel.
     *
     * "Previous visits" in the patient column and the History tab in the panel
     * are the same thing said twice; the button opens the tab instead of
     * opening a second screen showing the same five rows.
     */
    const [tab, setTab] = useState<ConsultationTab>('clinical');

    return (
        <Card
            className="md-room"
            title={
                <span className="cp-title">
                    <i className="cp-mark ti ti-user" aria-hidden="true" />

                    <span>
                        <b>
                            Current patient
                            {current && <em className="md-inroom">In consultation</em>}
                        </b>

                        <small>
                            {current?.started_at
                                ? `Consultation started at ${spoken(current.started_at)}`
                                : 'Nobody is with you at the moment'}
                        </small>
                    </span>
                </span>
            }
            actions={
                current ? (
                    <div className="cp-acts">
                        {current.in_room_minutes !== null && (
                            <span className="cp-elapsed">
                                <i className="ti ti-clock" aria-hidden="true" />
                                <span>
                                    <small>Time elapsed</small>
                                    <b>{current.in_room_minutes} min</b>
                                </span>
                            </span>
                        )}

                        {/*
                            Ending without writing up is a real thing a doctor
                            does — the patient walked out, or was sent
                            elsewhere — so it is offered, in red, away from the
                            two buttons at the foot of the write-up.
                        */}
                        <button
                            type="button"
                            className="cp-end"
                            disabled={advancing}
                            onClick={() => onDone(current.id)}
                        >
                            <i className="ti ti-circle-x" aria-hidden="true" />
                            End consultation
                        </button>
                    </div>
                ) : undefined
            }
        >
            {!current ? (
                <div className="org-pending">
                    <i className="ti ti-door" />
                    <h6>Nobody in the room</h6>
                    <p>Call the next patient from the queue above.</p>
                </div>
            ) : (
                <div className="md-current">
                    <PatientAside current={current} onHistory={() => setTab('history')} />

                    {/*
                        Keyed on the appointment, so moving to the next patient
                        starts a clean write-up rather than inheriting the last
                        one's half-typed complaint.
                    */}
                    <ConsultationPanel
                        key={current.id}
                        appointmentId={current.id}
                        saved={current.consultation}
                        history={current.history}
                        suggestions={current.suggestions}
                        completing={advancing}
                        onComplete={() => onDone(current.id)}
                        tab={tab}
                        onTab={setTab}
                    />
                </div>
            )}
        </Card>
    );
}
