import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Avatar } from '@/shared/components/ui/Avatar';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { resourceKey } from '@/shared/hooks/useResource';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useOpdContext } from '@/core/opd/useOpdContext';
import { BranchPicker } from '../components/BranchPicker';
import { BookDialog } from '@/core/appointments/components/BookDialog';
import { QueueStateBadge } from '../components/QueueBadges';
import { useCallNext, useQueueBoard, useQueueLive } from '../api';
import type { QueueBoardPayload, QueueMessage } from '../types';

/**
 * The reception desk's view of every doctor at once.
 *
 * One row per doctor, each with its own Call next: there is no button here that
 * moves "the queue", because there is no such thing. Clicking a doctor opens
 * their queue in full, which is where somebody checks a patient in, moves them,
 * or changes their priority.
 *
 * A message from a doctor's channel replaces that doctor's row where it is, and
 * the numbers only a signed-in screen sees catch up a moment later.
 */
export default function QueueBoardPage() {
    const navigate = useNavigate();
    const client = useQueryClient();
    const { can, queueStep } = useTenantAuth();

    const context = useOpdContext();
    const { branchId, branch, branchesLoading, hasNoBranch, date } = context;

    const [departmentId, setDepartmentId] = useState<number | ''>('');
    const [booking, setBooking] = useState(false);
    const [live, setLive] = useState(false);

    const callNext = useCallNext();
    const { data, isLoading, isError, refetch } = useQueueBoard(branchId, departmentId, live);

    const connected = useQueueLive(data?.channels, (message: QueueMessage) => {
        client.setQueryData<QueueBoardPayload>(
            resourceKey('tenant/queue', 'board', branchId, departmentId),
            (current) =>
                current && {
                    ...current,
                    cards: current.cards.map((card) =>
                        card.doctor_id === message.doctor_id ? { ...card, ...message.card } : card,
                    ),
                },
        );
    });

    useEffect(() => setLive(connected), [connected]);

    if (branchesLoading) {
        return <LoadingBlock label="Loading…" />;
    }

    const cards = data?.cards ?? [];
    const mayCall = can('queue.control') && queueStep('call');
    const waiting = cards.reduce((sum, card) => sum + card.waiting, 0);
    const inRooms = cards.filter((card) => card.serving !== null).length;
    const longest = cards.reduce((worst, card) => Math.max(worst, card.longest_wait_minutes), 0);

    return (
        <>
            <PageHeader
                title="Live queue"
                subtitle={branch ? `Every doctor at ${branch.name}, right now.` : 'Every doctor, right now.'}
                icon="ti ti-broadcast"
                tone="sky"
                crumbs={[{ label: 'OPD', to: '/opd' }, { label: 'Live queue' }]}
                actions={
                    <div className="q-head-acts">
                        <BranchPicker context={context} />

                        {can('queue.displays') && (
                            <Link className="btn btn-light" to="/settings/tv-displays">
                                <i className="ti ti-device-tv" aria-hidden="true" /> TV displays
                            </Link>
                        )}

                        {can('appointments.book') && (
                            <Button icon="ti ti-user-plus" disabled={!branchId} onClick={() => setBooking(true)}>
                                Walk-in
                            </Button>
                        )}
                    </div>
                }
            />

            {hasNoBranch ? (
                <Card>
                    <div className="org-pending">
                        <i className="ti ti-map-pin-off" />
                        <h6>You are not attached to a branch</h6>
                        <p>A queue belongs to a branch. Ask whoever manages your organization to add you to the one you work at.</p>
                    </div>
                </Card>
            ) : (
                <>
                    <div className="opd-stats sq-stats">
                        <article className="opd-stat is-sky">
                            <span className="opd-stat-icon">
                                <i className="ti ti-stethoscope" aria-hidden="true" />
                            </span>
                            <b>{isLoading ? '—' : cards.length}</b>
                            <span className="opd-stat-name">Doctors on the board</span>
                            <small>{isLoading ? '' : `${inRooms} with a patient`}</small>
                        </article>

                        <article className="opd-stat is-violet">
                            <span className="opd-stat-icon">
                                <i className="ti ti-hourglass" aria-hidden="true" />
                            </span>
                            <b>{isLoading ? '—' : waiting}</b>
                            <span className="opd-stat-name">Waiting</span>
                            <small>{waiting === 0 ? 'Nobody waiting' : longest > 0 ? `Longest ${longest} min` : 'Just arrived'}</small>
                        </article>

                        <article className="opd-stat is-amber">
                            <span className="opd-stat-icon">
                                <i className="ti ti-urgent" aria-hidden="true" />
                            </span>
                            <b>{isLoading ? '—' : cards.reduce((sum, card) => sum + card.emergency + card.urgent, 0)}</b>
                            <span className="opd-stat-name">Priority patients</span>
                            <small>emergency and urgent waiting</small>
                        </article>

                        <article className="opd-stat is-teal">
                            <span className="opd-stat-icon">
                                <i className="ti ti-circle-check" aria-hidden="true" />
                            </span>
                            <b>{isLoading ? '—' : cards.reduce((sum, card) => sum + card.seen, 0)}</b>
                            <span className="opd-stat-name">Seen today</span>
                            <small>{isLoading ? '' : `${cards.reduce((sum, card) => sum + card.expected, 0)} still expected`}</small>
                        </article>
                    </div>

                    <Card
                        className="sq-board"
                        actions={
                            <div className="sq-board-tools">
                                <span className={`sq-live ${connected ? 'is-live' : 'is-off'}`}>
                                    <i aria-hidden="true" />
                                    {connected ? 'Live' : 'Refreshing every 10 seconds'}
                                </span>

                                {(data?.departments ?? []).length > 0 && (
                                    <SearchableSelect
                                        compact
                                        className="q-doctor-pick"
                                        value={String(departmentId)}
                                        ariaLabel="Filter by department"
                                        placeholder="All departments"
                                        clearable
                                        onChange={(next) => setDepartmentId(next === '' ? '' : Number(next))}
                                        options={(data?.departments ?? []).map((department) => ({
                                            value: String(department.id),
                                            label: department.name,
                                        }))}
                                    />
                                )}
                            </div>
                        }
                    >
                        {isLoading ? (
                            <LoadingBlock label="Loading the queue…" />
                        ) : isError ? (
                            <ErrorState message="Unable to load the live queue." onRetry={() => refetch()} />
                        ) : cards.length === 0 ? (
                            <div className="org-pending">
                                <i className="ti ti-stethoscope" />
                                <h6>No doctors are sitting here today</h6>
                                <p>
                                    {departmentId !== ''
                                        ? 'Nobody in this department is sitting today. Clear the filter to see the whole branch.'
                                        : "A doctor appears here once they have a sitting today, or somebody in their queue."}
                                </p>
                            </div>
                        ) : (
                            <div className="q-table-scroll tbl-cards-scroll">
                                <table className="q-table tbl-cards sq-table">
                                    <thead>
                                        <tr>
                                            <th>Doctor</th>
                                            <th>Room</th>
                                            <th>Serving</th>
                                            <th>Waiting</th>
                                            <th>Next</th>
                                            <th>Estimated wait</th>
                                            <th>Status</th>
                                            <th className="q-th-act">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {cards.map((card) => (
                                            <tr
                                                key={card.doctor_id}
                                                className="sq-row"
                                                onClick={() => navigate(`/opd/live-queue/${card.doctor_id}?branch=${branchId}`)}
                                            >
                                                <td data-label="Doctor">
                                                    <span className="sq-doctor">
                                                        <Avatar name={card.doctor_name} src={card.photo_url} size={34} preview={false} />
                                                        <span>
                                                            <Link
                                                                to={`/opd/live-queue/${card.doctor_id}?branch=${branchId}`}
                                                                onClick={(event) => event.stopPropagation()}
                                                            >
                                                                {card.doctor_name}
                                                            </Link>
                                                            {card.department && <small>{card.department}</small>}
                                                        </span>
                                                    </span>
                                                </td>

                                                <td data-label="Room">{card.room ?? <span className="q-dim">Not set</span>}</td>

                                                <td data-label="Serving">
                                                    {card.now_calling ? (
                                                        <span className={`sq-token${card.now_calling_state === 'called' ? ' is-called' : ''}`}>
                                                            {card.now_calling}
                                                        </span>
                                                    ) : (
                                                        <span className="q-dim">—</span>
                                                    )}
                                                    {card.now_calling_state === 'called' && <small className="sq-called">called</small>}
                                                </td>

                                                <td data-label="Waiting">
                                                    <b className="sq-count">{card.waiting}</b>
                                                    {card.emergency + card.urgent > 0 && (
                                                        <small className="sq-priority-count">{card.emergency + card.urgent} priority</small>
                                                    )}
                                                </td>

                                                <td data-label="Next">
                                                    {card.next[0] ? <span className="sq-token is-next">{card.next[0]}</span> : <span className="q-dim">—</span>}
                                                </td>

                                                <td className="q-dim" data-label="Estimated wait">
                                                    {card.waiting > 0 ? `about ${card.estimated_wait_minutes} min` : '—'}
                                                </td>

                                                <td data-label="Status">
                                                    <QueueStateBadge
                                                        paused={card.paused}
                                                        availability={card.availability}
                                                        startsAt={card.session_starts_at}
                                                    />
                                                </td>

                                                <td className="q-row-act" data-label="">
                                                    <div className="q-actions">
                                                        {mayCall && (
                                                            <button
                                                                type="button"
                                                                className="q-act is-primary"
                                                                disabled={card.waiting === 0 || callNext.isPending}
                                                                onClick={(event) => {
                                                                    event.stopPropagation();
                                                                    callNext.mutate({ doctorId: card.doctor_id, locationId: Number(branchId) });
                                                                }}
                                                            >
                                                                <i className="ti ti-bell-ringing" />
                                                                Call next
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </>
            )}

            {can('appointments.book') && (
                <BookDialog
                    open={booking}
                    onClose={() => setBooking(false)}
                    locationId={branchId}
                    date={date}
                    branchName={branch?.name ?? ''}
                />
            )}
        </>
    );
}
