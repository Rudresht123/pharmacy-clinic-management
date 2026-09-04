import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { StatTiles } from '@/shared/components/ui/StatTiles';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { locationsHooks } from '@/core/locations/api';
import { doctorsHooks } from '@/core/doctors/api';
import { BookDialog } from '../components/BookDialog';
import { useMoveAppointment, useQueue } from '../api';
import type { Appointment, AppointmentStatus } from '../types';

/** How each state presents itself. */
const STATUS: Record<AppointmentStatus, { label: string; tone: string }> = {
    booked: { label: 'Expected', tone: 'muted' },
    checked_in: { label: 'Waiting', tone: 'sky' },
    in_consultation: { label: 'With the doctor', tone: 'amber' },
    completed: { label: 'Seen', tone: 'emerald' },
    cancelled: { label: 'Cancelled', tone: 'rose' },
    no_show: { label: 'Did not come', tone: 'rose' },
};

/** The move each next-state offers, and what the button says. */
const ACTIONS: Record<string, { label: string; icon: string; action: string }> = {
    checked_in: { label: 'Check in', icon: 'ti ti-login', action: 'check-in' },
    in_consultation: { label: 'Call in', icon: 'ti ti-player-play', action: 'start' },
    completed: { label: 'Done', icon: 'ti ti-check', action: 'complete' },
    no_show: { label: 'No-show', icon: 'ti ti-user-x', action: 'no-show' },
    cancelled: { label: 'Cancel', icon: 'ti ti-x', action: 'cancel' },
};

function today(): string {
    const now = new Date();

    return `${now.getFullYear()}-${`${now.getMonth() + 1}`.padStart(2, '0')}-${`${now.getDate()}`.padStart(2, '0')}`;
}

/**
 * The OPD queue: one doctor, one branch, one day.
 *
 * Booked patients and walk-ins are one list in arrival order. A booked
 * patient is **not** floated to the top — their time is shown so the desk
 * can call somebody out of turn on purpose, which is a person's judgement
 * rather than a rule applied behind their back.
 */
