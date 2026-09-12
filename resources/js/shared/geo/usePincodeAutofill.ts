import { useCallback, useEffect, useRef } from 'react';
import {
    useWatch,
    type Control,
    type FieldValues,
    type Path,
    type PathValue,
    type UseFormGetValues,
    type UseFormSetValue,
} from 'react-hook-form';
import { isPincode, usePincodeLookup, type PincodePlace } from './pincode';

/**
 * Which form field each part of India Post's answer goes into.
 *
 * Every part is optional: a form with no district field simply leaves
 * `district` out, and that part of the answer is not written anywhere.
 */
export interface PincodeFills {
    area?: string;
    district?: string;
    state?: string;
    country?: string;
}

interface Options<T extends FieldValues> {
    control: Control<T>;
    setValue: UseFormSetValue<T>;
    getValues: UseFormGetValues<T>;
    /** The field the PIN code is typed into. */
    pincode: Path<T>;
    fills: PincodeFills;
}

export interface PincodeAutofill {
    pincode: string;
    /** Six valid digits, so a lookup is (or was) under way. */
    isComplete: boolean;
    place: PincodePlace | null;
    isLoading: boolean;
    /** India Post answered: there is no such code. */
    notFound: boolean;
    /** India Post could not be asked. The address has to be typed by hand. */
    failed: boolean;
    /** Puts one of the code's areas into the area field. */
    chooseArea: (name: string) => void;
}

/**
 * Fills an address in from its PIN code, in any react-hook-form.
 *
 * Watches the PIN code field; once it holds six valid digits the code is looked
 * up and the district, state and country are written into their fields. The
 * area is written only when the code covers exactly one: a city code covers a
 * dozen post offices, and choosing among them is the person's call, which is
 * what `chooseArea` is for.
 *
 * It never overwrites something somebody typed. A field is filled when it is
 * empty, or when it still holds what this hook put there, so changing the PIN
 * code updates the address, while a state corrected by hand stays corrected.
 * An existing record opened for editing keeps every value it was saved with.
 *
 * Not tied to any one screen. The configurable form uses it through
 * PincodeInput, and any hand-written form can call it directly.
 */
export function usePincodeAutofill<T extends FieldValues>({
    control,
    setValue,
    getValues,
    pincode,
    fills,
}: Options<T>): PincodeAutofill {
    const value = useWatch({ control, name: pincode }) as unknown;
    const pin = typeof value === 'string' ? value.trim() : '';

    const lookup = usePincodeLookup(pin);

    // What this hook last wrote into each field, so it can tell its own value
    // from one the user typed.
    const written = useRef<Record<string, string>>({});

    // The code the current values came from, so one answer is applied once.
    const appliedFor = useRef<string | null>(null);

    const put = useCallback(
        (field: string | undefined, next: string) => {
            if (!field || !next) {
                return;
            }

            const current = String(getValues(field as Path<T>) ?? '').trim();

            if (current !== '' && current !== written.current[field]) {
                return;
            }

            setValue(field as Path<T>, next as PathValue<T, Path<T>>, { shouldDirty: true });
            written.current[field] = next;
        },
        [getValues, setValue],
    );

    const place = lookup.data ?? null;

    useEffect(() => {
        if (!place || appliedFor.current === place.pincode) {
            return;
        }

        appliedFor.current = place.pincode;

        put(fills.district, place.district);
        put(fills.state, place.state);
        put(fills.country, place.country);

        if (place.areas.length === 1) {
            put(fills.area, place.areas[0].name);
        }
    }, [place, fills.district, fills.state, fills.country, fills.area, put]);

    const chooseArea = useCallback(
        (name: string) => {
            if (!fills.area) {
                return;
            }

            // Chosen from the list, so it is the answer: set even over a value
            // typed earlier, and remembered as this hook's own.
            setValue(fills.area as Path<T>, name as PathValue<T, Path<T>>, { shouldDirty: true });
            written.current[fills.area] = name;
        },
        [fills.area, setValue],
    );

    return {
        pincode: pin,
        isComplete: isPincode(pin),
        place,
        isLoading: lookup.isFetching,
        notFound: lookup.isSuccess && lookup.data === null,
        failed: lookup.isError,
        chooseArea,
    };
}
