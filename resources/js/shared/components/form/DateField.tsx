import {
    useController,
    type Control,
    type FieldErrors,
    type FieldValues,
    type Path,
} from 'react-hook-form';
import { cn } from '@/shared/utils/cn';
import { DatePicker } from './DatePicker';

interface DateFieldProps<T extends FieldValues> {
    name: Path<T>;
    label: string;
    control: Control<T>;
    errors: FieldErrors<T>;
    required?: boolean;
    hint?: string;
    placeholder?: string;
    className?: string;
}

/**
 * DatePicker, wired to react-hook-form.
 *
 * The calendar itself lives in DatePicker and knows nothing about forms, so
 * a plain screen can use it too — the module binding dates on the platform
 * side do. This adds the label, the required marker and the error message,
 * which is all a form actually needs on top.
 *
 * Takes `control` rather than `register`, unlike the fields in Fields.tsx —
 * that is what react-hook-form prescribes for an input whose value is set
 * programmatically instead of by typing.
 */
export function DateField<T extends FieldValues>({
    name,
    label,
    control,
    errors,
    required,
    hint,
    placeholder,
    className,
}: DateFieldProps<T>) {
    const { field } = useController({ name, control });

    const errorMessage = (() => {
        const found = name
            .split('.')
            .reduce<any>((acc, part) => (acc ? acc[part] : undefined), errors);

        return typeof found?.message === 'string' ? found.message : undefined;
    })();

    return (
        <div className={cn('mb-3', className)}>
            <label className="form-label" htmlFor={name}>
                {label}
                {required && <span className="text-danger ms-1">*</span>}
            </label>

            <DatePicker
                id={name}
                label={label}
                value={(field.value as string) ?? ''}
                onChange={field.onChange}
                placeholder={placeholder}
                invalid={Boolean(errorMessage)}
            />

            {errorMessage ? (
                <div className="invalid-feedback d-block">{errorMessage}</div>
            ) : (
                hint && <small className="text-muted d-block mt-1">{hint}</small>
            )}
        </div>
    );
}
