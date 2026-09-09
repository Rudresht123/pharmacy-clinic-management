import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMyDay } from '../api';
import type { ApiResponse } from '@/shared/types/api';

type DayState = 'on' | 'changed' | 'off' | 'none';

interface MonthDay {
    date: string;
    day: number;
    in_month: boolean;
    is_today: boolean;
    state: DayState;
    reason: string | null;
    sessions: {
        starts_at: string;
        ends_at: string;
        name: string | null;
        location_name: string | null;
        slot_minutes: number;
        changed: boolean;
    }[];
}

interface Month {
    month: string;
    label: string;
    from: string;
    to: string;
    days: MonthDay[];
}

/** How each state reads, in words as well as colour. */
const STATE: Record<DayState, string> = {
    on: 'Sitting',
    changed: 'Different from the usual',
    off: 'Not sitting — cancelled',
    none: 'No sitting',
};

/** "9:00 AM", from a stored "09:00". */
function spoken(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}:${String(minutes).padStart(2, '0')} ${
        hours < 12 ? 'AM' : 'PM'
    }`;
}

/** "Wed, 9 Sep 2026" */
function longDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** The first of the month N months from the one given. */
function shiftMonth(month: string, by: number): string {
    const [year, index] = month.split('-').map(Number);
    const at = new Date(year, index - 1 + by, 1);

    return `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-01`;
}

function thisMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
}

function useMonth(from: string, locationId: number | null) {
    return useQuery({
        queryKey: resourceKey('tenant/opd', 'my-month', from, locationId),
        queryFn: async (): Promise<Month> => {
            const { data } = await http.get<ApiResponse<Month>>('/tenant/opd/my-month', {
                params: { from, ...(locationId ? { location_id: locationId } : {}) },
            });

            return data.data;
        },
    });
}

/**
 * When a doctor is in, as a calendar.
 *
 * A rota is a shape before it is a list — "am I in on the 18th", "how much of
 * next week am I covering", "when is my next day off" are all answered by
 * looking rather than reading, and a table of seven rows answers none of them
 * without counting.
 *
 * Read-only. A doctor does not set their own hours: that is a manager's screen
 * behind `appointments.schedule`, and an editable copy here would be two
 * screens writing one rota.
 */
export default function MySchedulePage() {
    const { activeBranch } = useTenantAuth();

    const [from, setFrom] = useState(thisMonth());
    const [picked, setPicked] = useState<string | null>(null);

    const { data, isLoading, isError, refetch } = useMonth(from, activeBranch);

    // Today's own strip still comes from the day, which has leave applied.
    const { data: today } = useMyDay(activeBranch);

    /* Whichever day is open, or today when it is in this month. */
    const open = useMemo(() => {
        const days = data?.days ?? [];

        if (picked) return days.find((day) => day.date === picked) ?? null;

        return days.find((day) => day.is_today) ?? null;
    }, [data?.days, picked]);

    const sitting = (data?.days ?? []).filter(
        (day) => day.in_month && (day.state === 'on' || day.state === 'changed'),
    ).length;

    if (isLoading) return <LoadingBlock label="Loading your schedule…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    return (
        <>
            <PageHeader
                title="My schedule"
                subtitle="When you are in, month by month — with leave and changed hours already applied."
                icon="ti ti-calendar-month"
                tone="violet"
                crumbs={[{ label: 'My day', to: '/my-day' }, { label: 'My schedule' }]}
            />

            <div className="cal-split">
                <Card>
                    <div className="cal-bar">
                        <div className="cal-when">
                            <button
                                type="button"
                                className="cal-step"
                                aria-label="The month before"
                                onClick={() => {
                                    setFrom(shiftMonth(data.month, -1));
                                    setPicked(null);
                                }}
                            >
                                <i className="ti ti-chevron-left" aria-hidden="true" />
                            </button>

                            <b>{data.label}</b>

                            <button
                                type="button"
                                className="cal-step"
                                aria-label="The month after"
                                onClick={() => {
                                    setFrom(shiftMonth(data.month, 1));
                                    setPicked(null);
                                }}
                            >
                                <i className="ti ti-chevron-right" aria-hidden="true" />
                            </button>
                        </div>

                        <div className="cal-tools">
                            <span className="cal-count">
                                {sitting} {sitting === 1 ? 'day' : 'days'} this month
                            </span>

                            <button
                                type="button"
                                className="cal-today"
                                onClick={() => {
                                    setFrom(thisMonth());
                                    setPicked(null);
                                }}
                            >
                                Today
                            </button>
                        </div>
                    </div>

                    <div className="cal">
                        {/* Monday first, as the rota is stored and read. */}
                        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((name) => (
                            <span className="cal-head" key={name}>
                                {name}
                            </span>
                        ))}

                        {data.days.map((day) => (
                            <button
                                type="button"
                                key={day.date}
                                aria-pressed={open?.date === day.date}
                                aria-label={`${longDate(day.date)} — ${STATE[day.state]}`}
                                className={[
                                    'cal-day',
                                    `is-${day.state}`,
                                    day.in_month ? '' : 'is-out',
                                    day.is_today ? 'is-today' : '',
                                    open?.date === day.date ? 'is-open' : '',
                                ]
                                    .filter(Boolean)
                                    .join(' ')}
                                onClick={() => setPicked(day.date)}
                            >
                                <b>{day.day}</b>

                                {/*
                                    The hours themselves where they fit, a dot
                                    where they do not. A month of times is
                                    unreadable; a month of marks is the shape,
                                    and the panel beside it has the detail.
                                */}
                                {day.sessions.length > 0 && (
                                    <span className="cal-times">
                                        {day.sessions.slice(0, 2).map((session, index) => (
                                            <em key={index}>
                                                {session.starts_at}–{session.ends_at}
                                            </em>
                                        ))}

                                        {day.sessions.length > 2 && (
                                            <em>+{day.sessions.length - 2} more</em>
                                        )}
                                    </span>
                                )}

                                {day.state === 'off' && (
                                    <span className="cal-times">
                                        <em>Cancelled</em>
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>

                    {/*
                        Named, not only coloured. The two that matter most — an
                        ordinary day off and a day that was cancelled — are
                        exactly the pair a colour-blind reader would lose.
                    */}
                    <ul className="cal-legend">
                        {(['on', 'changed', 'off', 'none'] as DayState[]).map((state) => (
                            <li key={state}>
                                <i className={`cal-key is-${state}`} aria-hidden="true" />
                                {STATE[state]}
                            </li>
                        ))}
                    </ul>
                </Card>

                <aside className="cal-side">
                    <Card
                        title={
                            <span className="opd-card-title">
                                {open ? longDate(open.date) : 'Pick a day'}
                                {open?.is_today && <em className="md-waiting">Today</em>}
                            </span>
                        }
                    >
                        {!open ? (
                            <p className="md-quiet">
                                Choose a day in the calendar to see its hours.
                            </p>
                        ) : open.sessions.length === 0 ? (
                            <div className="cn-none-yet">
                                <i
                                    className={
                                        open.state === 'off'
                                            ? 'ti ti-calendar-x'
                                            : 'ti ti-calendar-off'
                                    }
                                    aria-hidden="true"
                                />
                                <b>
                                    {open.state === 'off'
                                        ? 'Not sitting — cancelled'
                                        : 'Not sitting'}
                                </b>
                                <p>
                                    {open.reason ??
                                        (open.state === 'off'
                                            ? 'This day was taken off the rota.'
                                            : 'You have no hours on this weekday.')}
                                </p>
                            </div>
                        ) : (
                            <ul className="md-sched">
                                {open.sessions.map((session, index) => (
                                    <li key={index} className="is-later">
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
                                                {` · every ${session.slot_minutes} min`}
                                            </small>
                                        </span>

                                        {session.changed && (
                                            <em className="msc-changed">Changed</em>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {/* Today's own strip, whichever month is being looked at. */}
                    <Card title={<span className="opd-card-title">Today</span>}>
                        {(today?.schedule ?? []).length === 0 ? (
                            <p className="md-quiet">Not sitting today.</p>
                        ) : (
                            <ul className="md-sched">
                                {(today?.schedule ?? []).map((session, index) => (
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

                        <p className="md-soon">
                            <i className="ti ti-info-circle" aria-hidden="true" />
                            Your hours are set by the clinic. Ask whoever manages the rota to
                            change them, or to record leave for a date.
                        </p>
                    </Card>
                </aside>
            </div>
        </>
    );
}
