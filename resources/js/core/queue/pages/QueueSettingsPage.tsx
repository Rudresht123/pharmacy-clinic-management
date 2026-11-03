import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Modal } from '@/shared/components/ui/Modal';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { useOpdContext } from '@/core/opd/useOpdContext';
import { BranchPicker } from '../components/BranchPicker';
import { useQueueSettings, useRemoveSharedRun, useSaveDoctorQueueSetting, useSaveSharedRun } from '../api';
import type { DoctorQueueSetting } from '../types';

function DoctorDialog({
    doctor,
    locationId,
    onClose,
}: {
    doctor: DoctorQueueSetting | null;
    locationId: number;
    onClose: () => void;
}) {
    const save = useSaveDoctorQueueSetting();
    const [values, setValues] = useState<DoctorQueueSetting | null>(doctor);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setValues(doctor);
        setError(null);
    }, [doctor]);

    const set = (change: Partial<DoctorQueueSetting>) => setValues((current) => current && { ...current, ...change });

    return (
        <Modal
            open={doctor !== null}
            onClose={onClose}
            size="md"
            busy={save.isPending}
            icon={<i className="ti ti-settings" aria-hidden="true" />}
            title={doctor?.doctor_name ?? 'Doctor'}
            subtitle="How their queue runs at this branch."
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button
                        disabled={save.isPending}
                        onClick={() =>
                            values &&
                            save.mutate(
                                {
                                    doctorId: values.doctor_id,
                                    locationId,
                                    values: {
                                        room_no: values.room_no,
                                        token_prefix: values.token_prefix,
                                        average_consultation_minutes: values.average_consultation_minutes,
                                        auto_call_enabled: values.auto_call_enabled,
                                        allow_priority: values.allow_priority,
                                        queue_enabled: values.queue_enabled,
                                    },
                                },
                                {
                                    onSuccess: onClose,
                                    onError: (failure) => {
                                        const fields = getValidationErrors(failure);

                                        setError(Object.values(fields ?? {})[0]?.[0] ?? resolveErrorMessage(failure));
                                    },
                                },
                            )
                        }
                    >
                        Save
                    </Button>
                </>
            }
        >
            {values && (
                <>
                    {error && <div className="alert alert-danger py-2">{error}</div>}

                    <div className="row g-3">
                        <div className="col-sm-4">
                            <label className="form-label" htmlFor="sq-room">Room</label>
                            <input
                                id="sq-room"
                                className="form-control"
                                maxLength={30}
                                value={values.room_no ?? ''}
                                placeholder="01"
                                onChange={(event) => set({ room_no: event.target.value })}
                            />
                        </div>
                        <div className="col-sm-4">
                            <label className="form-label" htmlFor="sq-prefix">Token prefix</label>
                            <input
                                id="sq-prefix"
                                className="form-control"
                                maxLength={4}
                                value={values.token_prefix ?? ''}
                                onChange={(event) => set({ token_prefix: event.target.value.toUpperCase() })}
                            />
                        </div>
                        <div className="col-sm-4">
                            <label className="form-label" htmlFor="sq-average">Minutes per patient</label>
                            <input
                                id="sq-average"
                                type="number"
                                min={1}
                                max={240}
                                className="form-control"
                                value={values.average_consultation_minutes}
                                onChange={(event) => set({ average_consultation_minutes: Number(event.target.value) })}
                            />
                        </div>
                    </div>

                    <p className="sq-note">
                        Minutes per patient is used until this doctor has finished a few consultations here; their own
                        average takes over after that.
                    </p>

                    <div className="sq-switches">
                        <label className="form-check form-switch">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                checked={values.queue_enabled}
                                onChange={(event) => set({ queue_enabled: event.target.checked })}
                            />
                            <span className="form-check-label">
                                <b>On the live queue</b>
                                <small>Off keeps them off the board and the televisions. Patients still check in.</small>
                            </span>
                        </label>

                        <label className="form-check form-switch">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                checked={values.auto_call_enabled}
                                onChange={(event) => set({ auto_call_enabled: event.target.checked })}
                            />
                            <span className="form-check-label">
                                <b>Call the next patient automatically</b>
                                <small>As soon as this doctor finishes a consultation.</small>
                            </span>
                        </label>

                        <label className="form-check form-switch">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                checked={values.allow_priority}
                                onChange={(event) => set({ allow_priority: event.target.checked })}
                            />
                            <span className="form-check-label">
                                <b>Take urgent patients ahead of turn</b>
                                <small>Off keeps a strict order. An emergency can always go first.</small>
                            </span>
                        </label>
                    </div>
                </>
            )}
        </Modal>
    );
}

/**
 * How a branch's queues are set up: who sits where, and what their tokens read.
 */
