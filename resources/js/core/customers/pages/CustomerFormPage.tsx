import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
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
        description: 'Where to reach them. A PIN code fills in the rest of the address.',
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
    const [params] = useSearchParams();
    const isEdit = Boolean(id);
    const label = useEntityLabel('customer');

    /*
     * Where to go back to, and what the screen that sent us already knew.
     *
     * Registration is reached from the OPD desk mid-booking as often as from
     * the patient list, and somebody who has already typed a name into the
     * search box should not have to type it again here. `return` also means
     * "back" can be the screen they actually came from rather than a list they
     * were never on.
     */
    const returnTo = params.get('return');
    const prefillName = params.get('name') ?? '';
    const prefillPhone = params.get('phone') ?? '';

    /*
     * Which button was pressed, held across the await.
     *
     * The two differ only in what happens after the save succeeds, so the
     * submit handler is one function and this says which ending it takes.
     */
    const [andAnother, setAndAnother] = useState(false);

    const { data: fields, isLoading: fieldsLoading } = useCustomerFields();
    const { data: customer, isLoading: recordLoading } = customersHooks.useDetail(id);

    const create = customersHooks.useCreate();
    const update = customersHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        setValue,
        getValues,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<CustomerFormValues>({
        defaultValues: { is_active: true },
    });

    // Whatever the sending screen already had. Only on a new record: on an
    // edit the row's own values arrive below and must win.
    useEffect(() => {
        if (isEdit || (!prefillName && !prefillPhone)) {
            return;
        }

        reset({ is_active: true, name: prefillName, phone: prefillPhone });
    }, [isEdit, prefillName, prefillPhone, reset]);

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
            pincode: customer.pincode ?? '',
            city: customer.city ?? '',
            district: customer.district ?? '',
            state: customer.state ?? '',
            country: customer.country ?? '',
            notes: customer.notes ?? '',
            is_active: customer.is_active,
            custom_fields: customer.custom_fields ?? {},
        });
    }, [customer, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, async () =>
            isEdit && id ? update.mutateAsync({ id, payload: values }) : create.mutateAsync(values),
        );

        if (!result) {
            return;
        }

        /*
         * Registering several people in a row is one job, not several.
         *
         * A morning's new patients arrive together — a family, a camp, a
         * backlog from the phone — and sending somebody back to a list after
         * each one, to press Add again, is three clicks per person for no
         * reason. The form clears and keeps its focus instead.
         */
        if (andAnother) {
            reset({ is_active: true });
            setAndAnother(false);
            window.scrollTo({ top: 0, behavior: 'smooth' });

            return;
        }

        /*
         * Back to whoever sent us, carrying who was just created — the desk
         * was mid-booking when it left, and arriving back at an empty search
         * box would waste the one thing it now knows.
         */
        if (returnTo) {
            const back = new URL(returnTo, window.location.origin);
            back.searchParams.set('patient', String((result as { id: number }).id));

            navigate(`${back.pathname}${back.search}`);

            return;
        }

        navigate('/customers');
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

                {/* setValue and getValues let the PIN code fill in the address. */}
                <ConfigurableForm
                    fields={fields}
                    groups={GROUPS}
                    register={register}
                    errors={errors}
                    control={control}
                    setValue={setValue}
                    getValues={getValues}
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

                    <Button
                        variant="light"
                        onClick={() => navigate(returnTo ?? '/customers')}
                    >
                        Cancel
                    </Button>

                    {/*
                        Two endings on a new record, one on an edit. "Another"
                        makes no sense when there is only ever this one row to
                        change.
                    */}
                    {!isEdit && (
                        <Button
                            variant="light"
                            type="submit"
                            loading={isSubmitting && andAnother}
                            disabled={isSubmitting}
                            icon="ti ti-user-plus"
                            onClick={() => setAndAnother(true)}
                        >
                            Save &amp; add another
                        </Button>
                    )}

                    <Button
                        type="submit"
                        loading={isSubmitting && !andAnother}
                        disabled={isSubmitting}
                        icon="ti ti-device-floppy"
                        onClick={() => setAndAnother(false)}
                    >
                        {isEdit ? `Update ${label.singular}` : 'Save & back'}
                    </Button>
                </div>
            </form>
        </>
    );
}
