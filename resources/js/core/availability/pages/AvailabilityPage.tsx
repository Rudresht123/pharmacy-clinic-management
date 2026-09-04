import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Tabs } from '@/shared/components/ui/Tabs';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { locationsHooks } from '@/core/locations/api';
import { useAvailabilityDay } from '../api';
import { ExceptionsPanel } from '../components/ExceptionsPanel';
import type { AvailabilitySession } from '../types';

type View = 'day' | 'changes';

/** Today, as the API wants it. */
function today(): string {
    const now = new Date();
    const month = `${now.getMonth() + 1}`.padStart(2, '0');
    const day = `${now.getDate()}`.padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

/**
 * Who is in, where, and when.
 *
 * Everything here is derived: the weekly sittings for that weekday, minus
 * what is cancelled, with changed hours applied, plus anything extra. There
 * is no slot table — the times below are worked out for the date being
 * looked at and thrown away.
 *
 * The date and branch ride the query string, so a particular day can be
 * linked to and a refresh lands back on it.
 */
export default function AvailabilityPage() {
    const [searchParams, setSearchParams] = useSearchParams();

    const view: View = searchParams.get('view') === 'changes' ? 'changes' : 'day';
    const date = searchParams.get('date') || today();

    const { data: branches } = locationsHooks.useList({ all: 1 });

    const [locationId, setLocationId] = useState<number | ''>('');

    // The first branch, once they arrive — the screen is useless without one.
    useEffect(() => {
        if (locationId === '' && branches && branches.length > 0) {
            setLocationId(branches[0].id);
        }
    }, [branches, locationId]);

    const { data, isLoading, isError, refetch } = useAvailabilityDay(date, locationId);

    function setParam(key: string, value: string) {
        const next = new URLSearchParams(searchParams);

        if (value) {
            next.set(key, value);
        } else {
            next.delete(key);
        }

        setSearchParams(next, { replace: true });
    }

    const heading = useMemo(() => {
        const parsed = new Date(`${date}T00:00:00`);

        return Number.isNaN(parsed.getTime())
            ? date
            : parsed.toLocaleDateString('en-GB', {
                  weekday: 'long',
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
              });
    }, [date]);

    return (
        <>
            <PageHeader
                title="Availability"
                subtitle="Who is sitting where, and when. Worked out from each doctor's weekly timings and whatever is different about this date."
                icon="ti ti-calendar-time"
                tone="violet"
                crumbs={[{ label: 'Availability' }]}
            />

            <Tabs<View>
                label="Availability views"
                value={view}
                onChange={(next) => setParam('view', next === 'day' ? '' : next)}
                tabs={[
                    { value: 'day', label: 'The day', icon: 'ti ti-calendar-event' },
                    { value: 'changes', label: 'Leave & changes', icon: 'ti ti-calendar-off' },
                ]}
            />

            {view === 'changes' ? (
                <ExceptionsPanel branches={branches ?? []} />
            ) : (
                <>
                    <div className="card av-controls">
                        <label className="av-control">
                            <span>Date</span>
                            <DatePicker
                                id="availability-date"
                                label="the date"
                                value={date}
                                onChange={(value: string) => setParam('date', value || today())}
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

                        <p className="av-heading">{heading}</p>
                    </div>

                    {isLoading ? (
                        <LoadingBlock label="Working out the day…" />
                    ) : isError ? (
                        <ErrorState onRetry={() => refetch()} />
                    ) : (data?.doctors ?? []).length === 0 ? (
                        <Card>
                            <div className="org-pending">
                                <i className="ti ti-calendar-off" />
                                <h6>Nobody is sitting here</h6>
                                <p>
                                    No doctor has timings for this branch on this day — or those
                                    they had are cancelled. Check <b>Leave &amp; changes</b>, or
                                    give a doctor timings from their own screen.
                                </p>
                            </div>
                        </Card>
                    ) : (
                        <div className="row g-3">
                            {(data?.doctors ?? []).map((doctor) => (
                                <div className="col-12 col-xl-6" key={doctor.doctor_id}>
                                    <Card
                                        className="av-card"
                                        title={doctor.doctor_name}
                                        icon="ti ti-stethoscope"
                                        description={doctor.specialisation ?? undefined}
                                    >
                                        {doctor.sessions.map((session, index) => (
                                            <Session key={index} session={session} />
                                        ))}
                                    </Card>
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </>
    );
}

/**
 * One sitting, and the times it divides into.
 *
 * The slots are shown because a receptionist reads them before booking, and
 * marked as derived rather than presented as records — nothing here exists
 * until somebody books it.
 */
function Session({ session }: { session: AvailabilitySession }) {
    return (
        <div className={`av-session${session.changed ? ' is-changed' : ''}`}>
            <div className="av-session-head">
                <b>
                    {session.starts_at} – {session.ends_at}
                </b>

                {session.name && <span className="av-session-name">{session.name}</span>}

                {/* Says why today looks different from the usual week. */}
                {session.changed && (
                    <span className="av-changed">
                        <i className="ti ti-alert-circle" />
                        {session.reason || 'Changed for this date'}
                    </span>
                )}

                <span className="av-session-meta">
                    every {session.slot_minutes} min
                    {session.max_walkins !== null && ` · ${session.max_walkins} walk-ins`}
                </span>
            </div>

            <div className="av-slots">
                {session.slots.map((slot) => (
                    <span className="av-slot" key={slot}>
                        {slot}
                    </span>
                ))}
            </div>
        </div>
    );
}
