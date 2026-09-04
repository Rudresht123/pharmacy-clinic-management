import type { Control, FieldErrors, FieldValues, UseFormRegister } from 'react-hook-form';
import {
    SelectField,
    SwitchField,
    TextField,
    TextareaField,
} from '@/shared/components/form/Fields';
import { DateField } from '@/shared/components/form/DateField';
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
    /** Only needed when the schema can contain a date. */
    control?: Control<T>;
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
                    placeholder={field.placeholder ?? 'Choose one…'}
                    options={field.options ?? []}
                />
            );

        case 'date':
            // Our own calendar rather than <input type="date">, which cannot
            // be styled and shows dd/mm/yyyy where a placeholder belongs. It
            // is controlled, so it needs `control` rather than `register`.
            return control ? (
                <DateField
                    name={name}
                    label={field.label}
                    required={field.required}
                    control={control}
                    errors={errors}
                    placeholder={field.placeholder ?? 'Select a date'}
                />
            ) : (
                <TextField {...shared} type="date" />
            );

        case 'email':
            return (
                <TextField {...shared} type="email" placeholder={field.placeholder ?? undefined} />
            );

        default:
            return <TextField {...shared} placeholder={field.placeholder ?? undefined} />;
    }
}
