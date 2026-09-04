import { useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { formatDate } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { doctorsHooks } from '@/core/doctors/api';
import { useDoctorSchedules } from '@/core/doctors/api';
import {
    useRemoveScheduleException,
    useSaveScheduleException,
    useScheduleExceptions,
} from '../api';
import type { ExceptionType, ScheduleException, ScheduleExceptionInput } from '../types';

interface Branch {
    id: number;
    name: string;
}

/** How each kind presents itself. They are genuinely different things. */
const TYPES: Record<ExceptionType, { label: string; icon: string; tone: string; hint: string }> = {
    unavailable: {
        label: 'Away',
        icon: 'ti ti-calendar-off',
        tone: 'rose',
        hint: 'Leave, a holiday, or one sitting cancelled.',
    },
    changed_hours: {
        label: 'Different hours',
        icon: 'ti ti-clock-edit',
        tone: 'amber',
        hint: 'A sitting that runs at other times, on this date only.',
    },
    extra_session: {
        label: 'Extra session',
        icon: 'ti ti-calendar-plus',
        tone: 'emerald',
        hint: 'A clinic that is not in the weekly pattern at all.',
    },
};

function blank(): ScheduleExceptionInput {
    return {
        doctor_id: '',
        date: '',
        type: 'unavailable',
        doctor_schedule_id: null,
        location_id: null,
        starts_at: '10:00',
        ends_at: '13:00',
        slot_minutes: 15,
        reason: '',
    };
}

/**
 * Everything that departs from the weekly pattern.
 *
 * The weekly timings say what usually happens; these are the dates it does
 * not. Kept on their own tab rather than buried in a doctor's screen,
 * because the question is almost always "who is off next week", across
 * everybody.
 */
export function ExceptionsPanel({ branches }: { branches: Branch[] }) {
    const confirm = useConfirm();
    const { user } = useTenantAuth();
    const canManage = user?.role === 'owner';

    const { data: rows, isLoading } = useScheduleExceptions();
    const { data: doctors } = doctorsHooks.useList({ all: 1 });

    const save = useSaveScheduleException();
    const remove = useRemoveScheduleException();

    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<ScheduleExceptionInput>(blank());
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Only needed to name a sitting, which only two of the three types do.
    const { data: schedules } = useDoctorSchedules(
        draft.doctor_id === '' ? undefined : Number(draft.doctor_id),
    );

    function patch(changes: Partial<ScheduleExceptionInput>) {
        setDraft((current) => ({ ...current, ...changes }));
    }

    async function onSave() {
        setErrors({});

        try {
            await save.mutateAsync({
                ...draft,
                // The server refuses fields a type has no use for, so they
                // are dropped here rather than sent and argued about.
                doctor_schedule_id:
                    draft.type === 'extra_session' ? null : draft.doctor_schedule_id || null,
                location_id: draft.type === 'extra_session' ? draft.location_id : null,
                starts_at: draft.type === 'unavailable' ? null : draft.starts_at,
                ends_at: draft.type === 'unavailable' ? null : draft.ends_at,
                slot_minutes: draft.type === 'extra_session' ? draft.slot_minutes : null,
            });

            setOpen(false);
            setDraft(blank());
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(validation).map(([key, messages]) => [key, messages[0]]),
                    ),
                );

                notify.error('Some details need attention.');

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    async function onRemove(row: ScheduleException) {
        const confirmed = await confirm({
            title: 'Remove this change?',
            message: `${row.doctor_name} goes back to their usual timings on ${formatDate(row.date)}.`,
            confirmLabel: 'Remove',
            danger: true,
        });

        if (confirmed) {
            remove.mutate(row.id);
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Loading changes…" />;
    }

    return (
        <>
            <Card
                title="Leave & changes"
                icon="ti ti-calendar-off"
                description="Dates that differ from the weekly timings. Anything listed here is already taken out of the day above."
                actions={
                    canManage ? (
                        <Button
                            icon="ti ti-plus"
                            onClick={() => {
                                setDraft(blank());
                                setErrors({});
                                setOpen(true);
                            }}
                        >
                            Record a change
                        </Button>
                    ) : undefined
                }
            >
                {(rows ?? []).length === 0 ? (
                    <div className="org-pending">
                        <i className="ti ti-calendar-check" />
                        <h6>Nothing coming up</h6>
                        <p>Every doctor is keeping to their usual timings.</p>
                    </div>
                ) : (
                    <ul className="av-exceptions">
                        {(rows ?? []).map((row) => {
                            const kind = TYPES[row.type];

                            return (
                                <li key={row.id}>
                                    <span
                                        className={`av-ex-dot is-${kind.tone}`}
                                        aria-hidden="true"
                                    >
                                        <i className={kind.icon} />
                                    </span>

                                    <div className="av-ex-body">
                                        <p>
                                            <b>{row.doctor_name}</b> · {kind.label}
                                            {row.whole_day && (
                                                <span className="av-ex-tag">whole day</span>
                                            )}
                                        </p>

                                        <span className="av-ex-when">
                                            {formatDate(row.date)}
                                            {row.starts_at && ` · ${row.starts_at}–${row.ends_at}`}
                                            {row.location_name && ` · ${row.location_name}`}
                                            {row.reason && ` · ${row.reason}`}
                                        </span>
                                    </div>

                                    {canManage && (
                                        <button
                                            type="button"
                                            className="av-ex-remove"
                                            title="Remove this change"
                                            onClick={() => onRemove(row)}
                                        >
                                            <i className="ti ti-trash" />
                                        </button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="Record a change"
                subtitle="One date that does not follow the weekly timings."
                size="md"
                icon={<i className="ti ti-calendar-off" />}
                footer={
                    <>
                        <Button variant="light" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button loading={save.isPending} onClick={onSave} icon="ti ti-check">
                            Save
                        </Button>
                    </>
                }
            >
                <div className="av-form">
                    <label className="av-field">
                        <span>Doctor</span>
                        <select
                            className="form-select"
                            value={draft.doctor_id}
                            onChange={(event) =>
                                patch({
                                    doctor_id: Number(event.target.value),
                                    doctor_schedule_id: null,
                                })
                            }
                        >
                            <option value="">Choose a doctor…</option>
                            {(doctors ?? []).map((doctor) => (
                                <option key={doctor.id} value={doctor.id}>
                                    {doctor.name}
                                </option>
                            ))}
                        </select>
                        {errors.doctor_id && <em>{errors.doctor_id}</em>}
                    </label>

                    <label className="av-field">
                        <span>Date</span>
                        <DatePicker
                            id="exception-date"
                            label="the date"
                            value={draft.date}
                            onChange={(value: string) => patch({ date: value })}
                        />
                        {errors.date && <em>{errors.date}</em>}
                    </label>

                    <div className="av-field av-field--wide">
                        <span>What is happening</span>

                        <div className="av-types">
                            {(Object.keys(TYPES) as ExceptionType[]).map((type) => (
                                <button
                                    type="button"
                                    key={type}
                                    className={`av-type${draft.type === type ? ' is-on' : ''}`}
                                    onClick={() => patch({ type })}
                                >
                                    <i className={TYPES[type].icon} />
                                    <b>{TYPES[type].label}</b>
                                    <small>{TYPES[type].hint}</small>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Away can mean the whole day, or one sitting. */}
                    {draft.type !== 'extra_session' && (
                        <label className="av-field av-field--wide">
                            <span>
                                {draft.type === 'unavailable'
                                    ? 'Which sitting (leave blank for the whole day)'
                                    : 'Which sitting'}
                            </span>
                            <select
                                className="form-select"
                                value={draft.doctor_schedule_id ?? ''}
                                onChange={(event) =>
                                    patch({
                                        doctor_schedule_id: event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    })
                                }
                            >
                                <option value="">
                                    {draft.type === 'unavailable' ? 'The whole day' : 'Choose…'}
                                </option>
                                {(schedules ?? []).map((schedule) => (
                                    <option key={schedule.id} value={schedule.id}>
                                        {schedule.weekday_label} · {schedule.starts_at}–
                                        {schedule.ends_at} · {schedule.location_name}
                                    </option>
                                ))}
                            </select>
                            {errors.doctor_schedule_id && <em>{errors.doctor_schedule_id}</em>}
                        </label>
                    )}

                    {draft.type === 'extra_session' && (
                        <label className="av-field av-field--wide">
                            <span>Branch</span>
                            <select
                                className="form-select"
                                value={draft.location_id ?? ''}
                                onChange={(event) =>
                                    patch({ location_id: Number(event.target.value) })
                                }
                            >
                                <option value="">Choose a branch…</option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                            {errors.location_id && <em>{errors.location_id}</em>}
                        </label>
                    )}

                    {draft.type !== 'unavailable' && (
                        <>
                            <label className="av-field">
                                <span>From</span>
                                <input
                                    type="time"
                                    className="form-control"
                                    value={draft.starts_at ?? ''}
                                    onChange={(event) => patch({ starts_at: event.target.value })}
                                />
                            </label>

                            <label className="av-field">
                                <span>To</span>
                                <input
                                    type="time"
                                    className="form-control"
                                    value={draft.ends_at ?? ''}
                                    onChange={(event) => patch({ ends_at: event.target.value })}
                                />
                                {errors.ends_at && <em>{errors.ends_at}</em>}
                            </label>
                        </>
                    )}

                    <label className="av-field av-field--wide">
                        <span>Reason</span>
                        <input
                            type="text"
                            className="form-control"
                            placeholder="On leave, public holiday, vaccination camp…"
                            maxLength={191}
                            value={draft.reason ?? ''}
                            onChange={(event) => patch({ reason: event.target.value })}
                        />
                    </label>
                </div>
            </Modal>
        </>
    );
}
