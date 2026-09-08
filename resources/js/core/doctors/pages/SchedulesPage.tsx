import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { Card } from '@/shared/components/ui/Card';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { locationsHooks } from '@/core/locations/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useRemoveScheduleException,
    useSaveScheduleException,
    useScheduleExceptions,
} from '@/core/availability/api';
import { doctorsHooks, useDoctorSchedules, useSaveDoctorSchedules } from '../api';
import type { DoctorScheduleInput } from '../types';
import type { ExceptionType } from '@/core/availability/types';

/** Monday 0 … Sunday 6 — the order the server stores and the week reads. */
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/** What a sitting is for. Free text on the server; a short list here. */
const KINDS = ['OPD', 'Follow-up', 'Procedure', 'Surgery', 'Consultation'];

/**
 * How long one patient gets.
 *
 * The window divided by this is how many slots the day offers, so it is the
 * one number on this screen that changes how much work a doctor is booked
 * into. The server takes anything from 1 to 240; these are the lengths clinics
 * actually use.
 */
const SLOTS = [5, 10, 15, 20, 30, 45, 60];

type Tab = 'weekly' | 'changes' | 'preview';

/** Recurring every week, or one date on its own. */
type Kind = 'weekly' | 'date';

function blank(weekday: number, locationId: number | ''): DoctorScheduleInput {
    return {
        location_id: locationId,
        name: 'OPD',
        weekday,
        starts_at: '09:00',
        ends_at: '13:00',
        slot_minutes: 15,
        max_walkins: null,
        is_active: true,
    };
}

