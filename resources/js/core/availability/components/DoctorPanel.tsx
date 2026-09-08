import { useState } from 'react';
import { Link } from 'react-router-dom';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { doctorsHooks, useDoctorSchedules } from '@/core/doctors/api';
import { useScheduleExceptions } from '../api';
import type { DoctorSchedule } from '@/core/doctors/types';
import type { ScheduleException, WeekDoctor } from '../types';

/** Monday 0 … Sunday 6 — the order the server stores and the week reads. */
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/** "9:00 AM", from a stored "09:00". */
function clock(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);
    const suffix = hours < 12 ? 'AM' : 'PM';
    const shown = hours % 12 === 0 ? 12 : hours % 12;

    return `${shown}:${String(minutes).padStart(2, '0')} ${suffix}`;
}

/** "18 Sep 2026" */
function longDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

type Tab = 'schedule' | 'exceptions' | 'details';

/**
 * One doctor, beside the week they appear in.
 *
 * The grid says what is happening; this says what is supposed to. The two
 * differ exactly where somebody has changed something, and a manager looking
 * at a red Wednesday wants the usual pattern and the reason side by side
 * rather than one after the other on separate screens.
 */
export function DoctorPanel({
    doctor,
    branchId,
    onClose,
}: {
    doctor: WeekDoctor;
    branchId: number | '';
    onClose: () => void;
}) {
    const [tab, setTab] = useState<Tab>('schedule');

    const { data: full, isLoading } = doctorsHooks.useDetail(String(doctor.doctor_id));

    // The week itself comes from its own endpoint — the doctor record carries
    // the person, not their timetable.
    const { data: sittings } = useDoctorSchedules(doctor.doctor_id);

    /*
     * Their own changes, from today onwards.
     *
     * Scoped to this branch as well as this doctor: somebody covering two
     * sites has leave at one of them, and a panel opened from the Gurgaon week
     * that listed Delhi's cancellations would be answering a question nobody
     * asked.
     */
    const { data: exceptions } = useScheduleExceptions({
        doctor_id: doctor.doctor_id,
        location_id: branchId || undefined,
    });

    const upcoming: ScheduleException[] = exceptions ?? [];

    /* The weekly pattern at this branch, as seven named rows. */
    const week = DAYS.map((label, weekday) => ({
        label,
        sittings: (sittings ?? []).filter(
            (sitting: DoctorSchedule) =>
                sitting.weekday === weekday &&
                (branchId === '' || sitting.location_id === branchId),
        ),
    }));

    return (
        <aside className="docp">
            <header className="docp-head">
                <PersonPhoto
                    src={doctor.photo_url}
                    name={doctor.doctor_name}
                    className="docp-face"
                />

                <div className="docp-who">
                    <b>{doctor.doctor_name}</b>
                    <small>{doctor.specialisation ?? 'General'}</small>
                    <span className={`docp-state${doctor.is_active ? '' : ' is-off'}`}>
                        {doctor.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>

                <button
                    type="button"
                    className="docp-close"
                    onClick={onClose}
                    aria-label="Close this doctor"
                >
                    <i className="ti ti-x" />
                </button>
            </header>

            <nav className="docp-tabs" role="tablist">
                {(
                    [
                        ['schedule', 'Schedule'],
                        ['exceptions', `Exceptions${upcoming.length ? ` (${upcoming.length})` : ''}`],
                        ['details', 'Details'],
                    ] as [Tab, string][]
                ).map(([key, label]) => (
                    <button
                        type="button"
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        className={`docp-tab${tab === key ? ' is-on' : ''}`}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </nav>

            {/*
                The tabs stay put; what they show scrolls.

                The panel is pinned to the grid's height so the two columns end
                together, and a doctor with a full week plus a list of changes
                is taller than that — so the overflow goes here rather than
                pushing the card past the thing it sits beside.
            */}
            <div className="docp-body">
            {isLoading ? (
                <LoadingBlock label="Loading…" />
            ) : tab === 'schedule' ? (
                <>
                    <div className="docp-section">
                        <p className="docp-title">
                            Weekly schedule
                            <Link to={`/doctors/${doctor.doctor_id}/edit`}>
                                <i className="ti ti-pencil" aria-hidden="true" />
                                Edit
                            </Link>
                        </p>

                        {/*
                            A list, not a table.
                            Three columns forced the session name into a strip
                            too narrow to hold it, so "Morning OPD" wrapped
                            onto two lines on every row. The name belongs with
                            the time it names, under it.
                        */}
                        <ul className="docp-days">
                            {week.map((day) => (
                                <li
                                    key={day.label}
                                    className={day.sittings.length === 0 ? 'is-off' : undefined}
                                >
                                    <span className="docp-dayname">{day.label}</span>

                                    {day.sittings.length === 0 ? (
                                        <span className="docp-quiet">Not available</span>
                                    ) : (
                                        <span className="docp-slots">
                                            {/*
                                                Each sitting carries its own
                                                name. Reading the first one
                                                against every time said a
                                                doctor's evening follow-up
                                                clinic was another morning OPD.
                                            */}
                                            {day.sittings.map((sitting: DoctorSchedule) => (
                                                <span className="docp-slot" key={sitting.id}>
                                                    <b>
                                                        {clock(sitting.starts_at)} –{' '}
                                                        {clock(sitting.ends_at)}
                                                    </b>
                                                    {sitting.name && <em>{sitting.name}</em>}
                                                </span>
                                            ))}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>

                    {upcoming.length > 0 && (
                        <div className="docp-section">
                            <p className="docp-title">
                                Upcoming changes
                                <button
                                    type="button"
                                    className="docp-viewall"
                                    onClick={() => setTab('exceptions')}
                                >
                                    View all
                                </button>
                            </p>

                            <ul className="docp-changes">
                                {upcoming.slice(0, 4).map((change) => (
                                    <li key={change.id}>
                                        <i className="ti ti-calendar-event" aria-hidden="true" />

                                        <span>
                                            <b>{longDate(change.date)}</b>

                                            <em
                                                className={
                                                    change.type === 'unavailable'
                                                        ? 'is-off'
                                                        : 'is-moved'
                                                }
                                            >
                                                {change.type === 'unavailable'
                                                    ? 'Unavailable'
                                                    : change.type === 'changed_hours'
                                                      ? 'Changed'
                                                      : 'Extra'}
                                            </em>

                                            {change.starts_at && change.ends_at && (
                                                <small>
                                                    {clock(change.starts_at)} –{' '}
                                                    {clock(change.ends_at)}
                                                </small>
                                            )}

                                            {change.reason && (
                                                <small>Reason: {change.reason}</small>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            ) : tab === 'exceptions' ? (
                <div className="docp-section">
                    {upcoming.length === 0 ? (
                        <p className="docp-quiet">Nothing changed for this doctor from today on.</p>
                    ) : (
                        <ul className="docp-changes">
                            {upcoming.map((change) => (
                                <li key={change.id}>
                                    <i className="ti ti-calendar-event" aria-hidden="true" />

                                    <span>
                                        <b>{longDate(change.date)}</b>
                                        <em
                                            className={
                                                change.type === 'unavailable' ? 'is-off' : 'is-moved'
                                            }
                                        >
                                            {change.type === 'unavailable'
                                                ? 'Unavailable'
                                                : change.type === 'changed_hours'
                                                  ? 'Changed'
                                                  : 'Extra'}
                                        </em>
                                        {change.reason && <small>{change.reason}</small>}
                                        {change.location_name && (
                                            <small>{change.location_name}</small>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : (
                <div className="docp-section">
                    <dl className="docp-facts">
                        {[
                            ['Department', full?.specialisation],
                            ['Qualifications', full?.qualifications?.join(', ')],
                            ['Registration', full?.registration_no],
                            ['Phone', full?.phone],
                            ['Email', full?.email],
                            ['Branches', full?.locations?.join(', ')],
                        ].map(([label, value]) => (
                            <div key={label as string}>
                                <dt>{label}</dt>
                                <dd>{value || <span className="docp-quiet">Not recorded</span>}</dd>
                            </div>
                        ))}
                    </dl>

                    <Link className="docp-open" to={`/doctors/${doctor.doctor_id}/edit`}>
                        Open the full record
                        <i className="ti ti-arrow-right" aria-hidden="true" />
                    </Link>
                </div>
            )}
            </div>
        </aside>
    );
}
