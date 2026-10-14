import { useMemo, useState } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DataTable } from '@/shared/components/ui/DataTable';
import { Modal } from '@/shared/components/ui/Modal';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { useOpenSlots } from '../api';
import {
    useAnswerReschedule,
    useRescheduleRequests,
    type RescheduleRequest,
    type RescheduleStatus,
} from '../reschedule';

const TABS: readonly TabItem<RescheduleStatus | 'all'>[] = [
    { value: 'pending', label: 'Waiting', icon: 'ti ti-hourglass' },
    { value: 'approved', label: 'Approved', icon: 'ti ti-circle-check' },
    { value: 'rejected', label: 'Rejected', icon: 'ti ti-circle-x' },
    { value: 'all', label: 'All', icon: 'ti ti-list' },
];

const STATUS: Record<RescheduleStatus, { label: string; tone: string }> = {
    pending: { label: 'Waiting', tone: 'bg-warning-subtle text-warning-emphasis' },
    approved: { label: 'Approved', tone: 'bg-success-subtle text-success-emphasis' },
    rejected: { label: 'Rejected', tone: 'bg-danger-subtle text-danger-emphasis' },
    withdrawn: { label: 'Withdrawn', tone: 'bg-secondary-subtle text-secondary-emphasis' },
};

/** "10:30 AM" from the server's "10:30". */
function clock(slot: string): string {
    const [h, m] = slot.split(':').map(Number);

    return new Date(2000, 0, 1, h, m).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

/** "Wed 9 Sep, 10:30 AM". */
function when(date: string | null, slot: string | null): string {
    if (!date) return '—';

    const day = new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

    return slot ? `${day}, ${clock(slot)}` : day;
}

type Answering = { request: RescheduleRequest; mode: 'approve' | 'other' | 'reject' };

const column = createColumnHelper<RescheduleRequest>();

/**
 * Patients' requests, from the app, to move a booking — and the desk's
 * answers.
 *
 * Nothing moves until somebody here says yes. Yes moves the visit to the time
 * the patient asked for; when that time has gone since, "Another time" moves
 * it to one agreed with the patient on the phone. No leaves it where it was.
 * The patient is told either way.
 */
export default function RescheduleRequestsPage() {
    const [tab, setTab] = useState<RescheduleStatus | 'all'>('pending');
    const [answering, setAnswering] = useState<Answering | null>(null);

    const { data, isLoading, isError, refetch } = useRescheduleRequests(tab);

    const columns = useMemo(
        () =>
            [
                column.display({
                    id: 'patient',
                    header: 'Patient',
                    cell: ({ row }) => (
                        <div>
                            <div className="fw-semibold">{row.original.patient.name ?? '—'}</div>
                            <div className="text-muted small">
                                {[row.original.patient.code, row.original.patient.phone]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </div>
                        </div>
                    ),
                }),
                column.display({
                    id: 'doctor',
                    header: 'Doctor',
                    cell: ({ row }) => (
                        <div>
                            <div>{row.original.doctor.name ?? '—'}</div>
                            <div className="text-muted small">{row.original.location.name}</div>
                        </div>
                    ),
                }),
                column.display({
                    id: 'move',
                    header: 'From → To',
                    cell: ({ row }) => {
                        const r = row.original;
                        const to = r.approved ?? r.requested;

                        return (
                            <div>
                                <div className="text-muted small text-decoration-line-through">
                                    {when(r.from.date, r.from.slot_at)}
                                </div>
                                <div className="fw-semibold">
                                    <i className="ti ti-arrow-right me-1" aria-hidden="true" />
                                    {when(to.date, to.slot_at)}
                                </div>
                                {r.approved &&
                                    (r.approved.date !== r.requested.date ||
                                        r.approved.slot_at !== r.requested.slot_at) && (
                                        <div className="text-muted small">
                                            Asked for {when(r.requested.date, r.requested.slot_at)}
                                        </div>
                                    )}
                            </div>
                        );
                    },
                }),
                column.display({
                    id: 'reason',
                    header: 'Reason',
                    cell: ({ row }) => (
                        <div className="small">
                            {row.original.reason ?? <span className="text-muted">—</span>}
                            {row.original.decision_note && (
                                <div className="text-muted mt-1">
                                    <i className="ti ti-message-2 me-1" aria-hidden="true" />
                                    {row.original.decision_note}
                                </div>
                            )}
                        </div>
                    ),
                }),
                column.display({
                    id: 'status',
                    header: 'Status',
                    cell: ({ row }) => (
                        <div className="small">
                            <span className={`badge ${STATUS[row.original.status].tone}`}>
                                {STATUS[row.original.status].label}
                            </span>
                            {row.original.decided_by && (
                                <div className="text-muted mt-1">by {row.original.decided_by}</div>
                            )}
                        </div>
                    ),
                }),
                column.display({
                    id: 'actions',
                    header: '',
                    cell: ({ row }) =>
                        row.original.status === 'pending' ? (
                            <div className="d-flex gap-2 justify-content-end flex-wrap">
                                <Button
                                    size="sm"
                                    icon="ti ti-check"
                                    onClick={() => setAnswering({ request: row.original, mode: 'approve' })}
                                >
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-calendar-event"
                                    onClick={() => setAnswering({ request: row.original, mode: 'other' })}
                                >
                                    Another time
                                </Button>
                                <Button
                                    size="sm"
                                    variant="light"
                                    icon="ti ti-x"
                                    onClick={() => setAnswering({ request: row.original, mode: 'reject' })}
                                >
                                    Reject
                                </Button>
                            </div>
                        ) : null,
                }),
            ] as ColumnDef<RescheduleRequest, unknown>[],
        [],
    );

    return (
        <>
            <PageHeader
                title="Reschedule requests"
                subtitle="Patients asking, from the app, to move a booking. Nothing moves until you answer."
                icon="ti ti-calendar-repeat"
            />

            <Tabs tabs={TABS} value={tab} onChange={setTab} label="Request status" />

            <Card className="mt-3">
                <DataTable
                    data={data ?? []}
                    columns={columns}
                    loading={isLoading}
                    error={isError}
                    onRetry={() => void refetch()}
                    hideSearch
                    emptyIcon="ti ti-calendar-check"
                    emptyTitle={tab === 'pending' ? 'Nothing waiting' : 'No requests here'}
                    emptyDescription={
                        tab === 'pending'
                            ? 'When a patient asks to move a booking from the app, it appears here.'
                            : undefined
                    }
                />
            </Card>

            {answering && <AnswerDialog answering={answering} onClose={() => setAnswering(null)} />}
        </>
    );
}

/** Yes, yes-to-another-time, or no — with a note the patient will read. */
function AnswerDialog({ answering, onClose }: { answering: Answering; onClose: () => void }) {
    const { request, mode } = answering;
    const answer = useAnswerReschedule();

    const [note, setNote] = useState('');
    const [date, setDate] = useState(request.requested.date ?? '');
    const [slot, setSlot] = useState('');

    const locationId = request.requested.location_id ?? request.location.id;
    const slots = useOpenSlots(mode === 'other' ? request.doctor.id : '', locationId, date);
    const free = (slots.data ?? []).filter((s) => s.location_id === locationId).flatMap((s) => s.slots);

    const submit = async () => {
        await answer.mutateAsync({
            id: request.id,
            answer: mode === 'reject' ? 'reject' : 'approve',
            body: {
                ...(note.trim() ? { note: note.trim() } : {}),
                ...(mode === 'other' ? { appointment_date: date, slot_at: slot, location_id: locationId } : {}),
            },
        });

        onClose();
    };

    const title =
        mode === 'reject' ? 'Reject the request?' : mode === 'other' ? 'Move to another time' : 'Approve the request?';

    return (
        <Modal
            open
            title={title}
            subtitle={`${request.patient.name ?? 'Patient'} · ${request.doctor.name ?? ''}`}
            onClose={onClose}
            busy={answer.isPending}
            tone={mode === 'reject' ? 'danger' : 'primary'}
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={answer.isPending}>
                        Cancel
                    </Button>
                    <Button
                        icon={mode === 'reject' ? 'ti ti-x' : 'ti ti-check'}
                        onClick={() => void submit()}
                        disabled={answer.isPending || (mode === 'other' && (!date || !slot))}
                    >
                        {mode === 'reject' ? 'Reject' : 'Move the visit'}
                    </Button>
                </>
            }
        >
            <p className="mb-3">
                Now <strong>{when(request.from.date, request.from.slot_at)}</strong>
                {mode !== 'other' && (
                    <>
                        {' '}
                        — asked for <strong>{when(request.requested.date, request.requested.slot_at)}</strong>
                    </>
                )}
                .{request.reason && <span className="text-muted"> Reason: {request.reason}</span>}
            </p>

            {mode === 'other' && (
                <div className="mb-3">
                    <label className="form-label" htmlFor="rs-date">
                        New date
                    </label>
                    <input
                        id="rs-date"
                        type="date"
                        className="form-control"
                        value={date}
                        min={new Date().toISOString().slice(0, 10)}
                        onChange={(event) => {
                            setDate(event.target.value);
                            setSlot('');
                        }}
                    />

                    <div className="form-label mt-3">Free times</div>
                    {slots.isLoading ? (
                        <div className="text-muted small">Looking…</div>
                    ) : free.length === 0 ? (
                        <div className="text-muted small">The doctor has no free time that day.</div>
                    ) : (
                        <div className="d-flex flex-wrap gap-2">
                            {free.map((time) => (
                                <Button
                                    key={time}
                                    size="sm"
                                    variant={slot === time ? 'primary' : 'light'}
                                    onClick={() => setSlot(time)}
                                >
                                    {clock(time)}
                                </Button>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <label className="form-label" htmlFor="rs-note">
                Note for the patient {mode !== 'reject' && <span className="text-muted">(optional)</span>}
            </label>
            <textarea
                id="rs-note"
                className="form-control"
                rows={2}
                maxLength={500}
                placeholder={
                    mode === 'reject'
                        ? 'e.g. The doctor is away that day — please pick another date.'
                        : 'e.g. Please come 10 minutes early.'
                }
                value={note}
                onChange={(event) => setNote(event.target.value)}
            />
        </Modal>
    );
}
