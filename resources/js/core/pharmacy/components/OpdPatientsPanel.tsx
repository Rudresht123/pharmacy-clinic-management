import { Card } from '@/shared/components/ui/Card';
import { formatRelative } from '@/shared/utils/format';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useRealtimeConnected } from '@/shared/realtime/echo';
import { useOpdPatients, useOpdPatientsLive, type OpdPatient } from '../opd';

/**
 * Patients from OPD — who the doctor has just finished with, at this
 * store's branch.
 *
 * Shown ONLY where the branch runs both the OPD and the pharmacy
 * (`opd_pharmacy_handoff`). A medical shop that was never sold appointments
 * never sees it, and nor does a counter at a branch with no OPD. Hiding it is
 * a courtesy; the endpoint and the channel refuse the same people.
 *
 * Live over Reverb when it is running, re-fetched every half minute when it
 * is not, so the counter is never silently stale.
 */
export function OpdPatientsPanel({
    storeId,
    onPick,
}: {
    storeId: number;
    /** Start the bill for this patient. */
    onPick?: (patient: OpdPatient) => void;
}) {
    const { feature, realtime } = useTenantAuth();
    const enabled = feature('opd_pharmacy_handoff');

    // Live only while the socket is really connected; otherwise re-fetch.
    const live = useRealtimeConnected(enabled ? realtime : null);

    const { data, isLoading, isError } = useOpdPatients(storeId, enabled, live);

    useOpdPatientsLive(storeId, enabled ? data?.channel : undefined, realtime);

    if (!enabled || isError) {
        return null;
    }

    const patients = data?.patients ?? [];
    const waiting = patients.filter((patient) => patient.pharmacy_pending).length;

    return (
        <Card
            className="opd-handoff"
            title="Patients from OPD"
            icon="ti ti-stethoscope"
            description={
                waiting > 0
                    ? `${waiting} waiting for medicines from today's consultations`
                    : "Today's consultations at this branch"
            }
            actions={
                <span
                    className={`opd-live${live ? ' is-live' : ''}`}
                    title={live ? 'Updates the moment a doctor finishes' : 'Checks every 30 seconds'}
                >
                    <i className={live ? 'ti ti-broadcast' : 'ti ti-refresh'} aria-hidden="true" />
                    {live ? 'Live' : 'Auto-refresh'}
                </span>
            }
        >
            {isLoading ? (
                <p className="opd-empty">Loading…</p>
            ) : patients.length === 0 ? (
                <p className="opd-empty">
                    Nobody yet today. A patient appears here as soon as their doctor completes the
                    consultation.
                </p>
            ) : (
                <ul className="opd-list">
                    {patients.map((patient) => (
                        <li key={patient.appointment_id}>
                            <button
                                type="button"
                                className={`opd-patient${patient.pharmacy_pending ? ' is-waiting' : ''}`}
                                onClick={() => onPick?.(patient)}
                                disabled={!onPick}
                                title={onPick ? `Start a bill for ${patient.patient.name ?? 'this patient'}` : undefined}
                            >
                                {patient.token_no !== null && (
                                    <span className="opd-token" aria-label="Token">
                                        {patient.token_no}
                                    </span>
                                )}

                                <span className="opd-who">
                                    <b>{patient.patient.name ?? 'Unnamed patient'}</b>
                                    <small>
                                        {patient.doctor.name ?? 'Doctor'}
                                        {patient.completed_at && ` · ${formatRelative(patient.completed_at)}`}
                                    </small>
                                </span>

                                <span
                                    className={`opd-state${patient.pharmacy_pending ? ' is-waiting' : patient.has_prescription ? ' is-done' : ''}`}
                                >
                                    {patient.pharmacy_pending
                                        ? 'Prescription waiting'
                                        : patient.has_prescription
                                          ? 'Dispensed'
                                          : 'No prescription'}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
