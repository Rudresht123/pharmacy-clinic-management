import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { locationsHooks } from '@/core/locations/api';
import { Link } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useAvailabilityDay, useAvailabilityWeek, useScheduleExceptions } from '../api';
import { WeekGrid } from '../components/WeekGrid';
import { DoctorPanel } from '../components/DoctorPanel';
import { ExceptionsPanel } from '../components/ExceptionsPanel';
import type { AvailabilitySession, WeekDoctor } from '../types';

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
    const [params, setParams] = useSearchParams();

    const { data: branches } = locationsHooks.useList({ all: 1 });
    const { activeBranch } = useTenantAuth();

    /*
     * Branch, week and view all live in the URL.
     *
     * A rota is the thing people send each other — "look at Gurgaon next week"
     * is a link, not a set of instructions — and a screen that forgets which
     * week it was on when you press back is a screen nobody links to.
     */
    const branchId = Number(params.get('branch')) || activeBranch || '';
    const view = (params.get('view') ?? 'week') as 'week' | 'doctors' | 'exceptions';
    const from = params.get('from') || mondayOf(new Date());

    function set(next: Record<string, string>) {
        const merged = new URLSearchParams(params);

        Object.entries(next).forEach(([key, value]) => merged.set(key, value));
        setParams(merged, { replace: true });
    }

    const [selected, setSelected] = useState<WeekDoctor | null>(null);

    const week = useAvailabilityWeek(from, branchId);
    const day = useAvailabilityDay(from, branchId);

    const { data: upcoming } = useScheduleExceptions(
        branchId ? { location_id: branchId } : {},
    );

    // Whichever branch is being looked at, by name.
    const branch = (branches ?? []).find((entry) => entry.id === branchId);

    return (
        <>
            <PageHeader
                title="Doctor availability"
                subtitle="Who is sitting where, and when. Built from the weekly pattern and the changes made to it."
                icon="ti ti-calendar-time"
                tone="violet"
                crumbs={[{ label: 'OPD', to: '/opd' }, { label: 'Availability' }]}
                actions={
                    <Link className="av-add" to="/doctors">
                        <i className="ti ti-plus" aria-hidden="true" />
                        Set a doctor&rsquo;s hours
                    </Link>
                }
            />

            <div className="av-bar">
                <label className="av-field">
                    <span>Branch</span>
                    <select
                        className="form-select"
                        value={branchId}
                        onChange={(event) => set({ branch: event.target.value })}
                    >
                        {(branches ?? []).map((entry) => (
                            <option key={entry.id} value={entry.id}>
                                {entry.name}
                            </option>
                        ))}
                    </select>
                </label>

                {/*
                    Three readings of the same rota, not three screens. The
                    week is the shape, the day is the detail behind one column
                    of it, and the changes are the reasons the two differ.
                */}
                <div className="av-views" role="group" aria-label="How to show availability">
                    {(
                        [
                            ['week', 'Week', 'ti ti-layout-grid'],
                            ['doctors', 'Day', 'ti ti-clock-hour-4'],
                            ['exceptions', 'Changes', 'ti ti-calendar-exclamation'],
                        ] as const
                    ).map(([key, label, icon]) => (
                        <button
                            type="button"
                            key={key}
                            className={`av-view${view === key ? ' is-on' : ''}`}
                            aria-pressed={view === key}
                            onClick={() => set({ view: key })}
                        >
                            <i className={icon} aria-hidden="true" />
                            {label}
                        </button>
                    ))}
                </div>

                {view !== 'exceptions' && (
                    <div className="av-when">
                        <button
                            type="button"
                            className="av-step"
                            aria-label="The week before"
                            onClick={() => set({ from: shift(from, -7) })}
                        >
                            <i className="ti ti-chevron-left" aria-hidden="true" />
                        </button>

                        <span className="av-range">
                            {view === 'week'
                                ? `${spoken(from)} – ${spoken(shift(from, 6))}`
                                : spoken(from)}
                        </span>

                        <button
                            type="button"
                            className="av-step"
                            aria-label="The week after"
                            onClick={() => set({ from: shift(from, 7) })}
                        >
                            <i className="ti ti-chevron-right" aria-hidden="true" />
                        </button>

                        <button
                            type="button"
                            className="av-today"
                            onClick={() => set({ from: mondayOf(new Date()) })}
                        >
                            Today
                        </button>
                    </div>
                )}
            </div>

            {view === 'exceptions' ? (
                <ExceptionsPanel branches={branches ?? []} />
            ) : view === 'doctors' ? (
                <Card>
                    {day.isLoading ? (
                        <LoadingBlock label="Loading the day…" />
                    ) : day.isError ? (
                        <ErrorState onRetry={() => day.refetch()} />
                    ) : (day.data?.doctors ?? []).length === 0 ? (
                        <div className="org-pending">
                            <i className="ti ti-calendar-off" />
                            <h6>Nobody is sitting on this day</h6>
                            <p>
                                No doctor has hours at {branch?.name ?? 'this branch'} on{' '}
                                {spoken(from)}.
                            </p>
                        </div>
                    ) : (
                        <div className="row g-3">
                            {(day.data?.doctors ?? []).map((doctor) => (
                                <div className="col-12 col-xl-6" key={doctor.doctor_id}>
                                    <DoctorDay doctor={doctor} />
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            ) : (
                <div className={`av-week${selected ? ' has-panel' : ''}`}>
                    <Card>
                        {week.isLoading ? (
                            <LoadingBlock label="Loading the week…" />
                        ) : week.isError ? (
                            <ErrorState onRetry={() => week.refetch()} />
                        ) : (week.data?.doctors ?? []).length === 0 ? (
                            <div className="org-pending">
                                <i className="ti ti-user-off" />
                                <h6>No doctors here yet</h6>
                                <p>
                                    Nobody is posted to {branch?.name ?? 'this branch'}. Assign a
                                    doctor to it from their own record and their week appears here.
                                </p>
                            </div>
                        ) : (
                            <WeekGrid
                                week={week.data!}
                                selected={selected?.doctor_id ?? null}
                                onSelect={(doctor) =>
                                    setSelected((was) =>
                                        was?.doctor_id === doctor.doctor_id ? null : doctor,
                                    )
                                }
                            />
                        )}
                    </Card>

                    {selected && (
                        <DoctorPanel
                            doctor={selected}
                            branchId={branchId}
                            onClose={() => setSelected(null)}
                        />
                    )}
                </div>
            )}

            {/*
                What is about to differ from the pattern, under the pattern
                itself. A rota is read forwards: the week answers "what is
                happening", and the only useful follow-up is "what is about to
                change".
            */}
            {view === 'week' && (upcoming ?? []).length > 0 && (
                <Card
                    title={
                        <span className="opd-card-title">
                            <i className="ti ti-calendar-exclamation" aria-hidden="true" />
                            Upcoming changes
                        </span>
                    }
                    actions={
                        <button
                            type="button"
                            className="av-viewall"
                            onClick={() => set({ view: 'exceptions' })}
                        >
                            View all
                        </button>
                    }
                >
                    <div className="av-table-scroll">
                        <table className="av-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Doctor</th>
                                    <th>Branch</th>
                                    <th>Change</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>

                            <tbody>
                                {(upcoming ?? []).slice(0, 6).map((change) => (
                                    <tr key={change.id}>
                                        <td className="av-date">{spoken(change.date)}</td>
                                        <td>{change.doctor_name}</td>
                                        <td className="av-dim">{change.location_name ?? '—'}</td>

                                        <td>
                                            {change.type === 'unavailable' ? (
                                                <span className="av-tag is-off">Unavailable</span>
                                            ) : change.type === 'changed_hours' ? (
                                                <span className="av-tag is-moved">
                                                    {change.starts_at} → {change.ends_at}
                                                </span>
                                            ) : (
                                                <span className="av-tag is-extra">Extra clinic</span>
                                            )}
                                        </td>

                                        <td className="av-dim">{change.reason ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </>
    );
}

/** The Monday of whatever week a date falls in. */
function mondayOf(date: Date): string {
    const at = new Date(date.getFullYear(), date.getMonth(), date.getDate());

    // getDay() is Sunday-first; the rota is Monday-first, as the server stores.
    at.setDate(at.getDate() - ((at.getDay() + 6) % 7));

    return iso(at);
}

/** N days on or back, built from the parts so a clock change cannot slide it. */
function shift(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);

    return iso(new Date(year, month - 1, day + days));
}

function iso(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** "7 Sep 2026" — the date as somebody would say it. */
function spoken(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}


/**
 * One doctor's sittings on the chosen date.
 *
 * The Day view's unit. It was written inline in the page before the week
 * arrived; pulling it out is what lets both views render the same thing rather
 * than two versions that drift.
 */
function DoctorDay({
    doctor,
}: {
    doctor: { doctor_id: number; doctor_name: string; specialisation: string | null; sessions: AvailabilitySession[] };
}) {
    return (
        <Card className="av-card">
            <p className="av-doc">
                <b>{doctor.doctor_name}</b>
                {doctor.specialisation && <small>{doctor.specialisation}</small>}
            </p>

            {doctor.sessions.map((session, index) => (
                <Session session={session} key={index} />
            ))}
        </Card>
    );
}

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
