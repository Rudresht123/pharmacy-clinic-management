import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { ConfigurableForm, type FieldGroup } from '@/core/field-settings/ConfigurableForm';
import { tenantUsersHooks, useTenantUserFields } from '../api';

// A `type`, not an `interface` — only type aliases get the implicit index
// signature the resource API's payload requires.
type UserFormValues = Record<string, unknown>;

/** Which card each group of fields lands in. */
const GROUPS: FieldGroup[] = [
    {
        key: 'person',
        title: 'Person',
        icon: 'ti ti-user',
        description: 'Who they are, and how they sign in.',
    },
    {
        key: 'custom',
        title: 'Additional Details',
        icon: 'ti ti-adjustments',
        description: 'Fields your organization added for itself.',
    },
    {
        key: 'access',
        title: 'Access',
        icon: 'ti ti-shield-lock',
        description: 'What they are allowed to do.',
        rail: true,
    },
];

export default function TenantUserFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);

    const { data: fields, isLoading: fieldsLoading } = useTenantUserFields();
    const { data: person, isLoading: recordLoading } = tenantUsersHooks.useDetail(id);

    const create = tenantUsersHooks.useCreate();
    const update = tenantUsersHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<UserFormValues>({
        defaultValues: { role: 'staff', is_active: true },
    });

    useEffect(() => {
        if (!person) {
            return;
        }

        reset({
            name: person.name,
            email: person.email,
            role: person.role,
            is_active: person.is_active,
            password: '',
            password_confirmation: '',
            custom_fields: person.custom_fields ?? {},
        });
    }, [person, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const payload = { ...values };

        // Blank on edit means "keep the current password", so it is not sent.
        if (isEdit && !payload.password) {
            delete payload.password;
            delete payload.password_confirmation;
        }

        const result = await submit(payload, async () =>
            isEdit && id
                ? update.mutateAsync({ id, payload })
                : create.mutateAsync(payload),
        );

        if (result) {
            navigate('/people');
        }
    });

    if (fieldsLoading || (isEdit && recordLoading)) {
        return <LoadingBlock label="Loading…" />;
    }

    /*
     * Password is not a configurable field — it is write-only, hashed, and
     * optional on edit — so it is appended to the person card rather than
     * coming from the schema.
     */
    const extras = {
        person: (
            <>
                <TextField
                    name="password"
                    label={isEdit ? 'New password' : 'Password'}
                    type="password"
                    required={!isEdit}
                    register={register}
                    errors={errors}
                    autoComplete="new-password"
                    hint={
                        isEdit
                            ? 'Leave blank to keep their current password.'
                            : 'At least 8 characters, with upper and lower case, a number and a symbol.'
                    }
                />

                <TextField
                    name="password_confirmation"
                    label="Confirm password"
                    type="password"
                    required={!isEdit}
                    register={register}
                    errors={errors}
                    autoComplete="new-password"
                />
            </>
        ),
    };

    return (
        <>
            <PageHeader
                title={isEdit ? 'Edit Person' : 'Add Person'}
                icon={isEdit ? 'ti ti-user-edit' : 'ti ti-user-plus'}
                tone={isEdit ? 'amber' : 'emerald'}
                crumbs={[{ label: 'People', to: '/people' }, { label: isEdit ? 'Edit' : 'Add' }]}
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
                    extras={extras}
                />

                <div className="form-actions">
                    <span className="form-actions-note">
                        {isEdit
                            ? 'They keep their password unless you set a new one.'
                            : 'They will sign in with the email and password you set here.'}
                    </span>

                    <Button variant="light" onClick={() => navigate('/people')}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {isEdit ? 'Update Person' : 'Add Person'}
                    </Button>
                </div>
            </form>
        </>
    );
}