export default function QueueSettingsPage() {
    const context = useOpdContext();
    const { branchId, branch, branchesLoading } = context;

    const { data, isLoading, isError, refetch } = useQueueSettings(branchId);

    const [editing, setEditing] = useState<DoctorQueueSetting | null>(null);
    const [departmentId, setDepartmentId] = useState('');
    const [prefix, setPrefix] = useState('');

    const saveRun = useSaveSharedRun();
    const removeRun = useRemoveSharedRun();

    if (branchesLoading) {
        return <LoadingBlock label="Loading…" />;
    }

    const runs = data?.shared_runs ?? [];
    const departments = data?.departments ?? [];
    const free = departments.filter((department) => !runs.some((run) => run.department_id === department.id));

    return (
        <>
            <PageHeader
                title="Queue settings"
                subtitle={branch ? `Rooms and tokens at ${branch.name}.` : 'Rooms and tokens.'}
                icon="ti ti-adjustments"
                tone="sky"
                crumbs={[{ label: 'Settings' }, { label: 'Queue settings' }]}
                actions={<BranchPicker context={context} />}
            />

            <Card
                className="sq-board"
                icon="ti ti-stethoscope"
                title="Doctors"
                description="Each doctor has their own token run, which starts again every morning."
            >
                {isLoading ? (
                    <LoadingBlock label="Loading…" />
                ) : isError ? (
                    <ErrorState message="Unable to load these settings." onRetry={() => refetch()} />
                ) : (data?.doctors ?? []).length === 0 ? (
                    <div className="org-pending">
                        <i className="ti ti-stethoscope" />
                        <h6>No doctors are posted here</h6>
                        <p>Post a doctor to this branch from Schedules, and they appear here.</p>
                    </div>
                ) : (
                    <div className="q-table-scroll tbl-cards-scroll">
                        <table className="q-table tbl-cards sq-table">
                            <thead>
                                <tr>
                                    <th>Doctor</th>
                                    <th>Room</th>
                                    <th>Token prefix</th>
                                    <th>Minutes per patient</th>
                                    <th>Auto call</th>
                                    <th>On the queue</th>
                                    <th className="q-th-act">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(data?.doctors ?? []).map((doctor) => (
                                    <tr key={doctor.doctor_id}>
                                        <td data-label="Doctor">
                                            <b className="fw-semibold">{doctor.doctor_name}</b>
                                            {doctor.department && <small className="sq-event-detail">{doctor.department}</small>}
                                        </td>
                                        <td data-label="Room">{doctor.room_no ?? <span className="q-dim">Not set</span>}</td>
                                        <td data-label="Token prefix">
                                            <span className="sq-token">{doctor.token_prefix ?? '—'}</span>
                                        </td>
                                        <td className="q-dim" data-label="Minutes per patient">{doctor.average_consultation_minutes}</td>
                                        <td className="q-dim" data-label="Auto call">{doctor.auto_call_enabled ? 'On' : 'Off'}</td>
                                        <td className="q-dim" data-label="On the queue">{doctor.queue_enabled ? 'Yes' : 'No'}</td>
                                        <td className="q-row-act" data-label="">
                                            <div className="q-actions">
                                                <button type="button" className="q-act" onClick={() => setEditing(doctor)}>
                                                    <i className="ti ti-pencil" /> Edit
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card
                className="sq-board"
                icon="ti ti-sitemap"
                title="Shared department runs"
                description="Optional. A department listed here shares one run of tokens between its doctors, for a single token machine at its door."
            >
                {runs.length > 0 && (
                    <div className="q-table-scroll tbl-cards-scroll sq-gap">
                        <table className="q-table tbl-cards sq-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Token prefix</th>
                                    <th className="q-th-act">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((run) => (
                                    <tr key={run.department_id}>
                                        <td data-label="Department">
                                            {departments.find((department) => department.id === run.department_id)?.name ?? 'Removed'}
                                        </td>
                                        <td data-label="Token prefix">
                                            <span className="sq-token">{run.token_prefix}</span>
                                        </td>
                                        <td className="q-row-act" data-label="">
                                            <div className="q-actions">
                                                <button
                                                    type="button"
                                                    className="q-act"
                                                    disabled={removeRun.isPending}
                                                    onClick={() => removeRun.mutate({ departmentId: run.department_id, locationId: Number(branchId) })}
                                                >
                                                    <i className="ti ti-x" /> Stop sharing
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="sq-run-form">
                    <SearchableSelect
                        compact
                        value={departmentId}
                        ariaLabel="Department"
                        placeholder="Choose a department"
                        onChange={setDepartmentId}
                        options={free.map((department) => ({ value: String(department.id), label: department.name }))}
                    />
                    <input
                        className="form-control sq-run-prefix"
                        maxLength={4}
                        value={prefix}
                        aria-label="Token prefix"
                        placeholder="Prefix"
                        onChange={(event) => setPrefix(event.target.value.toUpperCase())}
                    />
                    <Button
                        variant="light"
                        disabled={departmentId === '' || prefix.trim() === '' || saveRun.isPending}
                        onClick={() =>
                            saveRun.mutate(
                                { departmentId: Number(departmentId), locationId: Number(branchId), prefix },
                                {
                                    onSuccess: () => {
                                        setDepartmentId('');
                                        setPrefix('');
                                    },
                                },
                            )
                        }
                    >
                        Share a run
                    </Button>
                </div>
            </Card>

            <DoctorDialog doctor={editing} locationId={Number(branchId)} onClose={() => setEditing(null)} />
        </>
    );
}
