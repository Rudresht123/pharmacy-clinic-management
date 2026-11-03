import { useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Avatar } from '@/shared/components/ui/Avatar';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Tabs } from '@/shared/components/ui/Tabs';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { resourceKey } from '@/shared/hooks/useResource';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveAppointment, useMoveConsultation } from '@/core/appointments/api';
import { useOpdBranches } from '@/core/opd/api';
import { PauseDialog, PriorityDialog, ReassignDialog } from '../components/QueueDialogs';
import { PriorityBadge, QueueStateBadge } from '../components/QueueBadges';
import {
    useCallNext,
    useDoctorQueue,
    useQueueBoard,
    useQueueHistory,
    useQueueLive,
    useResumeQueue,
    useVisitAction,
} from '../api';
import type { DoctorQueuePayload, QueueMessage, QueueRow } from '../types';

type Section = 'queue' | 'expected' | 'done' | 'history';

const EVENT_WORDS: Record<string, string> = {
    checked_in: 'Checked in',
    called: 'Called',
    recalled: 'Recalled',
    serving: 'Taken in',
    completed: 'Consultation finished',
    reopened: 'Consultation reopened',
    skipped: 'Skipped',
    requeued: 'Back in the queue',
    cancelled: 'Cancelled',
    no_show: 'Marked as gone',
    priority_changed: 'Priority changed',
    reassigned: 'Moved to another doctor',
    paused: 'Queue paused',
    resumed: 'Queue resumed',
    settings_changed: 'Queue settings changed',
};

/** The sentence after the event's name, from what it recorded. */
function eventDetail(metadata: Record<string, unknown> | null): string {
    if (!metadata) {
        return '';
    }

    const parts: string[] = [];

    if (metadata.from && typeof metadata.from === 'string' && metadata.to) {
        parts.push(`${metadata.from} to ${String(metadata.to)}`);
    }

    if (metadata.to && typeof metadata.to === 'object') {
        const moved = metadata.from as { token?: string } | undefined;
        const to = metadata.to as { token?: string };

        parts.push(`${moved?.token ?? ''} became ${to.token ?? ''}`.trim());
    }

    if (metadata.override) {
        parts.push('emergency override');
    }

    if (metadata.reason) {
        parts.push(String(metadata.reason));
    }

    return parts.join(' · ');
}

function Who({ row }: { row: QueueRow }) {
    return (
        <span className="q-person">
            <Avatar name={row.customer_name} src={row.customer_photo_url} size={34} />
            <span className="q-person-text">
                <b>{row.customer_name}</b>
                {row.customer_code && <code>{row.customer_code}</code>}
            </span>
        </span>
    );
}

/**
 * One doctor's queue, and the controls for it.
 *
 * The doctor's own screen and the desk's view of them are this one page: it is
 * the same queue. What differs is what this login may do, and each button is
 * drawn only when BOTH the server says the row's state allows it and this person
 * holds the capability, so nothing is offered that would then answer 403.
 *
 * `doctorId` comes from the URL for the desk, or from the session for the doctor.
 */
