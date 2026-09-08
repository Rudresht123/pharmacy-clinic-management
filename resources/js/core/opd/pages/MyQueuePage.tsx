import { useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveAppointment } from '@/core/appointments/api';
import { useMyDay } from '../api';

/** The statuses, in the order somebody moves through them. */
const TABS = [
    ['all', 'All'],
    ['in_consultation', 'In the room'],
    ['checked_in', 'Waiting'],
    ['booked', 'Expected'],
    ['completed', 'Seen'],
] as const;

const LABEL: Record<string, string> = {
    booked: 'Expected',
    checked_in: 'Waiting',
    in_consultation: 'In the room',
    completed: 'Seen',
    cancelled: 'Cancelled',
    no_show: 'Did not come',
};

/**
 * A doctor's list, in full.
 *
 * The dashboard shows the same queue with everything else around it, which is
 * right for glancing at between patients and wrong for working through a
 * morning. This is the list on its own: every status, searchable, with the
 * moves that are legal from each row.
 *
 * Same request behind it — the doctor comes from the session either way, so a
 * second endpoint would only be a second thing to keep in step.
 */
export default function MyQueuePage() {
    const { activeBranch } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);
    const move = useMoveAppointment();

    const [tab, setTab] = useState<string>('all');
    const [term, setTerm] = useState('');

    const rows = data?.queue ?? [];

    const counted = useMemo(
        () =>
            rows.reduce<Record<string, number>>((tally, row) => {
                tally[row.status] = (tally[row.status] ?? 0) + 1;

                return tally;
            }, {}),
        [rows],
    );

    const shown = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return rows
            .filter((row) => tab === 'all' || row.status === tab)
            .filter(
                (row) =>
                    !needle ||
                    row.customer_name?.toLowerCase().includes(needle) ||
                    row.customer_code?.toLowerCase().includes(needle) ||
                    String(row.token_no ?? '').includes(needle),
            );
    }, [rows, tab, term]);

    if (isLoading) return <LoadingBlock label="Loading your queue…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    return (
        <>
            <PageHeader
                title="My queue"
                subtitle="Everybody on your list today, and what happens next for each of them."
                icon="ti ti-list-numbers"
                tone="violet"
                crumbs={[{ label: 'My day', to: '/my-day' }, { label: 'My queue' }]}
            />

            <Card
                actions={
                    <div className="md-tools">
                        <div className="md-tabs" role="group" aria-label="Filter the queue">
                            {TABS.map(([key, label]) => (
                                <button
                                    type="button"
                                    key={key}
                                    className={`md-tab${tab === key ? ' is-on' : ''}`}
                                    aria-pressed={tab === key}
                                    onClick={() => setTab(key)}
                                >
                                    {label}
                                    {key !== 'all' && counted[key] ? ` (${counted[key]})` : ''}
                                    {key === 'all' ? ` (${rows.length})` : ''}
                                </button>
                            ))}
                        </div>

                        <label className="md-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="text"
                                value={term}
                                placeholder="Name, code or token…"
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
                        <h6>Nothing here</h6>
                        <p>
                            {term || tab !== 'all'
                                ? 'Nothing on your list matches that.'
                                : 'Your list is clear for today.'}
                        </p>
                    </div>
                ) : (
                    <div className="md-scroll tbl-cards-scroll">
                        <table className="md-queue tbl-cards">
                            <thead>
                                <tr>
                                    <th>Token</th>
                                    <th>Patient</th>
                                    <th>Age / sex</th>
                                    <th>Visit</th>
                                    <th>Status</th>
                                    <th>Waiting</th>
                                    <th aria-label="Action" />
                                </tr>
                            </thead>

                            <tbody>
                                {shown.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={
                                            row.status === 'in_consultation' ? 'is-in' : undefined
                                        }
                                    >
                                        <td data-label="Token">
                                            <span className="md-token">
                                                {row.token_no ?? row.slot_at ?? '—'}
                                            </span>
                                        </td>

                                        <td data-label="Patient">
                                            <b>{row.customer_name ?? 'Unnamed'}</b>
                                            {row.customer_code && <small>{row.customer_code}</small>}
                                        </td>

                                        <td className="md-dim" data-label="Age / sex">
                                            {row.age !== null ? `${row.age}` : '—'}
                                            {row.gender
                                                ? ` / ${row.gender.charAt(0).toUpperCase()}`
                                                : ''}
                                        </td>

                                        <td data-label="Visit">
                                            <span
                                                className={`md-tag is-${
                                                    row.type === 'walk_in' ? 'walk' : 'book'
                                                }`}
                                            >
                                                {row.type === 'walk_in' ? 'Walk-in' : 'Booked'}
                                            </span>
                                        </td>

                                        <td className="md-dim" data-label="Status">{LABEL[row.status] ?? row.status}</td>

                                        <td className="md-dim" data-label="Waiting">
                                            {row.waiting_minutes !== null
                                                ? `${row.waiting_minutes} min`
                                                : '—'}
                                        </td>

                                        <td className="md-act" data-label="">
                                            {/*
                                                Only the moves the server says
                                                are legal from here — it owns
                                                the transitions and hands back
                                                what is next.
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

                                            {row.next_states.includes('in_consultation') && (
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
                                                    {row.status === 'completed' ? 'Reopen' : 'Call'}
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
        </>
    );
}
