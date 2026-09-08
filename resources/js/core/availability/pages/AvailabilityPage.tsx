import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
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

    /*
     * Which speciality, if any.
     *
     * Not in the URL with the rest: branch and week say which rota you are
     * looking at, and a department is a squint at it. Somebody sending a
     * colleague a link means the week, not the fact that they had ENT ticked.
     */
    const [department, setDepartment] = useState('');

    const week = useAvailabilityWeek(from, branchId);
    const day = useAvailabilityDay(from, branchId);

    const { data: upcoming } = useScheduleExceptions(
        branchId ? { location_id: branchId } : {},
    );

    // Whichever branch is being looked at, by name.
    const branch = (branches ?? []).find((entry) => entry.id === branchId);

    /* The departments actually represented here, not a fixed list. */
    const departments = Array.from(
        new Set((week.data?.doctors ?? []).map((doctor) => doctor.specialisation).filter(Boolean)),
    ).sort() as string[];

    const shown = department
        ? (week.data?.doctors ?? []).filter((doctor) => doctor.specialisation === department)
        : (week.data?.doctors ?? []);

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
                        Add schedule
                    </Link>
                }
            />

            <div className="av-bar">
                <label className="av-pick">
                    <span>Branch</span>
                    <SearchableSelect
                        value={String(branchId)}
                        onChange={(next) => set({ branch: next })}
                        ariaLabel="Branch"
                        options={(branches ?? []).map((entry) => ({
                            value: String(entry.id),
                            label: entry.name,
                        }))}
                    />
                </label>

                {/*
                    Only when there is more than one to choose between. A
                    filter whose every setting shows the same six people is a
                    control that can only waste a click.
                */}
                {departments.length > 1 && (
                    <label className="av-pick">
                        <span>Department</span>
                        <SearchableSelect
                            value={department}
                            onChange={setDepartment}
                            ariaLabel="Department"
                            placeholder="All departments"
                            clearable
                            options={departments.map((name) => ({
                                value: name,
                                label: name,
                            }))}
                        />
                    </label>
                )}

                {/*
                    Three readings of the same rota, not three screens. The
                    week is the shape, the day is the detail behind one column
                    of it, and the exceptions are the reasons the two differ.
                */}
                <div className="av-pick">
                    <span>View</span>

                    <div className="av-views" role="group" aria-label="How to show availability">
                        {(
                            [
                                ['week', 'Week'],
                                ['doctors', 'Doctors'],
                                ['exceptions', 'Exceptions'],
                            ] as const
                        ).map(([key, label]) => (
                            <button
                                type="button"
                                key={key}
                                className={`av-view${view === key ? ' is-on' : ''}`}
                                aria-pressed={view === key}
                                onClick={() => set({ view: key })}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                {view !== 'exceptions' && (
                    <div className="av-when">
                        {/*
                            The arrows and the range are one control, inside
                            one border. Three separately bordered pieces in a
                            row read as three decisions when they are one
                            decision seen three ways.
                        */}
                        <div className="av-range-group">
                            <button
                                type="button"
                                className="av-step"
                                aria-label="The week before"
                                onClick={() => set({ from: shift(from, -7) })}
                            >
                                <i className="ti ti-chevron-left" aria-hidden="true" />
                            </button>

                            <span className="av-range">
                                <i className="ti ti-calendar" aria-hidden="true" />
                                {view === 'week' ? spanned(from) : spoken(from)}
                            </span>

                            <button
                                type="button"
                                className="av-step"
                                aria-label="The week after"
                                onClick={() => set({ from: shift(from, 7) })}
                            >
                                <i className="ti ti-chevron-right" aria-hidden="true" />
                            </button>
                        </div>

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
                        <ul className="dl">
                            {/*
                                One list, full width — not a grid of cards.

                                Two-up, a doctor with sixteen slots sat beside
                                one with three and the shorter card padded
                                itself out to match; an odd number left the
                                last one stranded next to a gap. Neither is a
                                layout that survives a clinic adding its
                                seventh doctor.
                            */}
                            {(day.data?.doctors ?? []).map((doctor) => (
                                <DoctorDay doctor={doctor} key={doctor.doctor_id} />
                            ))}
                        </ul>
                    )}
                </Card>
            ) : (
                <div className="av-week has-panel">
                    <Card>
                        {week.isLoading ? (
                            <LoadingBlock label="Loading the week…" />
                        ) : week.isError ? (
                            <ErrorState onRetry={() => week.refetch()} />
                        ) : shown.length === 0 ? (
                            <div className="org-pending">
                                <i className="ti ti-user-off" />
                                <h6>No doctors here yet</h6>
                                <p>
                                    {department
                                        ? `Nobody in ${department} is posted to ${branch?.name ?? 'this branch'}.`
                                        : `Nobody is posted to ${branch?.name ?? 'this branch'}. Assign a doctor to it from their own record and their week appears here.`}
                                </p>
                            </div>
                        ) : (
                            <WeekGrid
                                week={week.data!}
                                doctors={shown}
                                selected={selected?.doctor_id ?? null}
                                onSelect={(doctor) =>
                                    setSelected((was) =>
                                        was?.doctor_id === doctor.doctor_id ? null : doctor,
                                    )
                                }
                            />
                        )}
                    </Card>

                    {/*
                        The panel's place is held whether or not one is open.
                        Letting the grid stretch across the full width and then
                        snap back to two thirds the moment somebody clicks a
                        name re-flows every column under the cursor — the week
                        they were reading moves while they are reading it.
                    */}
                    {selected ? (
                        <DoctorPanel
                            doctor={selected}
                            branchId={branchId}
                            onClose={() => setSelected(null)}
                        />
                    ) : (
                        <aside className="docp docp-empty">
                            <i className="ti ti-user-square-rounded" aria-hidden="true" />
                            <b>No doctor selected</b>
                            <p>
                                Pick a name from the grid to see their weekly hours, what is
                                changing, and how to reach them.
                            </p>
                        </aside>
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
                            Upcoming exceptions
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
                                    <th>Recorded by</th>
                                </tr>
                            </thead>

                            <tbody>
                                {(upcoming ?? []).slice(0, 6).map((change) => (
                                    <tr key={change.id}>
                                        <td className="av-date">{spoken(change.date)}</td>
                                        <td>{change.doctor_name}</td>
                                        {/*
                                            Leave takes the doctor off
                                            everywhere, so it has no branch —
                                            which is a fact, not a gap. A dash
                                            there reads as missing data.
                                        */}
                                        <td className="av-dim">
                                            {change.location_name ??
                                                (change.whole_day ? 'All branches' : '—')}
                                        </td>

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
                                        <td className="av-dim">{change.created_by_name ?? '—'}</td>
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

/**
 * "7 – 13 Sep 2026", and "28 Sep – 4 Oct 2026" when the week crosses a month.
 *
 * Naming September twice in a range that never leaves it is the sort of
 * padding that makes a header wrap on a laptop for no information at all.
 */
function spanned(monday: string): string {
    const sunday = shift(monday, 6);
    const [, aMonth] = monday.split('-').map(Number);
    const [, bMonth] = sunday.split('-').map(Number);

    if (aMonth === bMonth) {
        return `${Number(monday.split('-')[2])} – ${spoken(sunday)}`;
    }

    return `${spoken(monday).replace(/ \d{4}$/, '')} – ${spoken(sunday)}`;
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
        <li className="dl-row">
            {/*
                Who, held to one side. The name is what the eye comes back to
                when scanning down, so it keeps a column of its own rather than
                sitting on top of the times as a heading.
            */}
            <div className="dl-who">
                <span className="dl-face" aria-hidden="true">
                    {doctor.doctor_name.replace(/^Dr\.?\s*/i, '').charAt(0)}
                </span>

                <span className="dl-name">
                    <b>{doctor.doctor_name}</b>
                    {doctor.specialisation && <small>{doctor.specialisation}</small>}
                </span>
            </div>

            <div className="dl-sessions">
                {doctor.sessions.map((session, index) => (
                    <Session session={session} key={index} />
                ))}
            </div>
        </li>
    );
}

function Session({ session }: { session: AvailabilitySession }) {
    return (
        <div className={`dl-session${session.changed ? ' is-changed' : ''}`}>
            <div className="dl-session-head">
                <b>
                    {session.starts_at} – {session.ends_at}
                </b>

                {session.name && <span className="dl-tag">{session.name}</span>}

                {/* Says why today looks different from the usual week. */}
                {session.changed && (
                    <span className="dl-changed">
                        <i className="ti ti-alert-circle" aria-hidden="true" />
                        {session.reason || 'Changed for this date'}
                    </span>
                )}

                {session.max_walkins !== null && (
                    <span className="dl-meta">{session.max_walkins} walk-ins</span>
                )}
            </div>

            {/*
                The slot times, folded away.

                Sixteen chips per doctor is most of the screen spent on numbers
                that are entirely predictable from "09:00 – 13:00, every 15
                min" — and the row that matters, who is in and how long they
                have, was buried under them. The count and the interval answer
                the question; the times are there for whoever is booking.

                A native disclosure, so it needs no state and opens on the
                keyboard for nothing.
            */}
            <details className="dl-more">
                <summary>
                    <i className="ti ti-chevron-right" aria-hidden="true" />
                    {session.slots.length} slots · every {session.slot_minutes} min
                </summary>

                <div className="dl-slots">
                    {session.slots.map((slot) => (
                        <span className="dl-slot" key={slot}>
                            {slot}
                        </span>
                    ))}
                </div>
            </details>
        </div>
    );
}
