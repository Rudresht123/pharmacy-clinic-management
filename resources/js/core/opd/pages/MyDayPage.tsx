import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveAppointment } from '@/core/appointments/api';

/** Morning, afternoon or evening — as the person reading it would say it. */
function partOfDay(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'Morning' : hour < 17 ? 'Afternoon' : 'Evening';
}

interface Row {
    id: number;
    token_no: number | null;
    customer_name: string | null;
    customer_code: string | null;
    age: number | null;
    gender: string | null;
    status: string;
    type: string;
    slot_at: string | null;
    waiting_minutes: number | null;
    next_states: string[];
}

interface MyDay {
    date: string;
    doctor: {
        id: number;
        name: string;
        specialisation: string | null;
        photo_url: string | null;
    };
    counts: { total: number; seen: number; waiting: number; expected: number };
    queue: Row[];
    current: (Row & {
        customer_id: number;
        phone: string | null;
        location_name: string | null;
        in_room_minutes: number | null;
    }) | null;
    schedule: {
        starts_at: string;
        ends_at: string;
        name: string | null;
        location_name: string | null;
        changed: boolean;
        state: 'now' | 'later' | 'done';
    }[];
    upcoming: { id: number; slot_at: string; customer_name: string | null; type: string }[];
}

function useMyDay(locationId: number | null) {
    return useQuery({
        queryKey: ['tenant', 'opd', 'my-day', locationId],
        queryFn: async (): Promise<MyDay> => {
            const { data } = await http.get<ApiResponse<MyDay>>('/tenant/opd/my-day', {
                params: locationId ? { location_id: locationId } : {},
            });

            return data.data;
        },
        // A queue read between patients is stale the moment it lands.
        refetchInterval: 30_000,
    });
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
 * Nothing clinical is here yet, and nothing pretends to be. Chief complaint,
 * diagnosis, prescriptions and investigations all belong to tables that do not
 * exist — the consultation arrives in a later phase — so the current-patient
 * card carries who is in the room and the actions that are real today.
 */
export default function MyDayPage() {
    const { activeBranch } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);
    const move = useMoveAppointment();

    const [filter, setFilter] = useState<'all' | 'waiting' | 'expected'>('all');
    const [term, setTerm] = useState('');

    const shown = useMemo(() => {
        const rows = (data?.queue ?? []).filter((row) => {
            if (filter === 'waiting') return row.status === 'checked_in';
            if (filter === 'expected') return row.status === 'booked';

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
                    {doctor.photo_url ? (
                        <img src={doctor.photo_url} alt="" className="md-face" />
                    ) : (
                        <span className="md-face is-letter" aria-hidden="true">
                            {doctor.name.replace(/^Dr\.?\s*/i, '').charAt(0)}
                        </span>
                    )}

                    <div>
                        <h1>
                            Good {partOfDay().toLowerCase()}, {doctor.name}
                        </h1>
                        <p>{doctor.specialisation ?? 'General'}</p>
                    </div>
                </div>

                <div className="md-stats">
                    {(
                        [
                            ['Today', counts.total, 'ti ti-calendar-event', 'blue'],
                            ['Seen', counts.seen, 'ti ti-checks', 'green'],
                            ['Waiting', counts.waiting, 'ti ti-users', 'amber'],
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
                                <i className="ti ti-list-numbers" aria-hidden="true" />
                                My queue
                            </span>
                        }
                        actions={
                            <div className="md-tools">
                                <div className="md-tabs" role="group" aria-label="Filter the queue">
                                    {(
                                        [
                                            ['all', `All (${data.queue.length})`],
                                            ['waiting', `Waiting (${counts.waiting})`],
                                            ['expected', `Expected (${counts.expected})`],
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
                                        placeholder="Search this queue…"
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
                            <div className="md-scroll">
                                <table className="md-queue">
                                    <thead>
                                        <tr>
                                            <th>Token</th>
                                            <th>Patient</th>
                                            <th>Age / sex</th>
                                            <th>Visit</th>
                                            <th>Waiting</th>
                                            <th aria-label="Action" />
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {shown.map((row) => (
                                            <tr
                                                key={row.id}
                                                className={
                                                    row.status === 'in_consultation'
                                                        ? 'is-in'
                                                        : undefined
                                                }
                                            >
                                                <td>
                                                    <span className="md-token">
                                                        {row.token_no ?? row.slot_at ?? '—'}
                                                    </span>
                                                </td>

                                                <td>
                                                    <b>{row.customer_name ?? 'Unnamed'}</b>
                                                    {row.customer_code && (
                                                        <small>{row.customer_code}</small>
                                                    )}
                                                </td>

                                                <td className="md-dim">
                                                    {row.age !== null ? `${row.age}` : '—'}
                                                    {row.gender
                                                        ? ` / ${row.gender.charAt(0).toUpperCase()}`
                                                        : ''}
                                                </td>

                                                <td>
                                                    <span
                                                        className={`md-tag is-${
                                                            row.type === 'walk_in' ? 'walk' : 'book'
                                                        }`}
                                                    >
                                                        {row.type === 'walk_in'
                                                            ? 'Walk-in'
                                                            : 'Booked'}
                                                    </span>
                                                </td>

                                                <td className="md-dim">
                                                    {row.waiting_minutes !== null
                                                        ? `${row.waiting_minutes} min`
                                                        : row.status === 'in_consultation'
                                                          ? 'In the room'
                                                          : '—'}
                                                </td>

                                                <td className="md-act">
                                                    {/*
                                                        One button, and only the
                                                        move that is actually
                                                        legal from here — the
                                                        server owns the
                                                        transitions and hands
                                                        back what is next.
                                                    */}
                                                    {row.next_states.includes(
                                                        'in_consultation',
                                                    ) && (
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
                                                            Call in
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
                                <i className="ti ti-clock-hour-4" aria-hidden="true" />
                                My schedule today
                            </span>
                        }
                        actions={
                            <Link className="md-viewall" to="/availability">
                                Full schedule
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
                                                {session.starts_at} – {session.ends_at}
                                            </b>
                                            <small>
                                                {session.name ?? 'OPD'}
                                                {session.location_name
                                                    ? ` · ${session.location_name}`
                                                    : ''}
                                            </small>
                                        </span>

                                        {session.state === 'now' && (
                                            <em className="md-now">Now</em>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card
                        title={
                            <span className="opd-card-title">
                                <i className="ti ti-calendar-time" aria-hidden="true" />
                                Later today
                            </span>
                        }
                    >
                        {data.upcoming.length === 0 ? (
                            <p className="md-quiet">Nothing booked for later.</p>
                        ) : (
                            <ul className="md-later">
                                {data.upcoming.map((row) => (
                                    <li key={row.id}>
                                        <b>{row.slot_at}</b>
                                        <span>{row.customer_name ?? 'Unnamed'}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card
                        title={
                            <span className="opd-card-title">
                                <i className="ti ti-bolt" aria-hidden="true" />
                                Quick actions
                            </span>
                        }
                    >
                        <div className="md-quick">
                            {(
                                [
                                    ['/customers', 'ti ti-users', 'Patients'],
                                    ['/opd/queue', 'ti ti-list-check', 'Full queue'],
                                    ['/availability', 'ti ti-calendar-time', 'Availability'],
                                    ['/opd', 'ti ti-building-hospital', 'OPD board'],
                                ] as const
                            ).map(([to, icon, label]) => (
                                <Link className="md-quick-one" to={to} key={to}>
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
    return (
        <Card
            title={
                <span className="opd-card-title">
                    <i className="ti ti-stethoscope" aria-hidden="true" />
                    In the room
                </span>
            }
            actions={
                current?.in_room_minutes !== null && current ? (
                    <span className="md-elapsed">
                        <i className="ti ti-clock" aria-hidden="true" />
                        {current.in_room_minutes} min
                    </span>
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
                    <div className="md-patient">
                        <span className="md-face is-letter" aria-hidden="true">
                            {(current.customer_name ?? '?').charAt(0)}
                        </span>

                        <div>
                            <b>{current.customer_name ?? 'Unnamed'}</b>
                            <small>
                                {current.customer_code ?? '—'}
                                {current.age !== null ? ` · ${current.age} years` : ''}
                                {current.gender ? ` · ${current.gender}` : ''}
                            </small>

                            {current.phone && (
                                <small>
                                    <i className="ti ti-phone" aria-hidden="true" /> {current.phone}
                                </small>
                            )}
                        </div>
                    </div>

                    {/*
                        Said plainly rather than mocked up. A panel of empty
                        clinical fields that saved nowhere would be worse than
                        an honest gap — somebody would type into it.
                    */}
                    <p className="md-soon">
                        <i className="ti ti-info-circle" aria-hidden="true" />
                        Notes, diagnosis and prescriptions arrive with the consultation module.
                        For now the visit is recorded here and the patient&rsquo;s own record
                        holds their history.
                    </p>

                    <div className="md-current-acts">
                        <Link className="md-open" to={`/customers/${current.customer_id}/edit`}>
                            Open the patient record
                            <i className="ti ti-arrow-right" aria-hidden="true" />
                        </Link>

                        <button
                            type="button"
                            className="md-done is-wide"
                            disabled={advancing}
                            onClick={() => onDone(current.id)}
                        >
                            <i className="ti ti-check" aria-hidden="true" />
                            Finish consultation
                        </button>
                    </div>
                </div>
            )}
        </Card>
    );
}