export default function DoctorQueuePage() {
    const params = useParams();
    const [search] = useSearchParams();
    const { can, queueStep, doctorId: myDoctorId, activeBranch } = useTenantAuth();
    const confirm = useConfirm();
    const client = useQueryClient();

    const doctorId = params.doctorId ? Number(params.doctorId) : myDoctorId ?? null;
    const isMine = myDoctorId !== null && myDoctorId === doctorId;

    const { data: branches = [] } = useOpdBranches();
    const locationId = Number(search.get('branch')) || activeBranch || branches[0]?.id || null;

    const [section, setSection] = useState<Section>('queue');
    const [live, setLive] = useState(false);
    const [priorityFor, setPriorityFor] = useState<QueueRow | null>(null);
    const [reassignFor, setReassignFor] = useState<QueueRow | null>(null);
    const [pausing, setPausing] = useState(false);

    const { data, isLoading, isError, refetch } = useDoctorQueue(doctorId, locationId, live);
    const { data: history = [] } = useQueueHistory(doctorId, locationId, section === 'history');

    // Who somebody can be moved to. Only asked for by whoever may move them.
    const canReassign = can('queue.reassign');
    const { data: board } = useQueueBoard(canReassign && locationId ? locationId : '', '', true);

    const channels = useMemo(() => (data?.channel ? [data.channel] : undefined), [data?.channel]);

    const connected = useQueueLive(channels, (message: QueueMessage) => {
        client.setQueryData<DoctorQueuePayload>(
            resourceKey('tenant/queue', 'doctor', doctorId, locationId),
            (current) => current && { ...current, card: { ...current.card, ...message.card } },
        );
    });

    useEffect(() => setLive(connected), [connected]);

    const callNext = useCallNext();
    const resume = useResumeQueue();
    const visit = useVisitAction();
    const move = useMoveConsultation();
    const desk = useMoveAppointment();

    if (doctorId === null) {
        return (
            <Card>
                <div className="org-pending">
                    <i className="ti ti-stethoscope" />
                    <h6>This login is not a doctor's</h6>
                    <p>Open a doctor from the live queue to see their list.</p>
                </div>
            </Card>
        );
    }

    if (isLoading || !locationId) {
        return <LoadingBlock label="Loading the queue…" />;
    }

    if (isError || !data) {
        return <ErrorState message="Unable to load this queue." onRetry={() => refetch()} />;
    }

    const { card, settings } = data;
    const current = data.with_doctor[0] ?? null;
    const called = data.called[0] ?? null;
    const next = data.waiting[0] ?? null;

    const mayCall = can('queue.control') && queueStep('call');
    const mayStart = can('appointments.consult_start') && queueStep('consult_start');
    const mayComplete = can('appointments.consult_complete') && isMine;
    const mayCheckIn = can('appointments.queue') && queueStep('check_in');
    const mayPrioritise = can('queue.priority');
    const mayPause = can('queue.control');

    const working =
        callNext.isPending || visit.isPending || move.isPending || desk.isPending || resume.isPending;

    async function markGone(row: QueueRow) {
        const yes = await confirm({
            title: 'Mark as gone?',
            message: `${row.customer_name} (${row.token}) will be recorded as having left without being seen.`,
            confirmLabel: 'Mark as gone',
            danger: true,
        });

        if (yes) {
            visit.mutate({ id: row.id, action: 'absent' });
        }
    }

    /** The queue's own row actions, for whoever is waiting, called or skipped. */
    function rowActions(row: QueueRow) {
        return (
            <div className="q-actions sq-actions">
                {mayCall && row.available.recall && (
                    <button type="button" className="q-act" disabled={working} onClick={() => visit.mutate({ id: row.id, action: 'recall' })}>
                        <i className="ti ti-bell-ringing" /> Recall
                    </button>
                )}

                {mayStart && row.available.consult_start && (
                    <button type="button" className="q-act is-primary" disabled={working} onClick={() => move.mutate({ id: row.id, action: 'start' })}>
                        <i className="ti ti-player-play" /> Start
                    </button>
                )}

                {mayCall && row.available.skip && (
                    <button type="button" className="q-act" disabled={working} onClick={() => visit.mutate({ id: row.id, action: 'skip' })}>
                        <i className="ti ti-player-skip-forward" /> Skip
                    </button>
                )}

                {mayCall && row.queue_status === 'skipped' && (
                    <button type="button" className="q-act" disabled={working} onClick={() => visit.mutate({ id: row.id, action: 'requeue' })}>
                        <i className="ti ti-arrow-back-up" /> Back in line
                    </button>
                )}

                {mayCall && row.available.absent && (
                    <button type="button" className="q-act" disabled={working} onClick={() => markGone(row)}>
                        <i className="ti ti-user-x" /> Gone
                    </button>
                )}

                {mayPrioritise && row.available.priority && (
                    <button type="button" className="q-act" disabled={working} onClick={() => setPriorityFor(row)}>
                        <i className="ti ti-flag" /> Priority
                    </button>
                )}

                {canReassign && row.available.reassign && (
                    <button type="button" className="q-act" disabled={working} onClick={() => setReassignFor(row)}>
                        <i className="ti ti-transfer" /> Move
                    </button>
                )}
            </div>
        );
    }

    const inFront = [...data.with_doctor, ...data.called];

    return (
        <>
            <PageHeader
                title={`${card.doctor_name}${settings.room_no ? ` · Room ${settings.room_no}` : ''}`}
                subtitle={card.department ?? 'Their own queue, independent of every other doctor.'}
                icon="ti ti-list-numbers"
                tone="violet"
                crumbs={
                    params.doctorId
                        ? [{ label: 'OPD', to: '/opd' }, { label: 'Live queue', to: '/opd/live-queue' }, { label: card.doctor_name ?? 'Doctor' }]
                        : [{ label: 'My day', to: '/my-day' }, { label: 'My live queue' }]
                }
                actions={
                    <div className="q-head-acts">
                        <QueueStateBadge paused={settings.paused} availability={card.availability} startsAt={card.session_starts_at} />

                        {mayPause &&
                            (settings.paused ? (
                                <Button variant="light" icon="ti ti-player-play" disabled={working} onClick={() => resume.mutate({ doctorId, locationId })}>
                                    Resume queue
                                </Button>
                            ) : (
                                <Button variant="light" icon="ti ti-player-pause" onClick={() => setPausing(true)}>
                                    Pause queue
                                </Button>
                            ))}

                        {mayCall && (
                            <Button
                                icon="ti ti-bell-ringing"
                                disabled={!next || working}
                                onClick={() => callNext.mutate({ doctorId, locationId })}
                            >
                                Call next patient
                            </Button>
                        )}
                    </div>
                }
            />

            <div className="sq-now">
                <article className="sq-now-card is-current">
                    <span className="sq-now-label">Current patient</span>
                    <b className="sq-now-token">{current?.token ?? called?.token ?? '—'}</b>
                    <span className="sq-now-who">
                        {current
                            ? `${current.customer_name} · in consultation`
                            : called
                              ? `${called.customer_name} · called`
                              : 'Nobody in the room'}
                    </span>

                    <div className="sq-now-acts">
                        {current && mayComplete && (
                            <>
                                <Link className="btn btn-light btn-sm" to="/my-consultations">
                                    <i className="ti ti-clipboard-text" aria-hidden="true" /> Open write-up
                                </Link>
                                <Button size="sm" disabled={working} onClick={() => move.mutate({ id: current.id, action: 'complete' })}>
                                    Complete consultation
                                </Button>
                            </>
                        )}

                        {!current && called && mayStart && (
                            <Button size="sm" icon="ti ti-player-play" disabled={working} onClick={() => move.mutate({ id: called.id, action: 'start' })}>
                                Start consultation
                            </Button>
                        )}
                    </div>
                </article>

                <article className="sq-now-card">
                    <span className="sq-now-label">Next patient</span>
                    <b className="sq-now-token">{next?.token ?? '—'}</b>
                    <span className="sq-now-who">{next ? next.customer_name : 'Nobody waiting'}</span>
                    {next && next.priority !== 'normal' && <PriorityBadge priority={next.priority} reason={next.priority_reason} />}
                </article>

                <article className="sq-now-card">
                    <span className="sq-now-label">Waiting patients</span>
                    <b className="sq-now-token">{String(card.waiting).padStart(2, '0')}</b>
                    <span className="sq-now-who">
                        {card.waiting > 0 ? `about ${card.estimated_wait_minutes} min for somebody arriving now` : 'The list is clear'}
                    </span>
                </article>
            </div>

            {settings.paused && (
                <div className="alert alert-warning d-flex align-items-center gap-2 mt-3 mb-0">
                    <i className="ti ti-player-pause" aria-hidden="true" />
                    <span>
                        This queue is paused
                        {settings.pause_reason ? `: ${settings.pause_reason}` : ''}
                        {settings.pause_until ? ` until ${new Date(settings.pause_until).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}` : ''}.
                        Nobody new is called, unless they are an emergency.
                    </span>
                </div>
            )}

            <Card
                className="sq-board"
                actions={
                    <span className={`sq-live ${connected ? 'is-live' : 'is-off'}`}>
                        <i aria-hidden="true" />
                        {connected ? 'Live' : 'Refreshing every 10 seconds'}
                    </span>
                }
                title={
                    <Tabs
                        size="sm"
                        label="Views of this queue"
                        value={section}
                        onChange={setSection}
                        tabs={[
                            { value: 'queue', label: 'Queue', badge: data.waiting.length + inFront.length + data.skipped.length },
                            { value: 'expected', label: 'Expected', badge: data.expected.length },
                            { value: 'done', label: 'Seen', badge: data.done.length },
                            { value: 'history', label: 'History', icon: 'ti ti-history' },
                        ]}
                    />
                }
            >
                {section === 'queue' &&
                    (data.waiting.length + inFront.length + data.skipped.length === 0 ? (
                        <div className="org-pending">
                            <i className="ti ti-mood-check" />
                            <h6>The queue is clear</h6>
                            <p>Patients appear here as soon as they are checked in for this doctor.</p>
                        </div>
                    ) : (
                        <div className="q-table-scroll tbl-cards-scroll">
                            <table className="q-table tbl-cards sq-table">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Waited</th>
                                        <th>Estimate</th>
                                        <th className="q-th-act">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {[...inFront, ...data.waiting, ...data.skipped].map((row) => (
                                        <tr key={row.id} className={row.queue_status === 'skipped' ? 'sq-row-skipped' : undefined}>
                                            <td data-label="Token">
                                                <span className={`sq-token${row.queue_status === 'called' ? ' is-called' : ''}`}>{row.token}</span>
                                            </td>
                                            <td data-label="Patient">
                                                <Who row={row} />
                                            </td>
                                            <td data-label="Priority">
                                                <PriorityBadge priority={row.priority} reason={row.priority_reason} />
                                                {row.priority === 'normal' && <span className="q-dim">Normal</span>}
                                            </td>
                                            <td className="q-dim" data-label="Status">
                                                {row.queue_status === 'with_doctor'
                                                    ? 'In the room'
                                                    : row.queue_status === 'called'
                                                      ? 'Called'
                                                      : row.queue_status === 'skipped'
                                                        ? 'Skipped'
                                                        : row.patients_ahead === 0
                                                          ? 'Next'
                                                          : `${row.patients_ahead} ahead`}
                                            </td>
                                            <td className="q-dim" data-label="Waited">
                                                {row.waiting_minutes !== null ? `${row.waiting_minutes} min` : '—'}
                                            </td>
                                            <td className="q-dim" data-label="Estimate">
                                                {row.estimated_wait_minutes === null
                                                    ? '—'
                                                    : row.estimated_wait_minutes === 0
                                                      ? 'Next up'
                                                      : `about ${row.estimated_wait_minutes} min`}
                                            </td>
                                            <td className="q-row-act" data-label="">
                                                {rowActions(row)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}

                {section === 'expected' &&
                    (data.expected.length === 0 ? (
                        <div className="org-pending">
                            <i className="ti ti-calendar-check" />
                            <h6>Nobody else is expected</h6>
                            <p>Everybody booked with this doctor today has arrived or been dealt with.</p>
                        </div>
                    ) : (
                        <div className="q-table-scroll tbl-cards-scroll">
                            <table className="q-table tbl-cards sq-table">
                                <thead>
                                    <tr>
                                        <th>Booked for</th>
                                        <th>Patient</th>
                                        <th className="q-th-act">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.expected.map((row) => (
                                        <tr key={row.id}>
                                            <td data-label="Booked for">{row.slot_at ?? '—'}</td>
                                            <td data-label="Patient">
                                                <Who row={row} />
                                            </td>
                                            <td className="q-row-act" data-label="">
                                                <div className="q-actions">
                                                    {mayCheckIn && row.available.check_in && (
                                                        <button
                                                            type="button"
                                                            className="q-act is-primary"
                                                            disabled={working}
                                                            onClick={() => desk.mutate({ id: row.id, action: 'check-in' })}
                                                        >
                                                            <i className="ti ti-login" /> Check in
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}

                {section === 'done' &&
                    (data.done.length === 0 ? (
                        <div className="org-pending">
                            <i className="ti ti-clipboard-off" />
                            <h6>Nobody has been seen yet</h6>
                            <p>Finished consultations collect here through the day.</p>
                        </div>
                    ) : (
                        <div className="q-table-scroll tbl-cards-scroll">
                            <table className="q-table tbl-cards sq-table">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Finished</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.done.map((row) => (
                                        <tr key={row.id}>
                                            <td data-label="Token">
                                                <span className="sq-token">{row.token}</span>
                                            </td>
                                            <td data-label="Patient">
                                                <Who row={row} />
                                            </td>
                                            <td className="q-dim" data-label="Finished">
                                                {row.completed_at
                                                    ? new Date(row.completed_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
                                                    : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}

                {section === 'history' &&
                    (history.length === 0 ? (
                        <div className="org-pending">
                            <i className="ti ti-history" />
                            <h6>Nothing has happened yet today</h6>
                            <p>Every call, skip, priority change and move is written down here.</p>
                        </div>
                    ) : (
                        <div className="q-table-scroll tbl-cards-scroll">
                            <table className="q-table tbl-cards sq-table">
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Token</th>
                                        <th>What happened</th>
                                        <th>Patient</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map((row) => (
                                        <tr key={row.id}>
                                            <td className="q-dim" data-label="When">
                                                {new Date(row.created_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
                                            </td>
                                            <td data-label="Token">{row.token ?? '—'}</td>
                                            <td data-label="What happened">
                                                <b className="fw-semibold">{EVENT_WORDS[row.event_type] ?? row.event_type}</b>
                                                {eventDetail(row.metadata) && <small className="sq-event-detail">{eventDetail(row.metadata)}</small>}
                                            </td>
                                            <td className="q-dim" data-label="Patient">{row.customer_name ?? '—'}</td>
                                            <td className="q-dim" data-label="By">{row.actor_name ?? 'The system'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
            </Card>

            <PriorityDialog row={priorityFor} onClose={() => setPriorityFor(null)} />
            <ReassignDialog
                row={reassignFor}
                fromDoctorId={doctorId}
                doctors={board?.doctors ?? []}
                onClose={() => setReassignFor(null)}
            />
            <PauseDialog open={pausing} doctorId={doctorId} locationId={locationId} onClose={() => setPausing(false)} />
        </>
    );
}
