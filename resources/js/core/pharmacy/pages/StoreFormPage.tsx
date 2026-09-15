import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import {
    FormError,
    SelectField,
    SwitchField,
    TextField,
    TextareaField,
} from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { storesHooks, useStoreFormOptions } from '../api';
import { STORE_TYPE_LABELS } from '../types';

// A `type`, not an `interface` — only type aliases get the implicit index
// signature the resource API's payload requires.
type StoreFormValues = Record<string, unknown>;

/**
 * Adding or editing a pharmacy store.
 *
 * The branch is chosen once. Moving a store — with its stock, from the next
 * phase — is a transfer, so on an edit the branch is shown, not offered.
 */
export default function StoreFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);

    const { data: options, isLoading: optionsLoading } = useStoreFormOptions();
    const { data: store, isLoading: recordLoading } = storesHooks.useDetail(id);

    const create = storesHooks.useCreate();
    const update = storesHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<StoreFormValues>({
        defaultValues: { store_type: 'opd_counter', is_active: true, is_default: false },
    });

    useEffect(() => {
        if (!store) {
            return;
        }

        reset({
            name: store.name,
            code: store.code,
            store_type: store.store_type,
            pharmacist_user_id: store.pharmacist_user_id ?? '',
            address: store.address ?? '',
            phone: store.phone ?? '',
            drug_license_no: store.drug_license_no ?? '',
            drug_license_expiry_date: store.drug_license_expiry_date ?? '',
            is_active: store.is_active,
            is_default: store.is_default,
        });
    }, [store, reset]);

    const onSubmit = handleSubmit(async (values) => {
        // The branch is fixed once a store exists; the server refuses it.
        const { location_id: _branch, ...rest } = values;
        const payload = isEdit ? rest : values;

        const result = await submit(payload, async () =>
            isEdit && id ? update.mutateAsync({ id, payload }) : create.mutateAsync(payload),
        );

        if (result) {
            navigate('/pharmacy/stores');
        }
    });

    if (optionsLoading || (isEdit && recordLoading)) {
        return <LoadingBlock label="Loading…" />;
    }

    const branches = options?.branches ?? [];

    return (
        <>
            <PageHeader
                title={isEdit ? 'Edit Store' : 'Add Store'}
                icon={isEdit ? 'ti ti-pencil' : 'ti ti-building-warehouse'}
                tone={isEdit ? 'amber' : 'teal'}
                crumbs={[
                    { label: 'Pharmacy' },
                    { label: 'Stores', to: '/pharmacy/stores' },
                    { label: isEdit ? 'Edit' : 'Add' },
                ]}
            />

            <form onSubmit={onSubmit} noValidate>
                <FormError message={errors.root?.message} />

                <p className="form-legend">
                    <span className="req">*</span> Required. Everything else can be filled in later.
                </p>

                <div className="row g-3">
                    <div className="col-lg-8">
                        <Card
                            title="The Store"
                            icon="ti ti-building-warehouse"
                            description="Which branch it belongs to, and what it is called there."
                        >
                            {isEdit ? (
                                <p className="mb-3">
                                    <span className="form-label d-block">Branch</span>
                                    <b>{store?.location_name ?? '—'}</b>
                                    <small className="text-muted d-block mt-1">
                                        A store stays at its branch. To move stock, transfer it.
                                    </small>
                                </p>
                            ) : (
                                <SelectField
                                    name="location_id"
                                    label="Branch"
                                    control={control}
                                    errors={errors}
                                    required
                                    options={branches.map((branch) => ({
                                        value: branch.id,
                                        label: branch.name,
                                    }))}
                                    placeholder="Choose a branch"
                                    hint={
                                        branches.length === 0
                                            ? 'You have no active branches to add a store at.'
                                            : undefined
                                    }
                                />
                            )}

                            <div className="row">
                                <TextField
                                    className="col-md-7"
                                    name="name"
                                    label="Name"
                                    register={register}
                                    errors={errors}
                                    required
                                    placeholder="OPD Counter"
                                />
                                <TextField
                                    className="col-md-5"
                                    name="code"
                                    label="Code"
                                    register={register}
                                    errors={errors}
                                    required
                                    placeholder="NOI-OPD"
                                    hint="Unique across the organization."
                                />
                            </div>

                            <div className="row">
                                <SelectField
                                    className="col-md-6"
                                    name="store_type"
                                    label="Type"
                                    control={control}
                                    errors={errors}
                                    required
                                    options={(options?.store_types ?? Object.keys(STORE_TYPE_LABELS)).map(
                                        (type) => ({
                                            value: type,
                                            label: STORE_TYPE_LABELS[type] ?? type,
                                        }),
                                    )}
                                />
                                <SelectField
                                    className="col-md-6"
                                    name="pharmacist_user_id"
                                    label="Pharmacist in charge"
                                    control={control}
                                    errors={errors}
                                    options={(options?.pharmacists ?? []).map((person) => ({
                                        value: person.id,
                                        label: person.name,
                                    }))}
                                    placeholder="Nobody yet"
                                />
                            </div>
                        </Card>

                        <Card
                            title="Contact"
                            icon="ti ti-address-book"
                            description="Leave blank to use the branch's own address and phone."
                        >
                            <TextField
                                name="phone"
                                label="Phone"
                                register={register}
                                errors={errors}
                                placeholder="98765 43210"
                            />
                            <TextareaField
                                name="address"
                                label="Address"
                                register={register}
                                errors={errors}
                                rows={3}
                            />
                        </Card>
                    </div>

                    <div className="col-lg-4">
                        <Card
                            title="Use"
                            icon="ti ti-toggle-right"
                            description="Whether it is in use, and whether doctors see stock from it."
                        >
                            <SwitchField
                                name="is_active"
                                label="Active"
                                description="In use"
                                register={register}
                                errors={errors}
                            />

                            {store?.is_default ? (
                                <p className="form-hint mb-0">
                                    This is its branch&rsquo;s default store — the one doctors see
                                    stock from. To move that, make another store the default.
                                </p>
                            ) : (
                                <SwitchField
                                    name="is_default"
                                    label="Default"
                                    description="Default store for its branch"
                                    register={register}
                                    errors={errors}
                                    hint="The first store at a branch is its default automatically."
                                />
                            )}
                        </Card>

                        <Card
                            title="Drug Licence"
                            icon="ti ti-license"
                            description="Only if this store holds its own. Otherwise the branch's applies."
                        >
                            <TextField
                                name="drug_license_no"
                                label="Licence number"
                                register={register}
                                errors={errors}
                                hint={
                                    store?.licence && !store.licence.own && store.licence.number
                                        ? `Using the branch's: ${store.licence.number}`
                                        : undefined
                                }
                            />
                            <TextField
                                name="drug_license_expiry_date"
                                label="Expires on"
                                type="date"
                                register={register}
                                errors={errors}
                            />
                        </Card>
                    </div>
                </div>

                <div className="form-actions">
                    {/* Only on edit: a record being created has no history yet. */}
                    {isEdit && store && (
                        <RecordHistory entity="PharmacyStore" id={store.id} label={store.name} />
                    )}

                    <Button variant="light" onClick={() => navigate('/pharmacy/stores')}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {isEdit ? 'Update Store' : 'Add Store'}
                    </Button>
                </div>
            </form>
        </>
    );
}
