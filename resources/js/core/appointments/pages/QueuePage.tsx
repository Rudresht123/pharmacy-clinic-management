import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useEntityLabel } from '@/core/field-settings/api';
import { useOpdContext } from '@/core/opd/useOpdContext';
import { OpdToolbar } from '@/core/opd/components/OpdToolbar';
import { StatusBadge, WaitBadge } from '@/core/opd/components/Badges';
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

        const next = new URLSearchParams(params);
        next.delete('patient');
        setParams(next, { replace: true });
    }, [params, setParams]);
    const [menuFor, setMenuFor] = useState<number | null>(null);

    const { data, isLoading, isError, refetch } = useQueue(doctorId, branchId, date);
    const move = useMoveAppointment();

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

    const rows = data?.queue ?? [];

    return (
        <>
            <PageHeader
                title="Queue"
                subtitle={
                    branch
                        ? `${branch.name} — booked patients and walk-ins, in the order they arrived.`
                        : 'Booked patients and walk-ins, in the order they arrived.'
                }
                icon="ti ti-list-check"
                tone="sky"
                crumbs={[{ label: 'OPD', to: '/opd' }, { label: 'Queue' }]}
                actions={
                    canBook && (
                        <Button
                            icon="ti ti-plus"
                            disabled={!branchId}
                            onClick={() => setBooking(true)}
                        >
                            Walk-in or booking
                        </Button>
                    )
                }
            />

            <OpdToolbar context={context}>
                {/*
                    Built from the day's own list rather than from every doctor
                    on the books: one who has nobody booked is not a filter
                    anybody wants, and offering them returns an empty queue
                    that looks like a fault.
                */}
                {(data?.doctors ?? []).length > 1 && (
                    <div className="opd-bar-filter" role="group" aria-label="Filter by doctor">
                        <button
                            type="button"
                            className={`opd-chip${doctorId === '' ? ' is-on' : ''}`}
                            onClick={() => setDoctorId('')}
                        >
                            All doctors
                        </button>

                        {(data?.doctors ?? []).map((doctor) => (
                            <button
                                type="button"
                                key={doctor.id}
                                className={`opd-chip${doctorId === doctor.id ? ' is-on' : ''}`}
                                onClick={() => setDoctorId(doctor.id)}
                            >
                                {doctor.name}
                            </button>
                        ))}
                    </div>
                )}
            </OpdToolbar>

            {/* The counts a desk glances at without leaving the list. */}
            <div className="opd-strip">
                <span>
                    <b>{data?.waiting ?? 0}</b> waiting
                </span>
                <span>
                    <b>{data?.with_doctor ?? 0}</b> with a doctor
                </span>
                <span>
                    <b>{data?.seen ?? 0}</b> seen
                </span>
                <span>
                    <b>{data?.expected ?? 0}</b> expected
                </span>

                <Link className="opd-strip-link" to={`/opd?branch=${branchId}&date=${date}`}>
                    <i className="ti ti-layout-dashboard" aria-hidden="true" />
                    Department board
                </Link>
            </div>

            <Card>
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
            </Card>

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
