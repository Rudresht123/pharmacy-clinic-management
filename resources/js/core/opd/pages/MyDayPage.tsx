import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveConsultation } from '@/core/appointments/api';
import { NEXT_ACTION_STEP } from '@/core/appointments/workflow';
import { useMyDay } from '../api';
import { ConsultationPanel, type ConsultationTab } from '../components/ConsultationPanel';
import { PatientAside } from '../components/PatientAside';
import type { MyDay, MyRow } from '../types';

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
    const { activeBranch, can } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);

    // The consultation's own endpoints. A doctor starts, completes and
    // reopens their own write-ups; checking patients in and calling them
    // through is the desk's, on a different capability.
    const move = useMoveConsultation();

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
                                                    {/*
                                                        The column answers
                                                        "where is this patient",
                                                        which for a finished
                                                        visit is downstream of
                                                        this room. A doctor
                                                        asked whether somebody
                                                        has collected their
                                                        medicines can see it
                                                        without leaving their
                                                        list.
                                                    */}
                                                    {row.consultation_status === 'completed'
                                                        ? (row.next_action
                                                            ? NEXT_ACTION_STEP[row.next_action].label
                                                            : 'Seen')
                                                        : row.queue_status === 'called'
                                                          ? 'Called'
                                                          : row.waiting_minutes !== null
                                                            ? `${row.waiting_minutes} min`
                                                            : row.status === 'in_consultation'
                                                              ? 'In the room'
                                                              : '—'}
                                                </td>

                                                <td className="md-act" data-label="">
                                                    {/*
                                                        THE DOCTOR'S THREE, and
                                                        never more than one at
                                                        a time.

                                                        Two conditions each:
                                                        the server says the
                                                        state allows it, and
                                                        this login holds the
                                                        capability. Start is
                                                        offered only once
                                                        reception has called
                                                        the patient, and never
                                                        on a consultation that
                                                        has already been
                                                        started — which is what
                                                        stops a second one
                                                        before the server has
                                                        to refuse it.

                                                        "Arrived" is gone.
                                                        Checking a patient in
                                                        belongs at the desk,
                                                        and offering it here
                                                        made the doctor's list
                                                        a second queue screen.
                                                    */}
                                                    {row.available?.consult_start &&
                                                        can('appointments.consult_start') && (
                                                            <button
                                                                type="button"
                                                                className="md-call"
                                                                disabled={move.isPending}
                                                                onClick={() =>
                                                                    move.mutate({
                                                                        id: row.id,
                                                                        action: 'start',
                                                                    })
                                                                }
                                                            >
                                                                Start consultation
                                                            </button>
                                                        )}

                                                    {row.available?.consult_complete &&
                                                        can('appointments.consult_complete') && (
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
                                                                Complete
                                                            </button>
                                                        )}

                                                    {/*
                                                        Finished today, and a
                                                        mis-click is still
                                                        recoverable — but as
                                                        "Reopen", which says
                                                        what it does, rather
                                                        than as a Start button
                                                        that would read as a
                                                        second consultation.
                                                    */}
                                                    {row.available?.consult_reopen &&
                                                        can('appointments.consult_complete') && (
                                                            <button
                                                                type="button"
                                                                className="md-done"
                                                                disabled={move.isPending}
                                                                onClick={() =>
                                                                    move.mutate({
                                                                        id: row.id,
                                                                        action: 'reopen',
                                                                    })
                                                                }
                                                            >
                                                                Reopen
                                                            </button>
                                                        )}

                                                    {/*
                                                        Still in the waiting
                                                        room — booked or
                                                        checked in, but not yet
                                                        called through, so
                                                        there is nothing to
                                                        start. Shown disabled
                                                        rather than left blank:
                                                        an empty cell next to
                                                        rows that DO have a
                                                        button reads as broken.
                                                    */}
                                                    {!row.available?.consult_start &&
                                                        !row.available?.consult_complete &&
                                                        !row.available?.consult_reopen &&
                                                        row.consultation_status !== 'completed' &&
                                                        (row.status === 'checked_in' ||
                                                            row.status === 'booked') &&
                                                        can('appointments.consult_start') && (
                                                            <button
                                                                type="button"
                                                                className="md-call"
                                                                disabled
                                                                title={
                                                                    row.status === 'booked'
                                                                        ? 'Not checked in yet'
                                                                        : 'Waiting for the desk to call them through'
                                                                }
                                                            >
                                                                Start consultation
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
                </div>

                <aside className="md-side">
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
                </aside>
            </div>

            {/*
                Full width, below the queue and the rail rather than beside
                either of them. Writing up a visit is the actual work of the
                day, not a sidebar to it, and squeezed into the main column's
                share of the split it left the clinical panel narrower than
                the queue table sitting above it for no reason.
            */}
            <CurrentPatient
                current={data.current}
                nextCalled={data.queue.find((row) => row.queue_status === 'called') ?? null}
                advancing={move.isPending}
                onDone={(id) => move.mutate({ id, action: 'complete' })}
                onFinish={async (id) => {
                    await move.mutateAsync({ id, action: 'complete', silent: true });
                }}
            />
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
    nextCalled,
    advancing,
    onDone,
    onFinish,
}: {
    current: MyDay['current'];
    /**
     * Whoever reception has called and the doctor has not taken in yet.
     *
     * Only used for the empty state's sentence. A doctor sitting in front of
     * "Nobody in the room" needs to know whether that is because nobody has
     * been called or because somebody is standing outside the door.
     */
    nextCalled: MyRow | null;
    advancing: boolean;
    /**
     * The header's "End consultation" — abandoning without writing up is a
     * real, deliberate act, so it stays fire-and-forget with a toast.
     */
    onDone: (id: number) => void;
    /**
     * The write-up form's own "Complete consultation" — awaited, so a
     * refusal can be shown beside the fields it is about instead of in a
     * toast the form itself would then repeat.
     */
    onFinish: (id: number) => Promise<void>;
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
                    {/*
                        Not "call the next patient" any more — calling is
                        reception's. The doctor starts on somebody the desk
                        has already called, and the queue above marks them.
                    */}
                    <p>
                        {nextCalled
                            ? `${nextCalled.customer_name ?? 'The next patient'} has been called. Start the consultation from the list above.`
                            : 'Waiting for reception to call the next patient through.'}
                    </p>
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
                        customerId={current.customer_id}
                        saved={current.consultation}
                        history={current.history}
                        suggestions={current.suggestions}
                        completing={advancing}
                        onComplete={() => onFinish(current.id)}
                        tab={tab}
                        onTab={setTab}
                    />
                </div>
            )}
        </Card>
    );
}
