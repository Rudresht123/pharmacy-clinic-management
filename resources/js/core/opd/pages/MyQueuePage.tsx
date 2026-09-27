import { useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Pagination } from '@/shared/components/ui/Pagination';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveConsultation } from '@/core/appointments/api';
import { NEXT_ACTION_STEP } from '@/core/appointments/workflow';
import { useMyDay } from '../api';

/** The same page length as the other lists, so nobody relearns it. */
const PER_PAGE = 20;

/** The statuses, in the order somebody moves through them. */
const TABS = [
    ['all', 'All'],
    ['in_consultation', 'In the room'],

    /*
     * Called is its own tab, and it is the doctor's actual to-do list:
     * reception has told these people to come through and they are waiting
     * on the door. Before the split it was indistinguishable from the rest
     * of the waiting room.
     */
    ['called', 'Called'],
    ['checked_in', 'Waiting'],
    ['booked', 'Expected'],
    ['completed', 'Seen'],
] as const;

const LABEL: Record<string, string> = {
    booked: 'Expected',
    checked_in: 'Waiting',
    in_consultation: 'In the room',
    awaiting_pharmacy: 'At pharmacy',
    awaiting_lab: 'At laboratory',
    awaiting_payment: 'At billing',
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
    const { activeBranch, can } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);

    // The consultation's own endpoints, not the desk's. A doctor working
    // their list starts and finishes consultations; they do not check people
    // in and they do not call them through.
    const move = useMoveConsultation();

    const [tab, setTab] = useState<string>('all');
    const [term, setTerm] = useState('');
    const [page, setPage] = useState(1);

    const rows = data?.queue ?? [];

    /**
     * Which tab a row belongs under.
     *
     * "Called" is a queue state, not a visit state, so it cannot be matched
     * on `status` like the others — and the rows under it are the same rows
     * that would otherwise be under "Waiting". Deciding once, here, is what
     * keeps the counts and the filter from disagreeing.
     */
    const tabOf = (row: (typeof rows)[number]) =>
        row.queue_status === 'called' ? 'called' : row.status;

    const counted = useMemo(
        () =>
            rows.reduce<Record<string, number>>((tally, row) => {
                const key = tabOf(row);
                tally[key] = (tally[key] ?? 0) + 1;

                return tally;
            }, {}),
        [rows],
    );

    const shown = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return rows
            .filter((row) => tab === 'all' || tabOf(row) === tab)
            .filter(
                (row) =>
                    !needle ||
                    row.customer_name?.toLowerCase().includes(needle) ||
                    row.customer_code?.toLowerCase().includes(needle) ||
                    String(row.token_no ?? '').includes(needle),
            );
    }, [rows, tab, term]);

    /*
     * Clamped rather than reset.
     *
     * Narrowing a filter from four pages to one used to leave somebody on page
     * three looking at an empty table.
     */
    const pageCount = Math.max(1, Math.ceil(shown.length / PER_PAGE));
    const current = Math.min(page, pageCount);
    const paged = shown.slice((current - 1) * PER_PAGE, current * PER_PAGE);

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
                                    onClick={() => {
                                        setTab(key);
                                        setPage(1);
                                    }}
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
                                onChange={(event) => {
                                    setTerm(event.target.value);
                                    setPage(1);
                                }}
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
                    <div className="rec-scroll tbl-cards-scroll">
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
                                {paged.map((row) => (
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

                                        {/*
                                            "Called" beats "Waiting" here.
                                            Both are `checked_in` to the visit
                                            column, and the difference is the
                                            only thing on this row a doctor
                                            can act on.
                                        */}
                                        <td className="md-dim" data-label="Status">
                                            {row.queue_status === 'called'
                                                ? 'Called'
                                                : (LABEL[row.status] ?? row.status)}
                                        </td>

                                        <td className="md-dim" data-label="Waiting">
                                            {row.waiting_minutes !== null
                                                ? `${row.waiting_minutes} min`
                                                : '—'}
                                        </td>

                                        <td className="md-act" data-label="">
                                            {/*
                                                THE DOCTOR'S THREE, and only
                                                ever one of them at a time.

                                                Each needs two things to be
                                                true: the server says the
                                                state allows it (`available`),
                                                and this login holds the
                                                capability. A doctor is never
                                                shown Start on somebody
                                                reception has not called, and
                                                never shown it twice — which
                                                is what "do not allow a
                                                consultation to be started
                                                twice" means at the screen, on
                                                top of the server refusing it.

                                                There is deliberately no
                                                "Arrived" here any more.
                                                Checking a patient in is the
                                                desk's job and this is the
                                                doctor's list.
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
                                                        Complete consultation
                                                    </button>
                                                )}

                                            {/*
                                                Finished. Not a dead cell and
                                                not a Start button that would
                                                open a second consultation —
                                                what to say is where the visit
                                                went next.
                                            */}
                                            {row.consultation_status === 'completed' && (
                                                <span className="md-dim">
                                                    {row.next_action
                                                        ? NEXT_ACTION_STEP[row.next_action].label
                                                        : 'Completed'}
                                                </span>
                                            )}

                                            {/*
                                                Still in the waiting room —
                                                booked or checked in, but the
                                                desk has not called them
                                                through, so there is nothing
                                                to start yet. Shown disabled
                                                rather than left blank: an
                                                empty cell next to rows that
                                                DO have a button reads as
                                                broken, and a doctor should
                                                not have to guess whether the
                                                row loaded correctly.
                                            */}
                                            {!row.available?.consult_start &&
                                                !row.available?.consult_complete &&
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

                {shown.length > 0 && (
                    <Pagination
                        page={current}
                        pageCount={pageCount}
                        total={shown.length}
                        perPage={PER_PAGE}
                        onChange={setPage}
                    />
                )}
            </Card>
        </>
    );
}
