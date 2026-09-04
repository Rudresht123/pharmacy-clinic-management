import { useEffect, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { locationsHooks } from '@/core/locations/api';
import { useDoctorSchedules, useSaveDoctorSchedules } from '../api';
import type { DoctorScheduleInput } from '../types';

/** Monday 0 … Sunday 6 — the order the server stores and the week reads. */
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function blankSitting(weekday: number, locationId: number | ''): DoctorScheduleInput {
    return {
        location_id: locationId,
        name: null,
        weekday,
        starts_at: '10:00',
        ends_at: '13:00',
        slot_minutes: 15,
        max_walkins: null,
        is_active: true,
    };
}

/**
 * When and where a doctor sits, a week at a time.
 *
 * A form, not a table. A doctor who sits 10–1 in Gurgaon and 5–8 in Delhi on
 * the same Monday needs two entries under Monday, and "one row per schedule"
 * buries that — the week is the thing being edited, so the week is what the
 * screen shows.
 *
 * These are **availability windows**. Setting 10:00–13:00 at 15 minutes does
 * not create twelve appointments; it says how finely that window divides when
 * somebody asks what is free.
 *
 * Saved whole, because whether a sitting overlaps depends on every other
 * sitting that day — which no per-row save could check.
 */
export function ScheduleEditor({ doctorId }: { doctorId: number }) {
    const { data: saved, isLoading } = useDoctorSchedules(doctorId);
    const { data: branches } = locationsHooks.useList({ all: 1 });
    const save = useSaveDoctorSchedules(doctorId);

    const [rows, setRows] = useState<DoctorScheduleInput[]>([]);
    const [errors, setErrors] = useState<Record<number, string>>({});
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (!saved) {
            return;
        }

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
            })),
        );

        setErrors({});
        setDirty(false);
    }, [saved]);

    function patch(index: number, changes: Partial<DoctorScheduleInput>) {
        setDirty(true);
        setRows((current) =>
            current.map((row, position) => (position === index ? { ...row, ...changes } : row)),
        );
    }

    function addTo(weekday: number) {
        setDirty(true);
        setRows((current) => [...current, blankSitting(weekday, branches?.[0]?.id ?? '')]);
    }

    function removeAt(index: number) {
        setDirty(true);
        setRows((current) => current.filter((_, position) => position !== index));
        setErrors({});
    }

    async function onSave() {
        setErrors({});

        try {
            await save.mutateAsync(rows);
            setDirty(false);
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                /*
                 * The server indexes errors by position in the submitted
                 * array; the screen lays the same array out under seven days.
                 * Mapped back by index so an overlap lands on the sitting it
                 * belongs to rather than at the top of the page.
                 */
                const byRow: Record<number, string> = {};

                for (const [field, messages] of Object.entries(validation)) {
                    const index = Number(field.split('.')[1]);

                    if (!Number.isNaN(index)) {
                        byRow[index] = messages[0];
                    }
                }

                setErrors(byRow);
                notify.error('Some sittings need attention.');

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Loading timings…" />;
    }

    const hasBranches = (branches ?? []).length > 0;

    return (
        <Card
            className="sched"
            title="Timings"
            icon="ti ti-calendar-time"
            description="Where this doctor sits, and when. A sitting is an availability window — the slot length says how finely it divides, not how many appointments exist."
            actions={
                <Button
                    icon="ti ti-device-floppy"
                    loading={save.isPending}
                    disabled={!dirty || !hasBranches}
                    onClick={onSave}
                >
                    {dirty ? 'Save timings' : 'Saved'}
                </Button>
            }
        >
            {!hasBranches && (
                <p className="sched-hint">
                    <i className="ti ti-info-circle" /> Add a branch first — a sitting has to happen
                    somewhere.
                </p>
            )}

            <div className="sched-week">
                {DAYS.map((day, weekday) => {
                    // Indices into the flat array, so an error maps straight back.
                    const entries = rows
                        .map((row, index) => ({ row, index }))
                        .filter((entry) => entry.row.weekday === weekday);

                    return (
                        <section className="sched-day" key={day}>
                            <div className="sched-day-head">
                                <h6>{day}</h6>

                                <button
                                    type="button"
                                    className="sched-add"
                                    disabled={!hasBranches}
                                    onClick={() => addTo(weekday)}
                                >
                                    <i className="ti ti-plus" />
                                    Add sitting
                                </button>
                            </div>

                            {entries.length === 0 ? (
                                <p className="sched-off">Not sitting</p>
                            ) : (
                                <div className="sched-list">
                                    {entries.map(({ row, index }) => (
                                        <div
                                            className={`sched-row${
                                                errors[index] ? ' has-error' : ''
                                            }${row.is_active ? '' : ' is-off'}`}
                                            key={index}
                                        >
                                            <label className="sched-field sched-branch">
                                                <span>Branch</span>
                                                <select
                                                    className="form-select form-select-sm"
                                                    value={row.location_id}
                                                    onChange={(event) =>
                                                        patch(index, {
                                                            location_id: Number(event.target.value),
                                                        })
                                                    }
                                                >
                                                    {(branches ?? []).map((branch) => (
                                                        <option key={branch.id} value={branch.id}>
                                                            {branch.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>

                                            <label className="sched-field">
                                                <span>From</span>
                                                <input
                                                    type="time"
                                                    className="form-control form-control-sm"
                                                    value={row.starts_at}
                                                    onChange={(event) =>
                                                        patch(index, {
                                                            starts_at: event.target.value,
                                                        })
                                                    }
                                                />
                                            </label>

                                            <label className="sched-field">
                                                <span>To</span>
                                                <input
                                                    type="time"
                                                    className="form-control form-control-sm"
                                                    value={row.ends_at}
                                                    onChange={(event) =>
                                                        patch(index, {
                                                            ends_at: event.target.value,
                                                        })
                                                    }
                                                />
                                            </label>

                                            <label className="sched-field sched-slot">
                                                <span>Every</span>
                                                <div className="sched-slot-input">
                                                    <input
                                                        type="number"
                                                        className="form-control form-control-sm"
                                                        min={1}
                                                        max={240}
                                                        value={row.slot_minutes}
                                                        onChange={(event) =>
                                                            patch(index, {
                                                                slot_minutes: Number(
                                                                    event.target.value,
                                                                ),
                                                            })
                                                        }
                                                    />
                                                    <em>min</em>
                                                </div>
                                            </label>

                                            <label className="sched-field">
                                                <span>Walk-ins</span>
                                                <input
                                                    type="number"
                                                    className="form-control form-control-sm"
                                                    min={0}
                                                    max={999}
                                                    placeholder="No cap"
                                                    value={row.max_walkins ?? ''}
                                                    onChange={(event) =>
                                                        patch(index, {
                                                            max_walkins:
                                                                event.target.value === ''
                                                                    ? null
                                                                    : Number(event.target.value),
                                                        })
                                                    }
                                                />
                                            </label>

                                            <label className="sched-field sched-name">
                                                <span>Label</span>
                                                <input
                                                    type="text"
                                                    className="form-control form-control-sm"
                                                    placeholder="Morning OPD"
                                                    maxLength={60}
                                                    value={row.name ?? ''}
                                                    onChange={(event) =>
                                                        patch(index, {
                                                            name: event.target.value || null,
                                                        })
                                                    }
                                                />
                                            </label>

                                            <div className="sched-tools">
                                                <button
                                                    type="button"
                                                    className={`sched-toggle${
                                                        row.is_active ? ' is-on' : ''
                                                    }`}
                                                    title={
                                                        row.is_active
                                                            ? 'Pause this sitting'
                                                            : 'Resume this sitting'
                                                    }
                                                    aria-pressed={row.is_active}
                                                    onClick={() =>
                                                        patch(index, {
                                                            is_active: !row.is_active,
                                                        })
                                                    }
                                                >
                                                    <i
                                                        className={
                                                            row.is_active
                                                                ? 'ti ti-player-pause'
                                                                : 'ti ti-player-play'
                                                        }
                                                    />
                                                </button>

                                                <button
                                                    type="button"
                                                    className="sched-remove"
                                                    title="Remove this sitting"
                                                    onClick={() => removeAt(index)}
                                                >
                                                    <i className="ti ti-trash" />
                                                </button>
                                            </div>

                                            {errors[index] && (
                                                <p className="sched-error">{errors[index]}</p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    );
                })}
            </div>
        </Card>
    );
}
