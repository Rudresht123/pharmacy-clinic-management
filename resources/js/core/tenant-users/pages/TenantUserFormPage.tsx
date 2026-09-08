import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError, TextField } from '@/shared/components/form/Fields';
import { Controller } from 'react-hook-form';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { ConfigurableForm, type FieldGroup } from '@/core/field-settings/ConfigurableForm';
import { rolesHooks } from '@/core/roles/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { BranchMemberships } from '../components/BranchMemberships';
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

    const { data: roles } = rolesHooks.useList();
    const { can } = useTenantAuth();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        watch,
        formState: { errors, isSubmitting },
    } = useApiForm<UserFormValues>({
        defaultValues: { role: 'staff', role_id: '', is_active: true },
    });

    // An owner bypasses roles, so the picker only means anything for staff.
    const isStaff = watch('role') === 'staff';

    useEffect(() => {
        if (!person) {
            return;
        }

        reset({
            name: person.name,
            email: person.email,
            role: person.role,
            role_id: person.role_id ?? '',
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

        /*
         * An owner is refused a role by the server rather than quietly given
         * one, so promoting somebody clears it here instead of sending a
         * limit that nothing would enforce.
         */
        payload.role_id = payload.role === 'staff' ? Number(payload.role_id) || null : null;

        const result = await submit(payload, async () =>
            isEdit && id ? update.mutateAsync({ id, payload }) : create.mutateAsync(payload),
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

        /*
         * Sits beside `role` rather than in the schema: which capabilities
         * somebody holds is not a configurable field an organization can
         * rename or remove, and it has to disappear the moment they become an
         * owner — which no field setting can express.
         */
        access: isStaff ? (
            <div className="form-group">
                <label className="form-label" htmlFor="role_id">
                    Role <span className="req">*</span>
                </label>

                <Controller
                    name="role_id"
                    control={control}
                    render={({ field }) => (
                        <SearchableSelect
                            id="role_id"
                            value={field.value == null ? '' : String(field.value)}
                            onChange={field.onChange}
                            invalid={Boolean(errors.role_id)}
                            placeholder="Choose a role…"
                            options={(roles ?? []).map((role) => ({
                                value: String(role.id),
                                label: role.name,
                            }))}
                        />
                    )}
                />

                {errors.role_id ? (
                    <p className="invalid-feedback d-block">
                        {String(errors.role_id.message ?? '')}
                    </p>
                ) : (
                    <p className="form-hint">
                        What they may do. Manage the list under Roles &amp; Permissions.
                    </p>
                )}
            </div>
        ) : (
            <p className="form-hint">
                An owner is not limited by a role, and works across every branch.
            </p>
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

                {/*
                    Only once they exist: a person being created has no id to
                    hang memberships on, so branches are set on the next screen.
                    Saved separately because assigning them is a different,
                    organization-scoped permission.
                */}
                {isEdit && person && (
                    <BranchMemberships
                        userId={person.id}
                        initial={(person.branches ?? []).map((branch) => ({
                            location_id: branch.location_id,
                            role_id: branch.role_id,
                            is_primary: branch.is_primary,
                        }))}
                        canAssign={can('people.assign_branch')}
                    />
                )}

                <div className="form-actions">
                    {/* Only on edit: a record being created has no
                        history to show yet. */}
                    {isEdit && person && (
                        <RecordHistory entity="User" id={person.id} label={person?.name} />
                    )}

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
