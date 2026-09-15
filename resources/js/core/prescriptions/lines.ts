import type { DurationUnit, Frequency, LineDraft, PrescriptionItem } from './types';

/** The pattern slots, in the order they are written: 1–0–0–1. */
export const SLOTS = ['morning', 'afternoon', 'evening', 'night'] as const;

/** Doses a day. Absent for `sos` and `custom`, and `stat` is one dose in all. */
const PER_DAY: Partial<Record<Frequency, number>> = { od: 1, bd: 2, tds: 3, qid: 4, hs: 1, weekly: 1 / 7 };

const DAYS_IN: Partial<Record<DurationUnit, number>> = { days: 1, weeks: 7, months: 30 };

/**
 * How many base units a line adds up to — the same sum the server makes
 * (PrescriptionItem::suggestQuantity), shown as the quantity's placeholder.
 *
 * Only when the dose is counted in the medicine's own unit: 5 ml of a syrup
 * sold by the bottle is not a count of bottles.
 */
export function suggestQuantity(line: LineDraft): number | null {
    const unit = line.dose_unit.trim().toLowerCase();

    if (unit !== '' && unit.replace(/s+$/, '') !== (line.base_unit ?? '').toLowerCase()) {
        return null;
    }

    const dose = line.dose_amount || 1;

    if (line.frequency === 'stat') {
        return Math.ceil(dose);
    }

    const per = DAYS_IN[line.duration_unit];

    if (!per || !line.duration) {
        return null;
    }

    const days = per * line.duration;

    // A written pattern is itself the dose at each time of day.
    const pattern = SLOTS.reduce((sum, slot) => sum + (line[slot] ?? 0), 0);
    const perDay =
        pattern > 0
            ? pattern
            : line.frequency && PER_DAY[line.frequency] !== undefined
              ? dose * (PER_DAY[line.frequency] as number)
              : null;

    return perDay === null ? null : Math.max(1, Math.ceil(perDay * days));
}

let fresh = 0;

export function blankLine(): LineDraft {
    fresh += 1;

    return {
        key: `new-${fresh}`,
        medicine_id: null,
        medicine_name: '',
        base_unit: null,
        dose_amount: null,
        dose_unit: '',
        morning: null,
        afternoon: null,
        evening: null,
        night: null,
        frequency: '',
        food_timing: '',
        duration: null,
        duration_unit: 'days',
        prescribed_quantity: null,
        instructions: '',
    };
}

/**
 * A saved line, back into the editor.
 *
 * A quantity the server worked out is held as "not typed", so changing the
 * duration afterwards changes it too, instead of freezing the first sum.
 */
export function toDraft(item: PrescriptionItem): LineDraft {
    const line: LineDraft = {
        id: item.id,
        key: `rx-${item.id}`,
        medicine_id: item.medicine_id,
        medicine_name: item.medicine_name,
        base_unit: item.base_unit ?? null,
        dose_amount: item.dose_amount,
        dose_unit: item.dose_unit ?? '',
        morning: item.morning,
        afternoon: item.afternoon,
        evening: item.evening,
        night: item.night,
        frequency: item.frequency ?? '',
        food_timing: item.food_timing ?? '',
        duration: item.duration,
        duration_unit: item.duration_unit ?? 'days',
        prescribed_quantity: item.prescribed_quantity,
        instructions: item.instructions ?? '',
    };

    if (line.medicine_id !== null && line.prescribed_quantity === suggestQuantity({ ...line, prescribed_quantity: null })) {
        line.prescribed_quantity = null;
    }

    return line;
}

/** One line, as the API takes it. */
export function toPayload(line: LineDraft) {
    const continuous = line.duration_unit === 'continuous';

    return {
        ...(line.id ? { id: line.id } : {}),
        medicine_id: line.medicine_id,
        medicine_name: line.medicine_name.trim() || null,
        dose_amount: line.dose_amount,
        dose_unit: line.dose_unit.trim() || null,
        morning: line.morning,
        afternoon: line.afternoon,
        evening: line.evening,
        night: line.night,
        frequency: line.frequency || null,
        food_timing: line.food_timing || null,
        duration: continuous ? null : line.duration,
        duration_unit: continuous ? 'continuous' : line.duration ? line.duration_unit : null,
        prescribed_quantity: line.prescribed_quantity,
        instructions: line.instructions.trim() || null,
    };
}

/**
 * What finishing a consultation does to its prescription, per open editor.
 *
 * The editor registers itself here; the consultation panel's "Complete"
 * asks it to save and issue the draft first, so a prescription is never
 * left unsigned by a visit that has ended.
 */
const flushers = new Map<number, () => Promise<boolean>>();

export function registerFlush(appointmentId: number, flush: () => Promise<boolean>): () => void {
    flushers.set(appointmentId, flush);

    return () => {
        if (flushers.get(appointmentId) === flush) flushers.delete(appointmentId);
    };
}

/** True when it is safe to finish the visit. */
export async function flushPrescription(appointmentId: number): Promise<boolean> {
    const flush = flushers.get(appointmentId);

    return flush ? flush() : true;
}
