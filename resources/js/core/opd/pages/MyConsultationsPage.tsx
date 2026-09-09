import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMoveAppointment } from '@/core/appointments/api';
import { useMyDay } from '../api';
import { ConsultationPanel, type ConsultationTab } from '../components/ConsultationPanel';
import type { ApiResponse } from '@/shared/types/api';
import type { Consultation } from '../types';

const PER_PAGE = 8;

type Tab = 'queue' | 'consulted' | 'follow-ups' | 'no-show';

/** "9:00 AM", from a stored "09:00". */
function spoken(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}:${String(minutes).padStart(2, '0')} ${
        hours < 12 ? 'AM' : 'PM'
    }`;
}

/**
 * The write-up for whichever visit is selected.
 *
 * Its own request rather than riding on the day: the day carries the
 * consultation for the patient in the room, and this screen lets a doctor open
 * any of today's — including ones they finished an hour ago.
 */
function useConsultation(appointmentId: number | null) {
    return useQuery({
        queryKey: resourceKey('tenant/appointments', 'consultation', appointmentId),
        queryFn: async (): Promise<Consultation> => {
            const { data } = await http.get<ApiResponse<Consultation>>(
                `/tenant/appointments/${appointmentId}/consultation`,
            );

            return data.data;
        },
        enabled: appointmentId !== null,
    });
}

/**
 * Today's consultations, worked through one at a time.
 *
 * The list and the write-up on one screen, because that is the actual loop: a
 * doctor finishes somebody, glances at who is next, and starts writing. Split
 * across two screens it becomes navigate, write, navigate back — and the
 * queue's position is lost every round.
 *
 * The dashboard has the same two things, arranged the other way round: there
 * the queue is a glance and the room is the work. Here the list is a working
 * list — every patient on it can be opened, not only whoever is in the room.
 */
export default function MyConsultationsPage() {
    const { activeBranch } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);
    const move = useMoveAppointment();

    const [tab, setTab] = useState<Tab>('queue');
    const [term, setTerm] = useState('');
    const [page, setPage] = useState(1);

    const [openId, setOpenId] = useState<number | null>(null);
    const [panelTab, setPanelTab] = useState<ConsultationTab>('clinical');

    const { data: consultation } = useConsultation(openId);

    /*
     * Whoever is in the room, until somebody picks otherwise.
     *
     * A doctor arriving mid-clinic wants the person in front of them, not the
     * top of a list — and once they have chosen somebody else, the screen has
     * to stop moving under them, so the poll never re-picks.
     */
    useEffect(() => {
        if (openId === null && data?.current) setOpenId(data.current.id);
    }, [data?.current, openId]);

    const rows = data?.queue ?? [];
    const counts = data?.counts;

    const inTab = useMemo(() => {
        const list = rows.filter((row) => {
            if (tab === 'queue') {
                return ['checked_in', 'in_consultation', 'booked'].includes(row.status);
            }

            if (tab === 'consulted') return row.status === 'completed';
            if (tab === 'no-show') return row.status === 'no_show';

            // Follow-ups are known from the write-up, which only the open one
            // carries — so this tab is the ones already seen, narrowed later.
            return row.status === 'completed';
        });

        const needle = term.trim().toLowerCase();

        if (!needle) return list;

        return list.filter(
            (row) =>
                row.customer_name?.toLowerCase().includes(needle) ||
                row.customer_code?.toLowerCase().includes(needle) ||
                String(row.token_no ?? '').includes(needle),
        );
    }, [rows, tab, term]);

    const pageCount = Math.max(1, Math.ceil(inTab.length / PER_PAGE));
    const current = Math.min(page, pageCount);
    const shown = inTab.slice((current - 1) * PER_PAGE, current * PER_PAGE);

    const open = rows.find((row) => row.id === openId) ?? null;

    if (isLoading) return <LoadingBlock label="Loading your list…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    return (
        <>
            <div className="cs-hero">
                <div>
                    <h1>My consultations</h1>
                    <p>Work through today&rsquo;s list, and write each one up as you go.</p>
                </div>

                {data.current && (
                    <span className="cs-live">
                        <i aria-hidden="true" />
                        Consultation live
                    </span>
                )}
            </div>

            <div className="md-stats cs-stats">
                {(
                    [
                        ['Total patients', counts?.total ?? 0, 'ti ti-users', 'blue'],
                        ['Consulted', counts?.seen ?? 0, 'ti ti-checks', 'green'],
                        ['Waiting', counts?.waiting ?? 0, 'ti ti-hourglass', 'amber'],
                        ['Follow-up', counts?.follow_ups ?? 0, 'ti ti-calendar-repeat', 'violet'],
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

            <div className="cs-split">
                <Card>
                    <nav className="cs-tabs" role="tablist">
                        {(
                            [
                                ['queue', 'Patient queue', (counts?.waiting ?? 0) + (counts?.expected ?? 0)],
                                ['consulted', 'Consulted', counts?.seen ?? 0],
                                ['follow-ups', 'Follow-up', counts?.follow_ups ?? 0],
                                ['no-show', 'No show', counts?.no_show ?? 0],
                            ] as [Tab, string, number][]
                        ).map(([key, label, count]) => (
                            <button
                                type="button"
                                key={key}
                                role="tab"
                                aria-selected={tab === key}
                                className={`cs-tab${tab === key ? ' is-on' : ''}`}
                                onClick={() => {
                                    setTab(key);
                                    setPage(1);
                                }}
                            >
                                {label} ({count})
                            </button>
                        ))}
                    </nav>

                    <label className="md-search cs-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="text"
                            value={term}
                            placeholder="Search in today&rsquo;s list…"
                            aria-label="Search today's list"
                            onChange={(event) => {
                                setTerm(event.target.value);
                                setPage(1);
                            }}
                        />
                    </label>

                    {shown.length === 0 ? (
                        <div className="cn-none-yet">
                            <i className="ti ti-mood-check" aria-hidden="true" />
                            <b>Nothing here</b>
                            <p>{term ? 'Nothing matches that.' : 'This list is empty.'}</p>
                        </div>
                    ) : (
                        <div className="rec-scroll tbl-cards-scroll">
                            <table className="md-queue tbl-cards">
                                <thead>
                                    <tr>
                                        <th className="md-no">#</th>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Age / gender</th>
                                        <th>Time</th>
                                        <th>Status</th>
                                        <th aria-label="Action" />
                                    </tr>
                                </thead>

                                <tbody>
                                    {shown.map((row, index) => (
                                        <tr
                                            key={row.id}
                                            className={`rec-row${openId === row.id ? ' is-open' : ''}`}
                                            onClick={() => setOpenId(row.id)}
                                        >
                                            <td className="md-no md-dim" data-label="">
                                                {(current - 1) * PER_PAGE + index + 1}
                                            </td>

                                            <td data-label="Token">
                                                <span className="md-token">
                                                    {row.token_no ?? '—'}
                                                </span>
                                            </td>

                                            <td data-label="Patient">
                                                <b>{row.customer_name ?? 'Unnamed'}</b>
                                                {row.customer_code && (
                                                    <small>{row.customer_code}</small>
                                                )}
                                            </td>

                                            <td className="md-dim" data-label="Age / gender">
                                                {row.age !== null ? row.age : '—'}
                                                {row.gender
                                                    ? ` / ${row.gender.charAt(0).toUpperCase()}`
                                                    : ''}
                                            </td>

                                            <td className="md-dim" data-label="Time">
                                                {row.slot_at ? spoken(row.slot_at) : '—'}
                                            </td>

                                            <td data-label="Status">
                                                <span
                                                    className={`pt-state is-${row.status}`}
                                                >
                                                    {row.status === 'in_consultation'
                                                        ? 'Consulting'
                                                        : row.status === 'checked_in'
                                                          ? 'Waiting'
                                                          : row.status === 'booked'
                                                            ? 'Expected'
                                                            : row.status === 'completed'
                                                              ? 'Seen'
                                                              : row.status}
                                                </span>
                                            </td>

                                            <td className="md-act" data-label="">
                                                <span className="cs-open">
                                                    Open
                                                    <i
                                                        className="ti ti-arrow-right"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {inTab.length > 0 && (
                        <Pagination
                            page={current}
                            pageCount={pageCount}
                            total={inTab.length}
                            perPage={PER_PAGE}
                            onChange={setPage}
                        />
                    )}
                </Card>

                <Card className="cs-work">
                    {!open ? (
                        <div className="cn-none-yet">
                            <i className="ti ti-user-square-rounded" aria-hidden="true" />
                            <b>Nobody selected</b>
                            <p>Pick somebody from the list to write up their visit.</p>
                        </div>
                    ) : (
                        <>
                            <div className="cs-head">
                                <PersonPhoto
                                    src={null}
                                    name={open.customer_name}
                                    className="pa-face"
                                />

                                <div className="cs-who">
                                    <b>
                                        {open.customer_name ?? 'Unnamed'}
                                        {open.status === 'in_consultation' && (
                                            <em className="md-inroom">Consulting now</em>
                                        )}
                                    </b>

                                    <small>
                                        {open.customer_code ?? '—'}
                                        {open.age !== null ? ` · ${open.age} years` : ''}
                                        {open.gender ? ` · ${open.gender}` : ''}
                                    </small>
                                </div>

                                {/*
                                    The move that is legal from here, and only
                                    that one — the server owns the transitions
                                    and hands back what is next.
                                */}
                                {open.next_states.includes('in_consultation') && (
                                    <button
                                        type="button"
                                        className="md-call"
                                        disabled={move.isPending}
                                        onClick={() =>
                                            move.mutate({
                                                id: open.id,
                                                action:
                                                    open.status === 'completed'
                                                        ? 'reopen'
                                                        : 'start',
                                            })
                                        }
                                    >
                                        {open.status === 'completed' ? 'Reopen' : 'Call in'}
                                    </button>
                                )}
                            </div>

                            {consultation ? (
                                <ConsultationPanel
                                    key={open.id}
                                    appointmentId={open.id}
                                    saved={consultation}
                                    history={data.current?.id === open.id ? data.current.history : []}
                                    suggestions={
                                        data.current?.suggestions ?? {
                                            complaints: [],
                                            diagnoses: [],
                                        }
                                    }
                                    completing={move.isPending}
                                    onComplete={() =>
                                        move.mutate({ id: open.id, action: 'complete' })
                                    }
                                    tab={panelTab}
                                    onTab={setPanelTab}
                                />
                            ) : (
                                <LoadingBlock label="Loading the write-up…" />
                            )}
                        </>
                    )}
                </Card>
            </div>
        </>
    );
}
