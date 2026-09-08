import type { Control, FieldErrors, FieldValues, UseFormRegister } from 'react-hook-form';
import {
    SelectField,
    SwitchField,
    TextField,
    TextareaField,
} from '@/shared/components/form/Fields';
import { DateField } from '@/shared/components/form/DateField';
import { MultiSelectField } from './MultiSelectField';
import type { ConfigurableField } from './types';

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
}: FieldInputProps<T>) {
    const name = inputNameFor(field) as never;

    const shared = {
        name,
        label: field.label,
        required: field.required,
        register,
        errors,
    };

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
