import type {
    Control,
    FieldErrors,
    FieldValues,
    Path,
    UseFormGetValues,
    UseFormRegister,
    UseFormSetValue,
} from 'react-hook-form';
import {
    SelectField,
    SwitchField,
    TextField,
    TextareaField,
} from '@/shared/components/form/Fields';
import { DateField } from '@/shared/components/form/DateField';
import type { PincodeFills } from '@/shared/geo/usePincodeAutofill';
import { MultiSelectField } from './MultiSelectField';
import { PincodeInput } from './PincodeInput';
import type { ConfigurableField, FieldLookup } from './types';

/**
 * Custom values live under the owning table's `custom_fields` jsonb column,
 * so their inputs are named for that path rather than the field key alone.
 */
export function inputNameFor(field: ConfigurableField): string {
    return field.is_custom ? `custom_fields.${field.key}` : field.key;
}

interface FieldInputProps<T extends FieldValues> {
    field: ConfigurableField;
    register: UseFormRegister<T>;
    errors: FieldErrors<T>;
    /**
     * Required, not optional.
     *
     * Selects, multi-selects and dates are all controlled now, which between
     * them is most of a schema — an optional `control` meant every one of them
     * carried a fallback for a case that never happens.
     */
    control: Control<T>;
    /**
     * Only needed by fields that fill other fields in, like a PIN code. A form
     * that does not pass them renders such a field as a plain input, so
     * opting in is a choice each form makes.
     */
    setValue?: UseFormSetValue<T>;
    getValues?: UseFormGetValues<T>;
    /** Keys of the fields on this form, so a lookup only fills what is there. */
    visibleKeys?: ReadonlySet<string>;
}

/**
 * One input, rendered from the field definition the server sent.
 *
 * Shared by every screen built this way, so a new data type is added here
 * once rather than in each form.
 */
export function FieldInput<T extends FieldValues>({
    field,
    register,
    errors,
    control,
    setValue,
    getValues,
    visibleKeys,
}: FieldInputProps<T>) {
    const name = inputNameFor(field) as never;

    const shared = {
        name,
        label: field.label,
        required: field.required,
        register,
        errors,
    };

    if (field.lookup?.type === 'pincode' && setValue && getValues) {
        return (
            <PincodeInput
                field={field}
                name={inputNameFor(field) as Path<T>}
                register={register}
                errors={errors}
                control={control}
                setValue={setValue}
                getValues={getValues}
                fills={fillsOnForm(field.lookup, visibleKeys)}
            />
        );
    }

    switch (field.type) {
        case 'boolean':
            return <SwitchField {...shared} description={field.label} />;

        case 'number':
            return (
                <TextField {...shared} type="number" placeholder={field.placeholder ?? undefined} />
            );

        case 'textarea':
            return (
                <TextareaField {...shared} rows={3} placeholder={field.placeholder ?? undefined} />
            );

        case 'select':
            return (
                <SelectField
                    {...shared}
                    control={control}
                    placeholder={field.placeholder ?? 'Choose one…'}
                    options={field.options ?? []}
                />
            );

        case 'multiselect':
            // Controlled, because the value is an array — which `register`
            // cannot express.
            return (
                <MultiSelectField
                    name={name}
                    label={field.label}
                    control={control}
                    options={field.options ?? []}
                    required={field.required}
                    placeholder={field.placeholder ?? 'Choose…'}
                    error={errors?.[field.key]?.message as string | undefined}
                />
            );

        case 'date':
            // Our own calendar rather than <input type="date">, which cannot
            // be styled and shows dd/mm/yyyy where a placeholder belongs. It
            // is controlled, so it needs `control` rather than `register`.
            return (
                <DateField
                    name={name}
                    label={field.label}
                    required={field.required}
                    control={control}
                    errors={errors}
                    placeholder={field.placeholder ?? 'Select a date'}
                />
            );

        case 'email':
            return (
                <TextField {...shared} type="email" placeholder={field.placeholder ?? undefined} />
            );

        default:
            return <TextField {...shared} placeholder={field.placeholder ?? undefined} />;
    }
}

/**
 * The lookup's targets, less any the organization has switched off.
 *
 * A hidden field is still in the form's values, so without this a hidden
 * district would be quietly filled and saved on every patient, which is the
 * opposite of what switching it off asked for.
 */
function fillsOnForm(lookup: FieldLookup, visibleKeys?: ReadonlySet<string>): PincodeFills {
    if (!visibleKeys) {
        return lookup.fills;
    }

    return Object.fromEntries(
        Object.entries(lookup.fills).filter(
            ([, target]) => typeof target === 'string' && visibleKeys.has(target),
        ),
    ) as PincodeFills;
}
