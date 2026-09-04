import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { BranchModulePanel } from '@/core/roles/components/BranchModulePanel';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { ConfigurableForm, type FieldGroup } from '@/core/field-settings/ConfigurableForm';
import { locationsHooks, useLocationFields } from '../api';

// A `type`, not an `interface`: only type aliases get the implicit index
// signature the resource API's Record<string, unknown> payload needs.
type LocationFormValues = Record<string, unknown>;

/** Which card each group of fields lands in. */
const GROUPS: FieldGroup[] = [
    {
        key: 'identity',
        title: 'Location Information',
        icon: 'ti ti-building-store',
        description: 'How this place is identified across your organization.',
    },
    {
        key: 'address',
        title: 'Address & Contact',
        icon: 'ti ti-map-pin',
        description: 'Where it is and how to reach it.',
    },
    {
        key: 'compliance',
        title: 'Compliance',
        icon: 'ti ti-certificate',
        description: 'Registrations held by this premises specifically.',
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
 * Two views of one branch, not two pages.
 *
 * Its modules are a property of the branch, so they live with it rather than
 * on a separate screen somebody has to know exists. The tab only appears once
 * the branch does — there is nothing to switch on for a record that has not
 * been created.
 */
type Tab = 'details' | 'modules';

const TABS: TabItem<Tab>[] = [
    { value: 'details', label: 'Details', icon: 'ti ti-building-store' },
    { value: 'modules', label: 'Modules', icon: 'ti ti-puzzle' },
];

export default function LocationFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);

    const [tab, setTab] = useState<Tab>('details');
    const { user } = useTenantAuth();

    const { data: fields, isLoading: fieldsLoading } = useLocationFields();
    const { data: location, isLoading: recordLoading } = locationsHooks.useDetail(id);

    const create = locationsHooks.useCreate();
    const update = locationsHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<LocationFormValues>({
        defaultValues: { is_active: true },
    });

    useEffect(() => {
        if (!location) {
            return;
        }

        reset({
            name: location.name,
            code: location.code,
            type: location.type,
            is_active: location.is_active,
            address: location.address ?? '',
            city: location.city ?? '',
            state: location.state ?? '',
            pincode: location.pincode ?? '',
            phone: location.phone ?? '',
            email: location.email ?? '',
            gstin: location.gstin ?? '',
            drug_license_no: location.drug_license_no ?? '',
            drug_license_expiry_date: location.drug_license_expiry_date ?? '',
            custom_fields: location.custom_fields ?? {},
        });
    }, [location, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, async () =>
            isEdit && id ? update.mutateAsync({ id, payload: values }) : create.mutateAsync(values),
        );

        if (result) {
            navigate('/locations');
        }
    });

    if (fieldsLoading || (isEdit && recordLoading)) {
        return <LoadingBlock label="Loading location…" />;
    }

    return (
        <>
            <PageHeader
                title={isEdit ? 'Edit Location' : 'Add Location'}
                icon={isEdit ? 'ti ti-edit' : 'ti ti-building-plus'}
                tone={isEdit ? 'amber' : 'emerald'}
                crumbs={[
                    { label: 'Locations', to: '/locations' },
                    { label: isEdit ? 'Edit' : 'Add' },
                ]}
            />

            {/* Only the owner reaches level two, and only an existing branch
                has modules to decide about. */}
            {isEdit && location && user?.role === 'owner' && (
                <Tabs tabs={TABS} value={tab} onChange={setTab} label="Branch views" />
            )}

            {isEdit && location && tab === 'modules' ? (
                <BranchModulePanel locationId={location.id} />
            ) : (
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
                    {/* Only on edit: a record being created has no
                        history to show yet. */}
                    {isEdit && location && (
                        <RecordHistory entity="Location" id={location.id} label={location?.name} />
                    )}

                    <span className="form-actions-note">
                        {isEdit
                            ? 'Changes apply to this location only.'
                            : 'The code must be unique across your live locations.'}
                    </span>

                    <Button variant="light" onClick={() => navigate('/locations')}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {isEdit ? 'Update Location' : 'Create Location'}
                    </Button>
                </div>
            </form>
            )}
        </>
    );
}
