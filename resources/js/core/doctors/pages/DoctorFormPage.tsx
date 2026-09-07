import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { ConfigurableForm, type FieldGroup } from '@/core/field-settings/ConfigurableForm';
import { useEntityLabel } from '@/core/field-settings/api';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { ScheduleEditor } from '../components/ScheduleEditor';
import { locationsHooks } from '@/core/locations/api';
import { doctorsHooks, useDoctorFields } from '../api';

// A `type`, not an `interface` — only type aliases get the implicit index
// signature the resource API's payload requires.
type DoctorFormValues = Record<string, unknown>;

const GROUPS: FieldGroup[] = [
    /*
     * Not a configurable field — a posting is a row of its own, not a column
     * on the doctor.
     *
     * A doctor belongs to the organization and covers as many of its branches
     * as somebody says. Until this existed the only way to say "he works at
     * Gurgaon" was to give him a Gurgaon sitting, so a consultant taken on
     * before anybody agreed his hours could not be recorded as working
     * anywhere at all.
     */
    {
        key: 'branches',
        title: 'Branches',
        icon: 'ti ti-building-store',
        description: 'Where this doctor works. Their hours are set separately.',
        rail: true,
    },
    /*
     * Not a configurable field — none of this is stored on the doctor.
     *
     * `Doctor::user()` and the login's `doctor_id` have existed since the
     * schema did, and nothing ever wrote the link between them: the morphOne
     * was there, the unique index was there, the queue was ready to open on a
     * doctor's own list, and no doctor could sign in because no screen could
     * create the account. This is that screen.
     */
    {
        key: 'account',
        title: 'Login access',
        icon: 'ti ti-key',
        description: 'Lets this doctor sign in and see their own list.',
        rail: true,
    },
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

    /*
     * Off unless they already have one. A visiting consultant who never
     * touches the system is why doctors are their own table rather than a role
     * on users, so an account is the exception rather than the default.
     */
    const [withAccount, setWithAccount] = useState(false);

    /*
     * Held outside the form because it is a set of ids rather than a field
     * value, and `register` has no way to express a set of checkboxes that
     * post back as one array.
     */
    const [branches, setBranches] = useState<number[]>([]);

    // Every branch the organization has, to tick against.
    const { data: allBranches } = locationsHooks.useList({ all: 1 });

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
            qualifications: doctor.qualifications ?? [],
            registration_no: doctor.registration_no ?? '',
            phone: doctor.phone ?? '',
            email: doctor.email ?? '',
            default_consultation_fee: doctor.default_consultation_fee ?? '',
            notes: doctor.notes ?? '',
            is_active: doctor.is_active,
            custom_fields: doctor.custom_fields ?? {},

            // The email only. A password is write-only, so the field starts
            // blank and blank means "leave it alone".
            account: { email: doctor.account?.email ?? '', password: '' },
        });

        setWithAccount(Boolean(doctor.account));
        setBranches(doctor.location_ids ?? []);
    }, [doctor, reset]);

    const onSubmit = handleSubmit(async (values) => {
        /*
         * The inputs stay mounted while the section is off, so their values
         * would still be sent. Stripped here rather than unmounted, because
         * somebody who fills it in, switches it off and on again should find
         * what they typed still there.
         *
         * Null rather than omitted: on an edit the server reads an absent
         * block as "they should not have a login" and closes the one they had,
         * which is exactly what switching it off means.
         */
        const payload = {
            ...values,
            account: withAccount ? values.account : null,
            locations: branches,
        };

        const result = await submit(payload, async () =>
            isEdit && id ? update.mutateAsync({ id, payload }) : create.mutateAsync(payload),
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
                    extras={{
                        branches: (
                            <>
                                {(allBranches ?? []).length === 0 ? (
                                    <p className="form-hint">
                                        This organization has no branches yet. Add one first and
                                        the doctor can be posted to it.
                                    </p>
                                ) : (
                                    <div className="dr-branches">
                                        {(allBranches ?? []).map((branch) => (
                                            <label className="dr-branch" key={branch.id}>
                                                <input
                                                    type="checkbox"
                                                    className="form-check-input"
                                                    checked={branches.includes(branch.id)}
                                                    onChange={(event) =>
                                                        setBranches((was) =>
                                                            event.target.checked
                                                                ? [...was, branch.id]
                                                                : was.filter(
                                                                      (id) => id !== branch.id,
                                                                  ),
                                                        )
                                                    }
                                                />

                                                <span>
                                                    <b>{branch.name}</b>
                                                    {branch.city && <small>{branch.city}</small>}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                )}

                                {/*
                                    Says what unticking will actually do, before
                                    it is done. Removing a branch removes the
                                    sittings there — leaving them would have the
                                    timetable offering slots at a branch the
                                    doctor no longer covers.
                                */}
                                {isEdit && (
                                    <p className="form-hint">
                                        Removing a branch also removes this doctor&rsquo;s hours
                                        there. Appointments already booked keep their date, time
                                        and branch.
                                    </p>
                                )}
                            </>
                        ),

                        account: (
                            <>
                                <div className="form-check form-switch mb-3">
                                    <input
                                        id="with-account"
                                        type="checkbox"
                                        className="form-check-input"
                                        checked={withAccount}
                                        onChange={(event) =>
                                            setWithAccount(event.target.checked)
                                        }
                                    />

                                    <label className="form-check-label" htmlFor="with-account">
                                        Give this doctor a login
                                    </label>

                                    <small className="text-muted d-block mt-1">
                                        They see their own list and can write up what happened —
                                        not the desk&rsquo;s work of booking and cancelling.
                                    </small>
                                </div>

                                {withAccount && (
                                    <>
                                        <TextField
                                            name="account.email"
                                            label="Email"
                                            type="email"
                                            required
                                            register={register}
                                            errors={errors}
                                            autoComplete="off"
                                            hint="What they sign in with. Unused across the organization."
                                        />

                                        <TextField
                                            name="account.password"
                                            label={doctor?.account ? 'New password' : 'Password'}
                                            type="password"
                                            required={!doctor?.account}
                                            register={register}
                                            errors={errors}
                                            autoComplete="new-password"
                                            hint={
                                                doctor?.account
                                                    ? 'Leave blank to keep their current password.'
                                                    : 'At least 8 characters.'
                                            }
                                        />
                                    </>
                                )}

                                {/* Says what switching it off will do, before
                                    it is done rather than after. */}
                                {!withAccount && doctor?.account && (
                                    <p className="form-hint text-danger">
                                        Saving now removes their login. The doctor and everything
                                        recorded against them stays.
                                    </p>
                                )}
                            </>
                        ),
                    }}
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
