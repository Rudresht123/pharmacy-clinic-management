import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { ConfigurableForm, type FieldGroup } from '@/core/field-settings/ConfigurableForm';
import { useEntityLabel } from '@/core/field-settings/api';
import { customersHooks, useCustomerFields } from '../api';

// A `type`, not an `interface` — only type aliases get the implicit index
// signature the resource API's payload requires.
type CustomerFormValues = Record<string, unknown>;

const GROUPS: FieldGroup[] = [
    {
        key: 'identity',
        title: 'Who They Are',
        icon: 'ti ti-user',
        description: 'Enough to find this person again at any counter.',
    },
    {
        key: 'contact',
        title: 'Contact',
        icon: 'ti ti-map-pin',
        description: 'Where to reach them.',
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

export default function CustomerFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);
    const label = useEntityLabel('customer');

    const { data: fields, isLoading: fieldsLoading } = useCustomerFields();
    const { data: customer, isLoading: recordLoading } = customersHooks.useDetail(id);

    const create = customersHooks.useCreate();
    const update = customersHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<CustomerFormValues>({
        defaultValues: { is_active: true },
    });

    useEffect(() => {
        if (!customer) {
            return;
        }

        reset({
            registered_location_id: customer.registered_location_id ?? '',
            name: customer.name,
            phone: customer.phone ?? '',
            email: customer.email ?? '',
            date_of_birth: customer.date_of_birth ?? '',
            gender: customer.gender ?? '',
            address: customer.address ?? '',
            city: customer.city ?? '',
            state: customer.state ?? '',
            pincode: customer.pincode ?? '',
            notes: customer.notes ?? '',
            is_active: customer.is_active,
            custom_fields: customer.custom_fields ?? {},
        });
    }, [customer, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, async () =>
            isEdit && id ? update.mutateAsync({ id, payload: values }) : create.mutateAsync(values),
        );

        if (result) {
            navigate('/customers');
        }
    });

    if (fieldsLoading || (isEdit && recordLoading)) {
        return <LoadingBlock label="Loading…" />;
    }

    return (
        <>
            <PageHeader
                title={`${isEdit ? 'Edit' : 'Add'} ${label.singular}`}
                icon={isEdit ? 'ti ti-user-edit' : 'ti ti-user-plus'}
                tone={isEdit ? 'amber' : 'sky'}
                crumbs={[
                    { label: label.plural, to: '/customers' },
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
                    {/* Only on edit: a record being created has no
                        history to show yet. */}
                    {isEdit && customer && (
                        <RecordHistory entity="Customer" id={customer.id} label={customer?.name} />
                    )}

                    <span className="form-actions-note">
                        This record belongs to the whole organization, not one store.
                    </span>

                    <Button variant="light" onClick={() => navigate('/customers')}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {`${isEdit ? 'Update' : 'Add'} ${label.singular}`}
                    </Button>
                </div>
            </form>
        </>
    );
}
