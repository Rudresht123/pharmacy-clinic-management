import { useState } from 'react';
import {
    useWatch,
    type Control,
    type FieldErrors,
    type FieldValues,
    type Path,
    type UseFormGetValues,
    type UseFormRegister,
    type UseFormSetValue,
} from 'react-hook-form';
import { TextField } from '@/shared/components/form/Fields';
import { usePincodeAutofill, type PincodeAutofill, type PincodeFills } from '@/shared/geo/usePincodeAutofill';
import type { ConfigurableField } from './types';

interface PincodeInputProps<T extends FieldValues> {
    field: ConfigurableField;
    name: Path<T>;
    register: UseFormRegister<T>;
    errors: FieldErrors<T>;
    control: Control<T>;
    setValue: UseFormSetValue<T>;
    getValues: UseFormGetValues<T>;
    /** Only the targets that are actually on this form. */
    fills: PincodeFills;
}

/** More than this and the list is folded behind "show all". */
const AREAS_SHOWN = 12;

/**
 * A PIN code field that fills the rest of the address in.
 *
 * Rendered by FieldInput for any configured field the server marks with a
 * `pincode` lookup, so every form built from field settings gets it by
 * adding that key, with no change here. The filling itself is
 * usePincodeAutofill; this is only the field, a line saying what happened,
 * and the areas to choose from.
 */
export function PincodeInput<T extends FieldValues>({
    field,
    name,
    register,
    errors,
    control,
    setValue,
    getValues,
    fills,
}: PincodeInputProps<T>) {
    const autofill = usePincodeAutofill({ control, setValue, getValues, pincode: name, fills });

    // Watched so the chosen area can be marked. Falls back to the PIN field
    // when there is no area target, which is then simply never matched.
    const chosenArea = useWatch({ control, name: (fills.area ?? name) as Path<T> }) as unknown;

    return (
        <div className="pincode-input">
            <TextField
                name={name}
                label={field.label}
                required={field.required}
                register={register}
                errors={errors}
                // "tel" rather than "number": the phone keypad, without the
                // spinner arrows and the silent loss of a leading digit.
                type="tel"
                autoComplete="postal-code"
                placeholder={field.placeholder ?? undefined}
            />

            <Status autofill={autofill} />

            {fills.area && autofill.place && autofill.place.areas.length > 1 && (
                <AreaPicker
                    autofill={autofill}
                    chosen={typeof chosenArea === 'string' ? chosenArea : ''}
                />
            )}
        </div>
    );
}

/** One line under the field saying what the lookup found, or why not. */
function Status({ autofill }: { autofill: PincodeAutofill }) {
    if (!autofill.isComplete) {
        return null;
    }

    if (autofill.isLoading) {
        return (
            <p className="pincode-status small text-muted d-flex align-items-center gap-2 mt-n2 mb-3">
                <span className="spinner-border spinner-border-sm" aria-hidden="true" />
                Looking up {autofill.pincode}…
            </p>
        );
    }

    if (autofill.place) {
        const { district, state, country } = autofill.place;

        return (
            <p className="pincode-status small text-success d-flex align-items-center gap-1 mt-n2 mb-3">
                <i className="ti ti-circle-check" aria-hidden="true" />
                {[district, state, country].filter(Boolean).join(', ')}
            </p>
        );
    }

    if (autofill.notFound) {
        return (
            <p className="pincode-status small text-danger d-flex align-items-center gap-1 mt-n2 mb-3">
                <i className="ti ti-alert-circle" aria-hidden="true" />
                No post office has this PIN code. Check the digits.
            </p>
        );
    }

    if (autofill.failed) {
        return (
            <p className="pincode-status small text-warning d-flex align-items-center gap-1 mt-n2 mb-3">
                <i className="ti ti-cloud-off" aria-hidden="true" />
                Couldn't look this PIN code up right now. Please fill in the address by hand.
            </p>
        );
    }

    return null;
}

/**
 * The post offices under the code, to pick the locality from.
 *
 * A city PIN code covers many (201301 is most of Noida), so the district is
 * certain but the area is a choice, and guessing it would put a patient in
 * the wrong sector.
 */
function AreaPicker({ autofill, chosen }: { autofill: PincodeAutofill; chosen: string }) {
    const [showAll, setShowAll] = useState(false);
    const areas = autofill.place?.areas ?? [];
    const visible = showAll ? areas : areas.slice(0, AREAS_SHOWN);

    return (
        <div className="pincode-areas mb-3">
            <p className="small text-muted mb-2">Choose the area:</p>

            <div className="d-flex flex-wrap gap-2">
                {visible.map((area) => {
                    const selected = area.name === chosen.trim();

                    return (
                        <button
                            key={area.name}
                            type="button"
                            className={`btn btn-sm ${selected ? 'btn-primary' : 'btn-outline-secondary'}`}
                            aria-pressed={selected}
                            onClick={() => autofill.chooseArea(area.name)}
                        >
                            {area.name}
                        </button>
                    );
                })}

                {areas.length > AREAS_SHOWN && (
                    <button
                        type="button"
                        className="btn btn-sm btn-link"
                        onClick={() => setShowAll((open) => !open)}
                    >
                        {showAll ? 'Show fewer' : `Show all ${areas.length}`}
                    </button>
                )}
            </div>
        </div>
    );
}
