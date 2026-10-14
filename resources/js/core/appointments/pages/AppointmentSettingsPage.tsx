import { useEffect, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useAppointmentSettings,
    useSaveAppointmentSettings,
    type AppointmentSettings,
    type Who,
} from '../reschedule';

interface Choice {
    key: keyof AppointmentSettings;
    title: string;
    help: string;
    /** What each option means for this question, in the words a clinic uses. */
    labels: Record<Who, string>;
}

const QUEUE: Choice[] = [
    {
        key: 'check_in_by',
        title: 'Who checks patients in',
        help: 'Marks a patient as arrived and gives them a token.',
        labels: { desk: 'Front desk', doctor: 'Doctor', both: 'Either' },
    },
    {
        key: 'call_by',
        title: 'Who calls the next patient',
        help: 'Sends the patient in to the doctor.',
        labels: { desk: 'Front desk', doctor: 'Doctor', both: 'Either' },
    },
    {
        key: 'consult_start_by',
        title: 'Who starts the consultation',
        help: 'Marks the patient as with the doctor and opens the write-up.',
        labels: { desk: 'Front desk', doctor: 'Doctor', both: 'Either' },
    },
];

const NOTIFY: Choice = {
    key: 'reschedule_notify',
    title: 'Who is told about a reschedule request',
    help: 'An app notification and an email when a patient asks, from the app, to move a booking. The front desk answers it either way.',
    labels: { desk: 'Front desk', doctor: 'Doctor', both: 'Both' },
};

const OPTIONS: Who[] = ['desk', 'doctor', 'both'];

/**
 * How appointments run here.
 *
 * Not every clinic has a receptionist: a doctor working alone checks in,
 * calls and starts everybody, and the queue's buttons follow whatever is set
 * here — for everybody, on the web and in the app.
 */
export default function AppointmentSettingsPage() {
    const { can, refreshSession } = useTenantAuth();
    const { data, isLoading } = useAppointmentSettings();
    const save = useSaveAppointmentSettings();
    const [values, setValues] = useState<AppointmentSettings | null>(null);

    useEffect(() => {
        if (data) setValues(data);
    }, [data]);

    const editable = can('appointments.settings');

    const onSave = async () => {
        if (!values) return;

        await save.mutateAsync(values);

        // This person's own queue buttons may have just changed.
        await refreshSession();
    };

    const row = (choice: Choice) => (
        <div className="as-row" key={choice.key}>
            <div className="as-text">
                <div className="fw-semibold">{choice.title}</div>
                <div className="text-muted small">{choice.help}</div>
            </div>
            <div className="btn-group" role="radiogroup" aria-label={choice.title}>
                {OPTIONS.map((option) => (
                    <button
                        key={option}
                        type="button"
                        role="radio"
                        aria-checked={values?.[choice.key] === option}
                        disabled={!editable}
                        className={`btn btn-sm ${values?.[choice.key] === option ? 'btn-primary' : 'btn-outline-secondary'}`}
                        onClick={() => values && setValues({ ...values, [choice.key]: option })}
                    >
                        {choice.labels[option]}
                    </button>
                ))}
            </div>
        </div>
    );

    return (
        <>
            <PageHeader
                title="Appointments"
                subtitle="Who runs the queue, and who hears about reschedule requests"
                icon="ti ti-calendar-cog"
            />

            {isLoading || !values ? (
                <LoadingBlock />
            ) : (
                <>
                    <Card className="mt-3">
                        <h6 className="mb-1">The queue</h6>
                        <p className="text-muted small mb-3">
                            No receptionist? Set all three to Doctor, and doctors check in, call and
                            start their own patients from their list.
                        </p>
                        {QUEUE.map(row)}
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-3">Reschedule requests</h6>
                        {row(NOTIFY)}
                    </Card>

                    {editable && (
                        <div className="mt-3">
                            <Button icon="ti ti-check" onClick={() => void onSave()} disabled={save.isPending}>
                                {save.isPending ? 'Saving…' : 'Save'}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </>
    );
}
