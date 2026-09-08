import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useMyDay } from '../api';

/** "09:00" → "9:00 AM", for reading rather than editing. */
function spoken(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}:${String(minutes).padStart(2, '0')} ${
        hours < 12 ? 'AM' : 'PM'
    }`;
}

/**
 * When a doctor is normally in, and what is happening today.
 *
 * Two different questions that look alike, so they are two sections rather
 * than one table. The week is the pattern somebody agreed; today is that
 * pattern with leave applied and moved hours honoured — and the day they
 * differ is the only day either matters.
 *
 * Read-only. A doctor does not set their own rota: that is a manager's screen,
 * behind `appointments.schedule`, and putting an editable copy here would be
 * two screens writing one week.
 */
export default function MySchedulePage() {
    const { activeBranch } = useTenantAuth();

    const { data, isLoading, isError, refetch } = useMyDay(activeBranch);

    if (isLoading) return <LoadingBlock label="Loading your schedule…" />;
    if (isError || !data) return <ErrorState onRetry={() => refetch()} />;

    const sitting = data.week.filter((day) => day.sittings.length > 0).length;

    return (
        <>
            <PageHeader
                title="My schedule"
                subtitle="Your usual week, and what is actually happening today."
                icon="ti ti-clock-hour-4"
                tone="violet"
                crumbs={[{ label: 'My day', to: '/my-day' }, { label: 'My schedule' }]}
            />

            <div className="ms-split">
                <Card
                    title={
                        <span className="opd-card-title">
                            <i className="ti ti-calendar-week" aria-hidden="true" />
                            Usual week
                        </span>
                    }
                >
                    <p className="sc-lede">
                        {sitting === 0
                            ? 'No hours are set for you yet.'
                            : `You sit on ${sitting} ${sitting === 1 ? 'day' : 'days'} a week.`}
                    </p>

                    <ul className="msc-week">
                        {data.week.map((day) => (
                            <li
                                key={day.weekday}
                                className={day.sittings.length === 0 ? 'is-off' : undefined}
                            >
                                <span className="msc-day">{day.label}</span>

                                {day.sittings.length === 0 ? (
                                    <span className="md-quiet">Not sitting</span>
                                ) : (
                                    <span className="msc-list">
                                        {day.sittings.map((session, index) => (
                                            <span className="msc-one" key={index}>
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
                                        ))}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card
                    title={
                        <span className="opd-card-title">
                            <i className="ti ti-calendar-event" aria-hidden="true" />
                            Today
                        </span>
                    }
                >
                    {data.schedule.length === 0 ? (
                        <div className="org-pending">
                            <i className="ti ti-calendar-off" />
                            <h6>Not sitting today</h6>
                            <p>Nothing is scheduled for you on this date.</p>
                        </div>
                    ) : (
                        <ul className="md-sched">
                            {data.schedule.map((session, index) => (
                                <li key={index} className={`is-${session.state}`}>
                                    <i aria-hidden="true" />

                                    <span>
                                        <b>
                                            {spoken(session.starts_at)} – {spoken(session.ends_at)}
                                        </b>
                                        <small>
                                            {session.name ?? 'OPD'}
                                            {session.location_name
                                                ? ` · ${session.location_name}`
                                                : ''}
                                        </small>
                                    </span>

                                    {/*
                                        Said where it matters. A doctor glancing
                                        at today needs to know their hours moved
                                        far more than they need the old ones.
                                    */}
                                    {session.changed && (
                                        <em className="msc-changed">Changed</em>
                                    )}

                                    {session.state === 'now' && <em className="md-now">Now</em>}
                                </li>
                            ))}
                        </ul>
                    )}

                    <p className="md-soon">
                        <i className="ti ti-info-circle" aria-hidden="true" />
                        Your hours are set by the clinic. Ask whoever manages the rota to change
                        them, or to record leave for a date.
                    </p>
                </Card>
            </div>
        </>
    );
}
