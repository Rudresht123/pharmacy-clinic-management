import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { ConfigurableForm, type FieldGroup } from '@/core/field-settings/ConfigurableForm';
import { useEntityLabel } from '@/core/field-settings/api';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { ScheduleEditor } from '../components/ScheduleEditor';
import { doctorsHooks, useDoctorFields } from '../api';

// A `type`, not an `interface` — only type aliases get the implicit index
// signature the resource API's payload requires.
type DoctorFormValues = Record<string, unknown>;

const GROUPS: FieldGroup[] = [
    {
        key: 'identity',
        title: 'Who They Are',
        icon: 'ti ti-user',
        description: 'How this doctor appears on a queue board and a prescription.',
    },
    {
        key: 'contact',
        title: 'Contact',
        icon: 'ti ti-address-book',
        description: 'How to reach them.',
    },
    {
        key: 'practice',
        title: 'Practice',
        icon: 'ti ti-license',
        description: 'Registration, and what a consultation normally costs.',
        rail: true,
    },
    {
        key: 'other',
        title: 'Other',
        icon: 'ti ti-note',
        description: 'Anything else worth keeping on the record.',
        rail: true,
    },
    {
        key: 'custom',
        title: 'Additional Details',
        icon: 'ti ti-adjustments',
        description: 'Fields your organization added for itself.',
        rail: true,
    },
];

/**
 * Adding or editing a doctor.
 *
 * Timings appear only once the doctor exists: a sitting belongs to somebody,
 * and there is nobody to belong to until the record is saved. That is also
 * why the two are saved separately — the week is replaced whole, on its own
 * endpoint, because overlap can only be judged across a whole day.
 */
export default function DoctorFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);
    const label = useEntityLabel('doctor');

    const { data: fields, isLoading: fieldsLoading } = useDoctorFields();
    const { data: doctor, isLoading: recordLoading } = doctorsHooks.useDetail(id);

    const create = doctorsHooks.useCreate();
    const update = doctorsHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<DoctorFormValues>({
        defaultValues: { is_active: true },
    });

    useEffect(() => {
        if (!doctor) {
            return;
        }

        reset({
            name: doctor.name,
            code: doctor.code ?? '',
            specialisation: doctor.specialisation ?? '',
            qualification: doctor.qualification ?? '',
            registration_no: doctor.registration_no ?? '',
            phone: doctor.phone ?? '',
            email: doctor.email ?? '',
            default_consultation_fee: doctor.default_consultation_fee ?? '',
            notes: doctor.notes ?? '',
            is_active: doctor.is_active,
            custom_fields: doctor.custom_fields ?? {},
        });
    }, [doctor, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, async () =>
            isEdit && id ? update.mutateAsync({ id, payload: values }) : create.mutateAsync(values),
        );

        if (result) {
            /*
             * A new doctor lands on their own edit screen rather than back on
             * the list, because the next thing anybody wants is their
             * timings — and those cannot be set until the doctor exists.
             */
            navigate(isEdit ? '/doctors' : `/doctors/${result.id}/edit`);
        }
    });

    if (fieldsLoading || (isEdit && recordLoading)) {
        return <LoadingBlock label="Loading…" />;
    }

    const singular = label.singular || 'Doctor';

    return (
        <>
            <PageHeader
                title={`${isEdit ? 'Edit' : 'Add'} ${singular}`}
                icon={isEdit ? 'ti ti-user-edit' : 'ti ti-user-plus'}
                tone={isEdit ? 'amber' : 'violet'}
                crumbs={[
                    { label: label.plural || 'Doctors', to: '/doctors' },
                    { label: isEdit ? 'Edit' : 'Add' },
                ]}
            />

            <form onSubmit={onSubmit} noValidate>
                <FormError message={errors.root?.message} />

                <p className="form-legend">
                    <span className="req">*</span> Required. Everything else can be filled in later.
                </p>

                <ConfigurableForm
                    fields={fields}
                    groups={GROUPS}
                    register={register}
                    errors={errors}
                    control={control}
                />

                <div className="form-actions">
                    {/* Only on edit: a record being created has no history yet. */}
                    {isEdit && doctor && (
                        <RecordHistory entity="Doctor" id={doctor.id} label={doctor.name} />
                    )}

                    <span className="form-actions-note">
                        Where this doctor works comes from their timings, below.
                    </span>

                    <Button variant="light" onClick={() => navigate('/doctors')}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {`${isEdit ? 'Update' : 'Add'} ${singular}`}
                    </Button>
                </div>
            </form>

            {/*
             * Outside the form, and saved by its own button: a sitting has
             * to belong to a doctor who already exists, and the week is
             * replaced whole rather than submitted alongside these fields.
             */}
            {isEdit && doctor && <ScheduleEditor doctorId={doctor.id} />}
        </>
    );
}
