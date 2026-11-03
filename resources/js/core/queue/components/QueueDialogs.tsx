import { useEffect, useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { usePauseQueue, useReassign, useSetPriority } from '../api';
import type { QueuePriority, QueueRow } from '../types';

const PRIORITIES: { value: QueuePriority; label: string; hint: string }[] = [
    { value: 'normal', label: 'Normal', hint: 'In turn, by arrival.' },
    { value: 'urgent', label: 'Urgent', hint: 'Counted as arriving half an hour earlier.' },
    { value: 'emergency', label: 'Emergency', hint: 'Next, ahead of everybody. Overrides a pause.' },
];

/** Moving somebody up or down the line, and saying why. The reason is not optional. */
export function PriorityDialog({ row, onClose }: { row: QueueRow | null; onClose: () => void }) {
    const save = useSetPriority();
    const [priority, setPriority] = useState<QueuePriority>('normal');
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (row) {
            setPriority(row.priority);
            setReason('');
        }
    }, [row]);

    const changed = row !== null && priority !== row.priority;

    return (
        <Modal
            open={row !== null}
            onClose={onClose}
            size="sm"
            busy={save.isPending}
            icon={<i className="ti ti-flag" aria-hidden="true" />}
            title={`Priority for ${row?.token ?? ''}`}
            subtitle={row?.customer_name ?? undefined}
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!changed || reason.trim() === '' || save.isPending}
                        onClick={() =>
                            row &&
                            save.mutate({ id: row.id, priority, reason }, { onSuccess: onClose })
                        }
                    >
                        Save
                    </Button>
                </>
            }
        >
            <div className="sq-choices" role="radiogroup" aria-label="Priority">
                {PRIORITIES.map((option) => (
                    <button
                        type="button"
                        key={option.value}
                        role="radio"
                        aria-checked={priority === option.value}
                        className={`sq-choice${priority === option.value ? ' is-on' : ''}`}
                        onClick={() => setPriority(option.value)}
                    >
                        <b>{option.label}</b>
                        <span>{option.hint}</span>
                    </button>
                ))}
            </div>

            <label className="form-label mt-3" htmlFor="sq-priority-reason">
                Reason
            </label>
            <textarea
                id="sq-priority-reason"
                className="form-control"
                rows={2}
                maxLength={191}
                value={reason}
                placeholder="Why this patient's place is changing"
                onChange={(event) => setReason(event.target.value)}
            />
        </Modal>
    );
}

/** Another doctor at this branch. A new token in their run; their wait goes with them. */
export function ReassignDialog({
    row,
    fromDoctorId,
    doctors,
    onClose,
}: {
    row: QueueRow | null;
    fromDoctorId: number;
    doctors: { id: number; name: string | null; department: string | null }[];
    onClose: () => void;
}) {
    const save = useReassign();
    const [doctorId, setDoctorId] = useState('');
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (row) {
            setDoctorId('');
            setReason('');
        }
    }, [row]);

    return (
        <Modal
            open={row !== null}
            onClose={onClose}
            size="sm"
            busy={save.isPending}
            icon={<i className="ti ti-transfer" aria-hidden="true" />}
            title={`Move ${row?.token ?? ''} to another doctor`}
            subtitle={row?.customer_name ?? undefined}
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button
                        disabled={doctorId === '' || reason.trim() === '' || save.isPending}
                        onClick={() =>
                            row &&
                            save.mutate({ id: row.id, doctorId: Number(doctorId), reason }, { onSuccess: onClose })
                        }
                    >
                        Move patient
                    </Button>
                </>
            }
        >
            <label className="form-label">Doctor</label>
            <SearchableSelect
                value={doctorId}
                onChange={setDoctorId}
                ariaLabel="Doctor"
                placeholder="Choose a doctor"
                options={doctors
                    .filter((doctor) => doctor.id !== fromDoctorId)
                    .map((doctor) => ({
                        value: String(doctor.id),
                        label: doctor.name ?? 'Unnamed',
                        hint: doctor.department ?? undefined,
                    }))}
            />

            <label className="form-label mt-3" htmlFor="sq-reassign-reason">
                Reason
            </label>
            <textarea
                id="sq-reassign-reason"
                className="form-control"
                rows={2}
                maxLength={191}
                value={reason}
                placeholder="Why they are moving"
                onChange={(event) => setReason(event.target.value)}
            />

            <p className="sq-note">
                They take the next token in the new doctor's run and keep their place by arrival time.
                {row?.slot_at ? ` Their ${row.slot_at} booking does not carry over.` : ''}
            </p>
        </Modal>
    );
}

const PAUSE_LENGTHS: { label: string; minutes: number | null }[] = [
    { label: '15 min', minutes: 15 },
    { label: '30 min', minutes: 30 },
    { label: '1 hour', minutes: 60 },
    { label: 'Until resumed', minutes: null },
];

/** Stop calling. Everybody keeps their place. */
export function PauseDialog({
    open,
    doctorId,
    locationId,
    onClose,
}: {
    open: boolean;
    doctorId: number;
    locationId: number;
    onClose: () => void;
}) {
    const pause = usePauseQueue();
    const [reason, setReason] = useState('');
    const [minutes, setMinutes] = useState<number | null>(30);

    useEffect(() => {
        if (open) {
            setReason('');
            setMinutes(30);
        }
    }, [open]);

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="sm"
            busy={pause.isPending}
            icon={<i className="ti ti-player-pause" aria-hidden="true" />}
            title="Pause this queue"
            subtitle="Nobody new is called. An emergency can still be."
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={pause.isPending}>
                        Cancel
                    </Button>
                    <Button
                        disabled={pause.isPending}
                        onClick={() =>
                            pause.mutate(
                                { doctorId, locationId, reason, minutes: minutes ?? undefined },
                                { onSuccess: onClose },
                            )
                        }
                    >
                        Pause queue
                    </Button>
                </>
            }
        >
            <label className="form-label">For how long</label>
            <div className="sq-lengths" role="radiogroup" aria-label="For how long">
                {PAUSE_LENGTHS.map((option) => (
                    <button
                        type="button"
                        key={option.label}
                        role="radio"
                        aria-checked={minutes === option.minutes}
                        className={`sq-length${minutes === option.minutes ? ' is-on' : ''}`}
                        onClick={() => setMinutes(option.minutes)}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            <label className="form-label mt-3" htmlFor="sq-pause-reason">
                Reason (optional)
            </label>
            <input
                id="sq-pause-reason"
                className="form-control"
                maxLength={191}
                value={reason}
                placeholder="Lunch, ward round…"
                onChange={(event) => setReason(event.target.value)}
            />
        </Modal>
    );
}