/** "09:00" → "9:00 AM", for reading rather than editing. */
function spoken(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}:${String(minutes).padStart(2, '0')} ${
        hours < 12 ? 'AM' : 'PM'
    }`;
}

/** Today, as the server writes dates. */
function today(): string {
    const now = new Date();
    const month = `${now.getMonth() + 1}`.padStart(2, '0');

    return `${now.getFullYear()}-${month}-${`${now.getDate()}`.padStart(2, '0')}`;
}

/**
 * How many patients a window holds.
 *
 * The same arithmetic the server does, and the reason the screen can show a
 * count without asking: slots are derived from the window, never stored.
 */
function slotCount(starts: string, ends: string, minutes: number): number {
    const [sh, sm] = starts.split(':').map(Number);
    const [eh, em] = ends.split(':').map(Number);

    return Math.max(0, Math.floor((eh * 60 + em - (sh * 60 + sm)) / minutes));
}

/**
 * A doctor's week, set once and read everywhere.
 *
 * This is the source the OPD board, the queue and the availability grid all
 * derive from, which is why it gets a screen of its own rather than a section
 * of the doctor form: the form is about a person, and this is about a rota.
 *
 * These are **availability windows**. Setting 09:00–13:00 at 15 minutes does
 * not create sixteen appointments; it says how finely that window divides when
 * somebody asks what is free.
 *
 * The weekly pattern is saved whole, because whether a sitting overlaps depends
 * on every other sitting that doctor has that day — which no per-row save could
 * check. A one-off date is a different thing entirely and is written as an
 * exception, not as a pattern that happens to run once.
 */
export default function SchedulesPage() {
    const [params, setParams] = useSearchParams();

    const { data: doctors } = doctorsHooks.useList({ all: 1 });
    const { data: branches } = locationsHooks.useList({ all: 1 });
    const { activeBranch } = useTenantAuth();

    const doctorId = Number(params.get('doctor')) || doctors?.[0]?.id;

    const { data: saved, isLoading } = useDoctorSchedules(doctorId);
    const save = useSaveDoctorSchedules(doctorId);

    const [tab, setTab] = useState<Tab>('weekly');
    const [kind, setKind] = useState<Kind>('weekly');

    /*
     * The branch this week belongs to.
     *
     * Defaulting to the organization's first would mean a branch admin
     * silently gives their doctor hours at somebody else's site — invisible in
     * the queue they were setting up, with nothing on screen saying why.
     */
    const [branchId, setBranchId] = useState<number | ''>('');

    useEffect(() => {
        if (branchId === '' && (activeBranch || branches?.[0]?.id)) {
            setBranchId((activeBranch || branches?.[0]?.id) as number);
        }
    }, [activeBranch, branches, branchId]);

    const [rows, setRows] = useState<DoctorScheduleInput[]>([]);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    /* Server state is the starting point; edits live here until saved. */
    useEffect(() => {
        if (!saved) return;

        setRows(
            saved.map((row) => ({
                location_id: row.location_id,
                name: row.name,
                weekday: row.weekday,
                starts_at: row.starts_at,
                ends_at: row.ends_at,
                slot_minutes: row.slot_minutes,
                max_walkins: row.max_walkins,
                is_active: row.is_active,
                effective_from: row.effective_from ?? null,
            })),
        );
    }, [saved]);

    const doctor = (doctors ?? []).find((entry) => entry.id === doctorId);

    /*
     * Every sitting in a saved week shares one start date, so the control is
     * one control. Per-sitting dates are a real thing, and the day somebody
     * needs them is the day this grows a column — not before.
     */
    const effectiveFrom = rows.find((row) => row.effective_from)?.effective_from ?? '';

    /*
     * The table shows one branch; the payload carries every branch.
     *
     * The endpoint replaces a doctor's whole week, so submitting only what is
     * on screen would delete the sittings they hold at every other site the
     * moment somebody edited this one.
     */
    const mine = useMemo(
        () => rows.filter((row) => branchId === '' || row.location_id === branchId),
        [rows, branchId],
    );

    const byDay = useMemo(
        () => DAYS.map((_, weekday) => mine.filter((row) => row.weekday === weekday)),
        [mine],
    );

    function edit(row: DoctorScheduleInput, patch: Partial<DoctorScheduleInput>) {
        setRows((was) => was.map((entry) => (entry === row ? { ...entry, ...patch } : entry)));
    }

    function submit() {
        setErrors({});

        save.mutate(rows, {
            onError: (error) => {
                const found = getValidationErrors(error);

                if (found) setErrors(found);
                else notify.error(resolveErrorMessage(error));
            },
        });
    }

    /*
     * The server numbers its complaints by position in the array it received,
     * so a message about `schedules.4.ends_at` has to find its way back to the
     * row the person is looking at.
     */
    function errorFor(row: DoctorScheduleInput, field: string): string | undefined {
        return errors[`schedules.${rows.indexOf(row)}.${field}`]?.[0];
    }

    /*
     * Take somebody else's week wholesale, to edit before saving.
     *
     * Two steps, because their week has to be fetched: naming them clears this
     * branch's rows and the arrival of their sittings fills it back in. Copied
     * into the form, never written — a copy that saved itself would overwrite
     * a week somebody was still looking at.
     */
    const [copying, setCopying] = useState<number | ''>('');
    const { data: theirs } = useDoctorSchedules(copying || undefined);

    function copyFrom(otherId: number) {
        if (!otherId) return;

        setRows((was) => was.filter((row) => row.location_id !== branchId));
        setCopying(otherId);
    }

    useEffect(() => {
        if (!copying || !theirs) return;

        setRows((was) => [
            ...was,
            ...theirs
                .filter((row) => row.location_id === branchId)
                .map((row) => ({
                    location_id: row.location_id,
                    name: row.name,
                    weekday: row.weekday,
                    starts_at: row.starts_at,
                    ends_at: row.ends_at,
                    slot_minutes: row.slot_minutes,
                    max_walkins: row.max_walkins,
                    is_active: row.is_active,
                    effective_from: null,
                })),
        ]);

        setCopying('');
        notify.success('Copied. Nothing is saved until you save the schedule.');
    }, [copying, theirs, branchId]);

    return (
        <>
            <PageHeader
                title="Create / edit doctor schedule"
                subtitle="Set the regular weekly timings for the doctor. You can also add date-specific changes — leave, different hours, an extra clinic."
                icon="ti ti-calendar-cog"
                tone="violet"
                crumbs={[{ label: 'Doctors', to: '/doctors' }, { label: 'Schedules' }]}
                actions={
                    <>
                        <Link className="sc-cancel" to="/doctors">
                            Cancel
                        </Link>

                        <button
                            type="button"
                            className="sc-save"
                            disabled={save.isPending || !doctorId}
                            onClick={submit}
                        >
                            {save.isPending ? 'Saving…' : 'Save schedule'}
                        </button>
                    </>
                }
            />

            {/* Who, where, from when, and of what sort. */}
            <div className="sc-strip">
                <div className="sc-who">
                    <PersonPhoto
                        src={doctor?.photo_url}
                        name={doctor?.name}
                        className="sc-face"
                    />

                    <label className="sc-pick">
                        <span>Doctor</span>
                        <SearchableSelect
                            value={String(doctorId ?? '')}
                            ariaLabel="Doctor"
                            onChange={(next) => {
                                const merged = new URLSearchParams(params);

                                merged.set('doctor', next);
                                setParams(merged, { replace: true });
                            }}
                            options={(doctors ?? []).map((entry) => ({
                                value: String(entry.id),
                                label: entry.name,
                                hint: entry.specialisation ?? undefined,
                            }))}
                        />
                    </label>
                </div>

                <label className="sc-pick">
                    <span>Branch</span>
                    <SearchableSelect
                        value={String(branchId)}
                        ariaLabel="Branch"
                        onChange={(next) => setBranchId(Number(next))}
                        options={(branches ?? []).map((entry) => ({
                            value: String(entry.id),
                            label: entry.name,
                        }))}
                    />
                </label>

                <div className="sc-pick">
                    <span>Effective from</span>
                    <DatePicker
                        value={effectiveFrom ?? ''}
                        placeholder="Straight away"
                        onChange={(next) =>
                            setRows((was) =>
                                was.map((row) => ({
                                    ...row,
                                    effective_from: next || null,
                                })),
                            )
                        }
                    />
                </div>

                {/*
                    Recurring, or one date.
                    A Tuesday that happens once is not a weekly pattern with an
                    asterisk — it is an exception, and writing it as a pattern
                    is how a one-off vaccination camp turns into every Tuesday
                    forever.
                */}
                <div className="sc-pick">
                    <span>Schedule type</span>

                    <div className="sc-kinds-pick" role="radiogroup" aria-label="Schedule type">
                        {(
                            [
                                ['weekly', 'Weekly recurring'],
                                ['date', 'Specific date only'],
                            ] as [Kind, string][]
                        ).map(([key, label]) => (
                            <label className="sc-radio" key={key}>
                                <input
                                    type="radio"
                                    name="sc-kind"
                                    checked={kind === key}
                                    onChange={() => {
                                        setKind(key);
                                        setTab(key === 'date' ? 'changes' : 'weekly');
                                    }}
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                </div>
            </div>

            <nav className="sc-tabs" role="tablist">
                {(
                    [
                        ['weekly', 'Weekly schedule'],
                        ['changes', 'Date-specific changes'],
                        ['preview', 'Preview'],
                    ] as [Tab, string][]
                ).map(([key, label]) => (
                    <button
                        type="button"
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        className={`sc-tab${tab === key ? ' is-on' : ''}`}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </nav>

            <div className="sc-split">
                {tab === 'changes' ? (
                    <DateChanges doctorId={doctorId} branchId={branchId} />
                ) : tab === 'preview' ? (
                    <Preview byDay={byDay} />
                ) : (
                    <Card
                        title={
                            <span className="opd-card-title">
                                <i className="ti ti-calendar-week" aria-hidden="true" />
                                Weekly schedule
                            </span>
                        }
                        actions={
                            <div className="sc-tools">
                                <div className="sc-copy">
                                    <SearchableSelect
                                        value=""
                                        ariaLabel="Copy from another doctor"
                                        placeholder="Copy from another doctor"
                                        onChange={(next) => copyFrom(Number(next))}
                                        options={(doctors ?? [])
                                            .filter((entry) => entry.id !== doctorId)
                                            .map((entry) => ({
                                                value: String(entry.id),
                                                label: entry.name,
                                                hint: entry.specialisation ?? undefined,
                                            }))}
                                    />
                                </div>

                                {mine.length > 0 && (
                                    <button
                                        type="button"
                                        className="sc-clear"
                                        onClick={() =>
                                            setRows((was) =>
                                                was.filter((row) => row.location_id !== branchId),
                                            )
                                        }
                                    >
                                        <i className="ti ti-trash" aria-hidden="true" />
                                        Clear all
                                    </button>
                                )}
                            </div>
                        }
                    >
                        <p className="sc-lede">
                            The regular weekly availability for this doctor. A day can hold more
                            than one sitting.
                        </p>

                        {isLoading ? (
                            <LoadingBlock label="Loading the week…" />
                        ) : (
                            <div className="sc-scroll tbl-cards-scroll">
                                <table className="sc-week tbl-cards">
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <th>Availability</th>
                                            <th>Time slots</th>
                                            <th>Session type</th>
                                            <th aria-label="Actions" />
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {DAYS.map((label, weekday) => {
                                            const sittings = byDay[weekday];

                                            return (
                                                <tr key={label}>
                                                    <th scope="row" className="sc-day">
                                                        {label}
                                                    </th>

                                                    <td className="sc-avail" data-label="Availability">
                                                        {/*
                                                            The tick is the day, not a row:
                                                            unticking takes the day's
                                                            sittings away, ticking gives it
                                                            one to start from.
                                                        */}
                                                        <label className="sc-tick">
                                                            <input
                                                                type="checkbox"
                                                                checked={sittings.length > 0}
                                                                onChange={(event) =>
                                                                    setRows((was) =>
                                                                        event.target.checked
                                                                            ? [
                                                                                  ...was,
                                                                                  blank(
                                                                                      weekday,
                                                                                      branchId,
                                                                                  ),
                                                                              ]
                                                                            : was.filter(
                                                                                  (row) =>
                                                                                      !(
                                                                                          row.weekday ===
                                                                                              weekday &&
                                                                                          row.location_id ===
                                                                                              branchId
                                                                                      ),
                                                                              ),
                                                                    )
                                                                }
                                                            />
                                                            {sittings.length > 0
                                                                ? 'Available'
                                                                : 'Not available'}
                                                        </label>
                                                    </td>

                                                    {sittings.length === 0 ? (
                                                        <td className="sc-off" colSpan={3} data-label="">
                                                            No slots. This doctor is not available
                                                            on {label}.
                                                        </td>
                                                    ) : (
                                                        <>
                                                            <td className="sc-times" data-label="Time slots">
                                                                {sittings.map((row, index) => (
                                                                    <div
                                                                        className="sc-span"
                                                                        key={index}
                                                                    >
                                                                        {/*
                                                                            From and to inside
                                                                            one border. Two
                                                                            bordered boxes and
                                                                            a dash read as two
                                                                            decisions; a range
                                                                            is one.
                                                                        */}
                                                                        <span
                                                                            className={`sc-range${errorFor(row, 'starts_at') || errorFor(row, 'ends_at') ? ' is-invalid' : ''}`}
                                                                        >
                                                                            <input
                                                                                type="time"
                                                                                value={
                                                                                    row.starts_at
                                                                                }
                                                                                aria-label={`Start on ${label}`}
                                                                                onChange={(event) =>
                                                                                    edit(row, {
                                                                                        starts_at:
                                                                                            event
                                                                                                .target
                                                                                                .value,
                                                                                    })
                                                                                }
                                                                            />

                                                                            <i aria-hidden="true">
                                                                                –
                                                                            </i>

                                                                            <input
                                                                                type="time"
                                                                                value={row.ends_at}
                                                                                aria-label={`End on ${label}`}
                                                                                onChange={(event) =>
                                                                                    edit(row, {
                                                                                        ends_at:
                                                                                            event
                                                                                                .target
                                                                                                .value,
                                                                                    })
                                                                                }
                                                                            />
                                                                        </span>

                                                                        {/*
                                                                            How finely the
                                                                            window divides —
                                                                            beside the window
                                                                            it describes, not
                                                                            in a column of its
                                                                            own.
                                                                        */}
                                                                        <SearchableSelect
                                                                            compact
                                                                            className="sc-every"
                                                                            value={String(
                                                                                row.slot_minutes,
                                                                            )}
                                                                            ariaLabel={`Slot length on ${label}`}
                                                                            onChange={(next) =>
                                                                                edit(row, {
                                                                                    slot_minutes:
                                                                                        Number(next),
                                                                                })
                                                                            }
                                                                            options={SLOTS.map(
                                                                                (min) => ({
                                                                                    value: String(min),
                                                                                    label: `${min} min`,
                                                                                }),
                                                                            )}
                                                                        />
                                                                    </div>
                                                                ))}

                                                                {/* One message under the day, not one per box. */}
                                                                {sittings
                                                                    .map((row) =>
                                                                        errorFor(row, 'ends_at'),
                                                                    )
                                                                    .filter(Boolean)
                                                                    .slice(0, 1)
                                                                    .map((message) => (
                                                                        <em
                                                                            className="sc-error"
                                                                            key={message}
                                                                        >
                                                                            {message}
                                                                        </em>
                                                                    ))}

                                                                <button
                                                                    type="button"
                                                                    className="sc-add"
                                                                    onClick={() =>
                                                                        setRows((was) => [
                                                                            ...was,
                                                                            blank(
                                                                                weekday,
                                                                                branchId,
                                                                            ),
                                                                        ])
                                                                    }
                                                                >
                                                                    <i
                                                                        className="ti ti-plus"
                                                                        aria-hidden="true"
                                                                    />
                                                                    Add another slot
                                                                </button>
                                                            </td>

                                                            <td className="sc-kinds" data-label="Session type">
                                                                {sittings.map((row, index) => (
                                                                    <SearchableSelect
                                                                        key={index}
                                                                        compact
                                                                        className="sc-type"
                                                                        value={row.name ?? 'OPD'}
                                                                        ariaLabel={`Session type on ${label}`}
                                                                        onChange={(next) =>
                                                                            edit(row, { name: next })
                                                                        }
                                                                        options={KINDS.map((entry) => ({
                                                                            value: entry,
                                                                            label: entry,
                                                                        }))}
                                                                    />
                                                                ))}
                                                            </td>

                                                            <td className="sc-acts" data-label="">
                                                                {sittings.map((row, index) => (
                                                                    <button
                                                                        type="button"
                                                                        key={index}
                                                                        className="sc-drop"
                                                                        aria-label={`Remove the ${spoken(row.starts_at)} sitting on ${label}`}
                                                                        onClick={() =>
                                                                            setRows((was) =>
                                                                                was.filter(
                                                                                    (entry) =>
                                                                                        entry !==
                                                                                        row,
                                                                                ),
                                                                            )
                                                                        }
                                                                    >
                                                                        <i
                                                                            className="ti ti-trash"
                                                                            aria-hidden="true"
                                                                        />
                                                                    </button>
                                                                ))}
                                                            </td>
                                                        </>
                                                    )}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                )}

                {/*
                    The same week, counted.
                    A table you are editing is hard to read as a whole, and
                    "did I leave Wednesday empty" is the question people
                    actually have while filling one in.
                */}
                <aside className="sc-side">
                    <Card
                        title={
                            <span className="opd-card-title">
                                <i className="ti ti-list-check" aria-hidden="true" />
                                Schedule summary
                            </span>
                        }
                    >
                        <p className="sc-lede">
                            Weekly overview of this doctor&rsquo;s availability.
                        </p>

                        <div className="sc-sum-box">
                            <table className="sc-sum">
                                <tbody>
                                    {DAYS.map((label, weekday) => {
                                        const sittings = byDay[weekday];

                                        return (
                                            <tr key={label}>
                                                <th scope="row">{label.slice(0, 3)}</th>

                                                <td>
                                                    {sittings.length === 0 ? (
                                                        <span className="sc-pill is-off">
                                                            Not available
                                                        </span>
                                                    ) : (
                                                        <span className="sc-pill is-on">
                                                            {sittings.length}{' '}
                                                            {sittings.length === 1
                                                                ? 'slot'
                                                                : 'slots'}
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="sc-sum-times">
                                                    {sittings.length === 0 ? (
                                                        <span className="sc-quiet">—</span>
                                                    ) : (
                                                        sittings.map((row, index) => (
                                                            <span key={index}>
                                                                {spoken(row.starts_at)} –{' '}
                                                                {spoken(row.ends_at)}
                                                                {row.name ? ` (${row.name})` : ''}
                                                            </span>
                                                        ))
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </Card>

                    <Card
                        title={
                            <span className="opd-card-title">
                                <i className="ti ti-bulb" aria-hidden="true" />
                                Next steps
                            </span>
                        }
                    >
                        <ul className="sc-next">
                            <li>
                                <i className="ti ti-square-check" aria-hidden="true" />
                                Review the weekly schedule
                            </li>
                            <li>
                                <i className="ti ti-square-check" aria-hidden="true" />
                                Add any date-specific changes — leave, different hours
                            </li>
                            <li>
                                <i className="ti ti-square-check" aria-hidden="true" />
                                Save the schedule
                            </li>
                        </ul>

                        <p className="sc-note">
                            <i className="ti ti-info-circle" aria-hidden="true" />
                            These are availability windows, not appointments. Slots are worked out
                            from them when somebody asks what is free.
                        </p>
                    </Card>
                </aside>
            </div>
        </>
    );
}

/**
 * What happens on one date that the weekly pattern does not say.
 *
 * Leave, a holiday, hours moved for a morning, an extra Sunday clinic. Kept
 * apart from the week deliberately: a Tuesday that happens once is not a
 * weekly pattern with an asterisk, and writing it as one is how a one-off
 * vaccination camp becomes every Tuesday forever.
 */
function DateChanges({ doctorId, branchId }: { doctorId?: number; branchId: number | '' }) {
    const { data: changes } = useScheduleExceptions(doctorId ? { doctor_id: doctorId } : {});

    const add = useSaveScheduleException();
    const remove = useRemoveScheduleException();

    const [type, setType] = useState<ExceptionType>('unavailable');
    const [date, setDate] = useState(today());
    const [startsAt, setStartsAt] = useState('09:00');
    const [endsAt, setEndsAt] = useState('13:00');
    const [minutes, setMinutes] = useState(15);
    const [reason, setReason] = useState('');

    function submit() {
        if (!doctorId) return;

        add.mutate(
            {
                doctor_id: doctorId,
                date,
                type,

                // Leave takes the day and needs no times; the other two are
                // windows and cannot be written without them.
                starts_at: type === 'unavailable' ? null : startsAt,
                ends_at: type === 'unavailable' ? null : endsAt,
                slot_minutes: type === 'unavailable' ? null : minutes,

                // Only an extra clinic carries a branch — it is not in the
                // weekly pattern, so nothing else could say where it is.
                location_id: type === 'extra_session' ? branchId || null : null,
                reason: reason || null,
            },
            {
                onSuccess: () => setReason(''),
                onError: (error) => notify.error(resolveErrorMessage(error)),
            },
        );
    }

    return (
        <Card
            title={
                <span className="opd-card-title">
                    <i className="ti ti-calendar-exclamation" aria-hidden="true" />
                    Date-specific changes
                </span>
            }
        >
            <p className="sc-lede">
                One date at a time — leave, moved hours, or a clinic that is not in the weekly
                pattern.
            </p>

            <div className="sc-ex-form">
                <div className="sc-pick">
                    <span>What</span>
                    <SearchableSelect
                        value={type}
                        ariaLabel="What kind of change"
                        onChange={(next) => setType(next as ExceptionType)}
                        options={[
                            { value: 'unavailable', label: 'Unavailable — leave or a holiday' },
                            { value: 'changed_hours', label: 'Different hours that day' },
                            { value: 'extra_session', label: 'An extra clinic' },
                        ]}
                    />
                </div>

                <div className="sc-pick">
                    <span>Date</span>
                    <DatePicker value={date} onChange={setDate} />
                </div>

                {type !== 'unavailable' && (
                    <>
                        <label className="sc-pick sc-pick-thin">
                            <span>From</span>
                            <input
                                type="time"
                                className="form-control"
                                value={startsAt}
                                onChange={(event) => setStartsAt(event.target.value)}
                            />
                        </label>

                        <label className="sc-pick sc-pick-thin">
                            <span>To</span>
                            <input
                                type="time"
                                className="form-control"
                                value={endsAt}
                                onChange={(event) => setEndsAt(event.target.value)}
                            />
                        </label>

                        <div className="sc-pick sc-pick-thin">
                            <span>Every</span>
                            <SearchableSelect
                                value={String(minutes)}
                                ariaLabel="Slot length"
                                onChange={(next) => setMinutes(Number(next))}
                                options={SLOTS.map((min) => ({
                                    value: String(min),
                                    label: `${min} min`,
                                }))}
                            />
                        </div>
                    </>
                )}

                <label className="sc-pick sc-pick-wide">
                    <span>Reason</span>
                    <input
                        type="text"
                        className="form-control"
                        placeholder="Personal leave, conference, camp…"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                    />
                </label>

                {/*
                    Saved on its own, not with the week.
                    An exception is written the moment it is known — somebody
                    recording leave has no reason to also re-save a timetable
                    they did not touch.
                */}
                <button
                    type="button"
                    className="sc-save"
                    disabled={add.isPending || !doctorId}
                    onClick={submit}
                >
                    {add.isPending ? 'Adding…' : 'Add change'}
                </button>
            </div>

            {(changes ?? []).length === 0 ? (
                <p className="sc-quiet sc-empty">Nothing changed for this doctor from today on.</p>
            ) : (
                <ul className="sc-ex">
                    {(changes ?? []).map((change) => (
                        <li key={change.id}>
                            <span
                                className={`sc-ex-tag is-${
                                    change.type === 'unavailable'
                                        ? 'off'
                                        : change.type === 'changed_hours'
                                          ? 'moved'
                                          : 'extra'
                                }`}
                            >
                                {change.type === 'unavailable'
                                    ? 'Unavailable'
                                    : change.type === 'changed_hours'
                                      ? 'Changed'
                                      : 'Extra'}
                            </span>

                            <span className="sc-ex-when">
                                <b>{change.date}</b>
                                {change.starts_at && change.ends_at && (
                                    <small>
                                        {spoken(change.starts_at)} – {spoken(change.ends_at)}
                                    </small>
                                )}
                            </span>

                            <span className="sc-ex-why">{change.reason ?? '—'}</span>

                            <button
                                type="button"
                                className="sc-drop"
                                aria-label={`Remove the change on ${change.date}`}
                                onClick={() => remove.mutate(change.id)}
                            >
                                <i className="ti ti-trash" aria-hidden="true" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/**
 * The week as it will read once saved.
 *
 * The table is a set of controls; this is the answer they add up to. Worth its
 * own tab because "how many patients have I just committed this doctor to" is
 * not a question a form full of time inputs can be read for.
 */
function Preview({ byDay }: { byDay: DoctorScheduleInput[][] }) {
    const total = byDay
        .flat()
        .reduce((sum, row) => sum + slotCount(row.starts_at, row.ends_at, row.slot_minutes), 0);

    return (
        <Card
            title={
                <span className="opd-card-title">
                    <i className="ti ti-eye" aria-hidden="true" />
                    Preview
                </span>
            }
        >
            <p className="sc-lede">As saved, this week offers {total} slots.</p>

            <ul className="sc-prev">
                {DAYS.map((label, weekday) => (
                    <li key={label} className={byDay[weekday].length === 0 ? 'is-off' : undefined}>
                        <span className="sc-prev-day">{label}</span>

                        {byDay[weekday].length === 0 ? (
                            <span className="sc-quiet">Not available</span>
                        ) : (
                            <span className="sc-prev-list">
                                {byDay[weekday].map((row, index) => (
                                    <span className="sc-prev-one" key={index}>
                                        <b>
                                            {spoken(row.starts_at)} – {spoken(row.ends_at)}
                                        </b>
                                        <small>
                                            {row.name ?? 'OPD'} ·{' '}
                                            {slotCount(
                                                row.starts_at,
                                                row.ends_at,
                                                row.slot_minutes,
                                            )}{' '}
                                            slots of {row.slot_minutes} min
                                        </small>
                                    </span>
                                ))}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}
