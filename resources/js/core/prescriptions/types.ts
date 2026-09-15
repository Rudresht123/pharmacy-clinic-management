/**
 * A visit's prescription, as the API returns it.
 *
 * Quantities are in the medicine's base unit (a tablet, not a strip). The
 * snapshot fields — name, generic, strength, form — are what was chosen, as
 * it read then; later catalogue edits never reach them.
 */

export type PrescriptionStatus =
    | 'draft'
    | 'issued'
    | 'partially_dispensed'
    | 'dispensed'
    | 'cancelled'
    | 'expired';

export type Frequency = 'od' | 'bd' | 'tds' | 'qid' | 'hs' | 'sos' | 'stat' | 'weekly' | 'custom';

export type FoodTiming = 'before_food' | 'after_food' | 'with_food' | 'empty_stomach' | 'any';

export type DurationUnit = 'days' | 'weeks' | 'months' | 'continuous';

/** The line written out, in the consultation's old shape. */
export interface WrittenLine {
    drug: string;
    dose: string | null;
    frequency: string | null;
    duration: string | null;
    notes: string | null;
}

export interface PrescriptionItem {
    id: number;
    medicine_id: number | null;
    is_unlisted: boolean;
    medicine_name: string;
    generic_name: string | null;
    strength: string | null;
    dosage_form: string | null;
    base_unit?: string | null;
    dose_amount: number | null;
    dose_unit: string | null;
    morning: number | null;
    afternoon: number | null;
    evening: number | null;
    night: number | null;
    frequency: Frequency | null;
    food_timing: FoodTiming | null;
    duration: number | null;
    duration_unit: DurationUnit | null;
    route: string | null;
    prescribed_quantity: number | null;
    dispensed_quantity: number;
    status: string;
    instructions: string | null;
    sort_order: number;
    line: WrittenLine;
}

export interface Prescription {
    id: number;
    prescription_number: string;
    status: PrescriptionStatus;
    is_draft: boolean;
    is_legacy: boolean;
    appointment_id: number;
    consultation_id: number | null;
    location_id: number;
    location_name?: string | null;
    customer_id: number;
    customer_name?: string | null;
    doctor_id: number;
    doctor_name?: string | null;
    prescription_date: string | null;
    valid_until: string | null;
    clinical_notes: string | null;
    issued_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    items?: PrescriptionItem[];
}

export type AvailabilityStatus =
    | 'available'
    | 'low_stock'
    | 'out_of_stock'
    | 'expired_only'
    | 'not_stocked';

/** What the visit's store holds of one medicine. A warning, never a refusal. */
export interface Availability {
    medicine_id: number;
    status: AvailabilityStatus;
    /** Active and not past expiry, in base units. */
    dispensable: number;
    expired: number;
    nearest_expiry: string | null;
    reorder_level: number | null;
}

/** GET appointments/{id}/prescription */
export interface VisitPrescription {
    appointment_id: number;
    prescription: Prescription | null;
    /** Where this branch dispenses from; null when it runs no pharmacy. */
    store: { id: number; name: string } | null;
    availability: Availability[];
}

/** One line as the editor holds it, and as the API takes it. */
export interface LineDraft {
    /** The saved line's id; absent for a line added since the last save. */
    id?: number;
    /** A key for React that survives a save which gives the line an id. */
    key: string;
    medicine_id: number | null;
    /** The catalogue name when chosen, the doctor's own words when unlisted. */
    medicine_name: string;
    base_unit: string | null;
    dose_amount: number | null;
    dose_unit: string;
    morning: number | null;
    afternoon: number | null;
    evening: number | null;
    night: number | null;
    frequency: Frequency | '';
    food_timing: FoodTiming | '';
    duration: number | null;
    duration_unit: DurationUnit;
    prescribed_quantity: number | null;
    instructions: string;
}

export const FREQUENCY_LABELS: Record<Frequency, string> = {
    od: 'Once a day',
    bd: 'Twice a day',
    tds: 'Three times a day',
    qid: 'Four times a day',
    hs: 'At bedtime',
    sos: 'When needed',
    stat: 'Once, straight away',
    weekly: 'Once a week',
    custom: 'As directed',
};

export const FOOD_LABELS: Record<FoodTiming, string> = {
    before_food: 'Before food',
    after_food: 'After food',
    with_food: 'With food',
    empty_stomach: 'Empty stomach',
    any: 'Any time',
};

export const DURATION_LABELS: Record<DurationUnit, string> = {
    days: 'days',
    weeks: 'weeks',
    months: 'months',
    continuous: 'continuous',
};

export const STATUS_LABELS: Record<PrescriptionStatus, string> = {
    draft: 'Draft',
    issued: 'Issued',
    partially_dispensed: 'Partly dispensed',
    dispensed: 'Dispensed',
    cancelled: 'Cancelled',
    expired: 'Expired',
};

export const AVAILABILITY_LABELS: Record<AvailabilityStatus, string> = {
    available: 'In stock',
    low_stock: 'Low stock',
    out_of_stock: 'Out of stock',
    expired_only: 'Only expired stock',
    not_stocked: 'Not stocked here',
};