export default function QueuePage() {
    const confirm = useConfirm();
    const [searchParams, setSearchParams] = useSearchParams();

    const date = searchParams.get('date') || today();

    const [locationId, setLocationId] = useState<number | ''>('');
    const [doctorId, setDoctorId] = useState<number | ''>('');
    const [booking, setBooking] = useState(false);

    const { data: branches } = locationsHooks.useList({ all: 1 });
    const { data: doctors } = doctorsHooks.useList({ all: 1 });

    useEffect(() => {
        if (locationId === '' && branches?.length) {
            setLocationId(branches[0].id);
        }
    }, [branches, locationId]);

    useEffect(() => {
        if (doctorId === '' && doctors?.length) {
            setDoctorId(doctors[0].id);
        }
    }, [doctors, doctorId]);

    const { data, isLoading, isError, refetch } = useQueue(doctorId, locationId, date);
    const move = useMoveAppointment();

    async function onMove(appointment: Appointment, next: AppointmentStatus) {
        const action = ACTIONS[next];

        if (!action) {
            return;
        }

        // The two that cannot be undone get asked about; the rest are the
        // ordinary rhythm of a busy desk and must not need a click each.
        if (next === 'cancelled' || next === 'no_show') {
            const confirmed = await confirm({
                title: next === 'cancelled' ? 'Cancel this appointment?' : 'Mark as a no-show?',
                message:
                    next === 'cancelled'
                        ? `${appointment.customer_name}'s slot goes back into the day. Their token, if issued, is not reused.`
                        : `${appointment.customer_name} will be recorded as not having come.`,
                confirmLabel: action.label,
                danger: true,
            });

            if (!confirmed) {
                return;
            }
        }

        move.mutate({ id: appointment.id, action: action.action as never });
    }

    const doctor = (doctors ?? []).find((entry) => entry.id === doctorId);

    return (
        <>
            <PageHeader
                title="OPD Queue"
                subtitle="Booked patients and walk-ins in one line, in the order they arrived."
                icon="ti ti-users-group"
                tone="sky"
                crumbs={[{ label: 'OPD Queue' }]}
                actions={
                    <Button
                        icon="ti ti-plus"
                        disabled={!doctorId || !locationId}
                        onClick={() => setBooking(true)}
                    >
                        Book or walk-in
                    </Button>
                }
            />

            <div className="card av-controls">
                <label className="av-control">
                    <span>Date</span>
                    <DatePicker
                        id="queue-date"
                        label="the date"
                        value={date}
                        onChange={(value: string) => {
                            const next = new URLSearchParams(searchParams);
                            next.set('date', value || today());
                            setSearchParams(next, { replace: true });
                        }}
                    />
                </label>

                <label className="av-control">
                    <span>Branch</span>
                    <select
                        className="form-select"
                        value={locationId}
                        onChange={(event) => setLocationId(Number(event.target.value))}
                    >
                        {(branches ?? []).map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="av-control">
                    <span>Doctor</span>
                    <select
                        className="form-select"
                        value={doctorId}
                        onChange={(event) => setDoctorId(Number(event.target.value))}
                    >
                        {(doctors ?? []).map((entry) => (
                            <option key={entry.id} value={entry.id}>
                                {entry.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            <StatTiles
                loading={isLoading}
                tiles={[
                    {
                        label: 'Waiting',
                        value: data?.waiting ?? 0,
                        icon: 'ti ti-hourglass',
                        tone: 'sky',
                        hint: 'Checked in, not yet called',
                    },
                    {
                        label: 'Seen',
                        value: data?.seen ?? 0,
                        icon: 'ti ti-circle-check',
                        tone: 'emerald',
                    },
                    {
                        label: 'Expected',
                        value: data?.expected ?? 0,
                        icon: 'ti ti-calendar-time',
                        tone: 'violet',
                        hint: 'Booked, not yet arrived',
                    },
                ]}
            />

            <Card>
                {isLoading ? (
                    <LoadingBlock label="Loading the queue…" />
                ) : isError ? (
                    <ErrorState onRetry={() => refetch()} />
                ) : (data?.queue ?? []).length === 0 ? (
                    <div className="org-pending">
                        <i className="ti ti-users" />
                        <h6>Nobody in the queue</h6>
                        <p>
                            Nothing is booked with {doctor?.name ?? 'this doctor'} here today. Book
                            somebody in, or take a walk-in.
                        </p>
                    </div>
                ) : (
                    <ul className="q">
                        {(data?.queue ?? []).map((row) => {
                            const state = STATUS[row.status];

                            return (
                                <li className={`q-row is-${state.tone}`} key={row.id}>
                                    <span className="q-token">
                                        {row.token_no ?? <i className="ti ti-minus" />}
                                    </span>

                                    <div className="q-who">
                                        <b>{row.customer_name}</b>
                                        <span>
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

                                    <span className={`q-status is-${state.tone}`}>
                                        {state.label}
                                    </span>

                                    {/* The number the desk actually looks at. */}
                                    <span className="q-wait">
                                        {row.waiting_minutes !== null
                                            ? `${row.waiting_minutes} min`
                                            : ''}
                                    </span>

                                    <div className="q-actions">
                                        {row.next_states.map((next) => {
                                            const action = ACTIONS[next];

                                            if (!action) {
                                                return null;
                                            }

                                            return (
                                                <button
                                                    type="button"
                                                    key={next}
                                                    className={`q-act${
                                                        next === 'cancelled' || next === 'no_show'
                                                            ? ' is-quiet'
                                                            : ''
                                                    }`}
                                                    onClick={() => onMove(row, next)}
                                                >
                                                    <i className={action.icon} />
                                                    {action.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            <BookDialog
                open={booking}
                onClose={() => setBooking(false)}
                doctorId={doctorId}
                locationId={locationId}
                date={date}
                doctorName={doctor?.name ?? ''}
            />
        </>
    );
}
