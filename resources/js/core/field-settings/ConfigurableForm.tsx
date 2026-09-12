import type { ReactNode } from 'react';
import type {
    Control,
    FieldErrors,
    FieldValues,
    UseFormGetValues,
    UseFormRegister,
    UseFormSetValue,
} from 'react-hook-form';
import { Card } from '@/shared/components/ui/Card';
import { FieldInput } from './FieldInput';
import type { ConfigurableField } from './types';

export interface FieldGroup {
    /** Matches ConfigurableField.group. */
    key: string;
    title: string;
    icon: string;
    description: string;
    /** Renders in the narrow right-hand rail rather than the main column. */
    rail?: boolean;
}

interface ConfigurableFormProps<T extends FieldValues> {
    fields: ConfigurableField[] | undefined;
    groups: FieldGroup[];
    register: UseFormRegister<T>;
    errors: FieldErrors<T>;
    control: Control<T>;
    /**
     * Pass both to let fields fill each other in, like a PIN code filling the
     * district and state. Without them those fields render as plain inputs.
     */
    setValue?: UseFormSetValue<T>;
    getValues?: UseFormGetValues<T>;
    /** Extra content appended inside a particular group's card. */
    extras?: Record<string, ReactNode>;
}

/**
 * The body of a form built from an entity's configured fields.
 *
 * Every configurable screen lays its fields out the same way — grouped into
 * cards, split between a main column and a rail — so that arrangement lives
 * here once. A page supplies its group titles and anything that is not a
 * configurable field (a password pair, say) through `extras`.
 *
 * A group whose fields are all switched off renders nothing rather than
 * leaving an empty card behind.
 */
export function ConfigurableForm<T extends FieldValues>({
    fields,
    groups,
    register,
    errors,
    control,
    setValue,
    getValues,
    extras,
}: ConfigurableFormProps<T>) {
    const visible = (fields ?? []).filter((field) => field.show_in_form);

    // Which fields are on this form, so a lookup fills only those.
    const visibleKeys = new Set(visible.map((field) => field.key));

    const populated = groups.filter(
        (group) =>
            visible.some((field) => field.group === group.key) || Boolean(extras?.[group.key]),
    );

    function renderGroup(group: FieldGroup) {
        return (
            <Card
                key={group.key}
                title={group.title}
                icon={group.icon}
                description={group.description}
            >
                {visible
                    .filter((field) => field.group === group.key)
                    .map((field) => (
                        <FieldInput
                            key={field.key}
                            field={field}
                            register={register}
                            errors={errors}
                            control={control}
                            setValue={setValue}
                            getValues={getValues}
                            visibleKeys={visibleKeys}
                        />
                    ))}

                {extras?.[group.key]}
            </Card>
        );
    }

    return (
        <div className="row g-3">
            <div className="col-lg-8 form-column">
                {populated.filter((group) => !group.rail).map(renderGroup)}
            </div>

            <div className="col-lg-4 form-rail">
                {populated.filter((group) => group.rail).map(renderGroup)}
            </div>
        </div>
    );
}
