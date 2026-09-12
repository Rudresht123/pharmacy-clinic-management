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
import { medicinesHooks, useMedicineFields } from '../api';

// A `type`, not an `interface` — only type aliases get the implicit index
// signature the resource API's payload requires.
type MedicineFormValues = Record<string, unknown>;

const GROUPS: FieldGroup[] = [
    {
        key: 'identity',
        title: 'What It Is',
        icon: 'ti ti-pill',
        description:
            'The generic, the brand, the strength and the form. Together they make a medicine distinct.',
    },
    {
        key: 'clinical',
        title: 'Prescribing',
        icon: 'ti ti-stethoscope',
        description: 'How it is normally taken, and whether it needs a prescription.',
    },
    {
        key: 'stock',
        title: 'Stock Unit',
        icon: 'ti ti-box',
        description: 'What stock is counted and dispensed in, and how many come in a pack.',
        rail: true,
    },
    {
        key: 'supply',
        title: 'Supply',
        icon: 'ti ti-truck',
        description: 'Who makes it, and how the catalogue groups it.',
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
 * Adding or editing a medicine.
 *
 * A duplicate is refused by the server with the name of the medicine that is
 * already there, shown against the generic name — so somebody about to add
 * "Paracetamol 500mg" a second time is sent to the one that exists.
 */
export default function MedicineFormPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);
    const label = useEntityLabel('medicine');

    const { data: fields, isLoading: fieldsLoading } = useMedicineFields();
    const { data: medicine, isLoading: recordLoading } = medicinesHooks.useDetail(id);

    const create = medicinesHooks.useCreate();
    const update = medicinesHooks.useUpdate();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<MedicineFormValues>({
        defaultValues: { is_active: true, prescription_required: true, pack_size: 1 },
    });

    useEffect(() => {
        if (!medicine) {
            return;
        }

        reset({
            medicine_code: medicine.medicine_code ?? '',
            generic_name: medicine.generic_name,
            brand_name: medicine.brand_name ?? '',
            strength: medicine.strength ?? '',
            dosage_form: medicine.dosage_form,
            route: medicine.route ?? '',
            base_unit: medicine.base_unit,
            pack_size: medicine.pack_size,
            manufacturer: medicine.manufacturer ?? '',
            category: medicine.category ?? '',
            schedule: medicine.schedule ?? '',
            prescription_required: medicine.prescription_required,
            description: medicine.description ?? '',
            is_active: medicine.is_active,
            custom_fields: medicine.custom_fields ?? {},
        });
    }, [medicine, reset]);

    const onSubmit = handleSubmit(async (payload) => {
        const result = await submit(payload, async () =>
            isEdit && id ? update.mutateAsync({ id, payload }) : create.mutateAsync(payload),
        );

        if (result) {
            navigate('/medicines');
        }
    });

    if (fieldsLoading || (isEdit && recordLoading)) {
        return <LoadingBlock label="Loading…" />;
    }

    const singular = label.singular || 'Medicine';

    return (
        <>
            <PageHeader
                title={`${isEdit ? 'Edit' : 'Add'} ${singular}`}
                icon={isEdit ? 'ti ti-pencil' : 'ti ti-pill'}
                tone={isEdit ? 'amber' : 'emerald'}
                crumbs={[
                    { label: label.plural || 'Medicines', to: '/medicines' },
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
                    {isEdit && medicine && (
                        <RecordHistory
                            entity="Medicine"
                            id={medicine.id}
                            label={medicine.display_name}
                        />
                    )}

                    <span className="form-actions-note">
                        Stock is counted in the base unit, so a strip of 10 is 10 tablets.
                    </span>

                    <Button variant="light" onClick={() => navigate('/medicines')}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {`${isEdit ? 'Update' : 'Add'} ${singular}`}
                    </Button>
                </div>
            </form>
        </>
    );
}
