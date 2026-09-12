import type { Customer } from '../types';

/** Whatever was measured. Every key optional; most visits record two. */
export type Vitals = { [key: string]: number | null };

export interface PrescriptionLine {
    drug: string;
    dose?: string | null;
    frequency?: string | null;
    duration?: string | null;
    notes?: string | null;
}

export interface InvestigationLine {
    test: string;
    notes?: string | null;
}

export interface Consultation {
    chief_complaint: string | null;
    diagnoses: string[];
    advice: string | null;
    notes: string | null;
    follow_up_days: number | null;
    vitals: Vitals;
    prescription: PrescriptionLine[];
    investigations: InvestigationLine[];
}

export interface Visit {
    id: number;
    date: string | null;
    status: string;
    type: string;
    slot_at: string | null;
    token_no: number | null;
    doctor_name: string | null;
    doctor_specialisation: string | null;
    location_name: string | null;
    /** Null when nobody wrote the visit up. */
    consultation: Consultation | null;
}

/** GET /tenant/customers/{id}/visits */
export interface PatientRecord {
    summary: {
        total_visits: number;
        seen_count: number;
        member_since: string | null;
        last_visit: { on: string | null; doctor_name: string | null } | null;
        next_visit: { on: string | null; at: string | null; doctor_name: string | null } | null;
        vitals: { on: string | null; values: Vitals } | null;
        medications: { on: string | null; lines: PrescriptionLine[] } | null;
        diagnoses: string[];
    };
    visits: Visit[];
}

/*
|--------------------------------------------------------------------------
| The sections of the file
|--------------------------------------------------------------------------
|
| One list for the side menu and the tabs, so the two can never disagree.
| Sections with nothing behind them yet (billing, documents) are here on
| purpose: the shape of the record is decided now, and each fills in when
| its module exists without the page being redrawn.
*/

export type Section =
    | 'overview'
    | 'visits'
    | 'prescriptions'
    | 'labs'
    | 'billing'
    | 'documents'
    | 'notes'
    | 'services'
    | 'vitals'
    | 'allergies'
    | 'settings';

export interface SectionDef {
    value: Section;
    /** In the side menu, where there is room. */
    label: string;
    /** On the tab strip, where there is not. */
    short: string;
    icon: string;
}

export const SECTIONS: readonly SectionDef[] = [
    { value: 'overview', label: 'Overview', short: 'Overview', icon: 'ti ti-layout-dashboard' },
    { value: 'visits', label: 'Visit History', short: 'Visit History', icon: 'ti ti-calendar-event' },
    { value: 'prescriptions', label: 'Prescriptions', short: 'Prescriptions', icon: 'ti ti-prescription' },
    { value: 'labs', label: 'Lab Reports', short: 'Lab Reports', icon: 'ti ti-flask' },
    { value: 'billing', label: 'Billing & Payments', short: 'Billing', icon: 'ti ti-receipt' },
    { value: 'documents', label: 'Files & Documents', short: 'Documents', icon: 'ti ti-files' },
    { value: 'notes', label: 'Notes', short: 'Notes', icon: 'ti ti-notes' },
    { value: 'services', label: 'Services', short: 'Services', icon: 'ti ti-stethoscope' },
    { value: 'vitals', label: 'Vitals', short: 'Vitals', icon: 'ti ti-heartbeat' },
    { value: 'allergies', label: 'Allergies & Conditions', short: 'Allergies', icon: 'ti ti-alert-triangle' },
    { value: 'settings', label: 'Record Settings', short: 'Settings', icon: 'ti ti-settings' },
];

export function isSection(value: string | null): value is Section {
    return SECTIONS.some((section) => section.value === value);
}

export const STATUS: Record<string, string> = {
    booked: 'Expected',
    checked_in: 'Waiting',
    in_consultation: 'In the room',
    completed: 'Seen',
    cancelled: 'Cancelled',
    no_show: 'Did not come',
};

/** "12 Aug 2026" */
export function longDate(value: string | null | undefined): string {
    if (!value) return '—';

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function yearOf(value: string | null | undefined): string | null {
    return value ? value.slice(0, 4) : null;
}

/*
|--------------------------------------------------------------------------
| Vitals
|--------------------------------------------------------------------------
*/

export interface VitalDef {
    key: string;
    label: string;
    unit: string;
    icon: string;
    tone: 'rose' | 'violet' | 'amber' | 'sky' | 'indigo' | 'emerald' | 'teal';
}

/**
 * The vitals worth a row, in the order a chart lists them.
 *
 * Temperature in °C because that is what the consultation screen takes and
 * validates (30–45); showing it as °F would mean converting a number
 * somebody else typed, and a converted reading is a transcription risk.
 */
export const VITALS: readonly VitalDef[] = [
    { key: 'bp', label: 'Blood Pressure', unit: 'mmHg', icon: 'ti ti-heart', tone: 'rose' },
    { key: 'pulse', label: 'Heart Rate', unit: 'bpm', icon: 'ti ti-heartbeat', tone: 'violet' },
    { key: 'temperature', label: 'Temperature', unit: '°C', icon: 'ti ti-temperature', tone: 'amber' },
    { key: 'spo2', label: 'SpO₂', unit: '%', icon: 'ti ti-lungs', tone: 'sky' },
    { key: 'weight', label: 'Weight', unit: 'kg', icon: 'ti ti-scale', tone: 'indigo' },
    { key: 'height', label: 'Height', unit: 'cm', icon: 'ti ti-ruler-2', tone: 'emerald' },
    { key: 'bmi', label: 'BMI', unit: '', icon: 'ti ti-activity', tone: 'teal' },
];

/**
 * Read a vital, including the two that are worked out rather than measured.
 *
 * Blood pressure is one reading written as two numbers, and BMI is arithmetic
 * on weight and height: storing either would be storing something already
 * known, and a stored BMI goes stale the moment a weight is corrected.
 */
export function vitalOf(values: Vitals | null | undefined, key: string): string | null {
    if (!values) return null;

    if (key === 'bp') {
        const top = values.bp_systolic;
        const bottom = values.bp_diastolic;

        return top && bottom ? `${top}/${bottom}` : null;
    }

    if (key === 'bmi') {
        const weight = values.weight;
        const height = values.height;

        if (!weight || !height) return null;

        return (weight / (height / 100) ** 2).toFixed(1);
    }

    const value = values[key];

    return value == null ? null : String(value);
}

/*
|--------------------------------------------------------------------------
| Fields a clinic may have added itself
|--------------------------------------------------------------------------
|
| Blood group, emergency contact and allergies are not columns: an
| organization adds them in Settings → Fields if it records them. These are
| the keys they are commonly given, read in order.
*/

export const BLOOD_GROUP_KEYS = ['blood_group', 'blood_type', 'bloodgroup'];
export const EMERGENCY_KEYS = ['emergency_contact', 'emergency_phone', 'emergency_contact_number'];
export const ALLERGY_KEYS = ['allergies', 'allergy', 'known_allergies'];

/** Every key read into a row of its own, so it is not listed twice. */
export const DEDICATED_KEYS = new Set([...BLOOD_GROUP_KEYS, ...EMERGENCY_KEYS, ...ALLERGY_KEYS]);

export function customText(customer: Customer, keys: readonly string[]): string | null {
    const fields = customer.custom_fields ?? {};

    for (const key of keys) {
        const value = fields[key];

        if (Array.isArray(value) && value.length > 0) {
            return value.join(', ');
        }

        if (typeof value === 'number' || (typeof value === 'string' && value.trim() !== '')) {
            return String(value).trim();
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| What the visits add up to
|--------------------------------------------------------------------------
*/

export interface PrescriptionRow {
    id: number;
    date: string | null;
    doctor: string | null;
    lines: PrescriptionLine[];
}

export interface LabRow {
    key: string;
    date: string | null;
    test: string;
    notes: string | null;
    doctor: string | null;
}

export interface NoteRow {
    key: string;
    date: string | null;
    doctor: string | null;
    kind: 'Note' | 'Advice';
    text: string;
}

export interface DiagnosisRow {
    name: string;
    /** The first visit it was written at. */
    since: string | null;
}

export interface PatientFile {
    seen: Visit[];
    doctorCount: number;
    prescriptions: PrescriptionRow[];
    labs: LabRow[];
    notes: NoteRow[];
    /** Visits at which anything was measured, newest first. */
    vitalsRows: Visit[];
    diagnoses: DiagnosisRow[];
}

type WrittenVisit = Visit & { consultation: Consultation };

/**
 * Everything the file shows, derived from the visits in one pass.
 *
 * Derived rather than stored, like the server's own summary: a stored count
 * of prescriptions would drift the first time a consultation was edited.
 * The visits arrive newest first, and every list here keeps that order.
 */
export function readFile(visits: Visit[]): PatientFile {
    const seen = visits.filter((visit) => visit.status === 'completed');
    const written = visits.filter((visit): visit is WrittenVisit => visit.consultation !== null);

    const doctorCount = new Set(seen.map((visit) => visit.doctor_name).filter(Boolean)).size;

    const prescriptions = written
        .filter((visit) => visit.consultation.prescription.length > 0)
        .map((visit) => ({
            id: visit.id,
            date: visit.date,
            doctor: visit.doctor_name,
            lines: visit.consultation.prescription,
        }));

    const labs = written.flatMap((visit) =>
        visit.consultation.investigations.map((line, index) => ({
            key: `${visit.id}-${index}`,
            date: visit.date,
            test: line.test,
            notes: line.notes ?? null,
            doctor: visit.doctor_name,
        })),
    );

    const notes = written.flatMap((visit) => {
        const rows: NoteRow[] = [];

        if (visit.consultation.notes) {
            rows.push({
                key: `${visit.id}-note`,
                date: visit.date,
                doctor: visit.doctor_name,
                kind: 'Note',
                text: visit.consultation.notes,
            });
        }

        if (visit.consultation.advice) {
            rows.push({
                key: `${visit.id}-advice`,
                date: visit.date,
                doctor: visit.doctor_name,
                kind: 'Advice',
                text: visit.consultation.advice,
            });
        }

        return rows;
    });

    const vitalsRows = written.filter(
        (visit) => visit.consultation.vitals && Object.keys(visit.consultation.vitals).length > 0,
    );

    /*
     * Each diagnosis once, with the first visit it was written at.
     *
     * Walking newest to oldest and overwriting the date leaves the earliest
     * one; a Map keeps the order each was first met in, which is most recent
     * first. "Since" is when it was first recorded here, not when it began,
     * and the page says so.
     */
    const since = new Map<string, string | null>();

    for (const visit of written) {
        for (const diagnosis of visit.consultation.diagnoses) {
            since.set(diagnosis, visit.date ?? since.get(diagnosis) ?? null);
        }
    }

    const diagnoses = [...since.entries()].map(([name, date]) => ({ name, since: date }));

    return { seen, doctorCount, prescriptions, labs, notes, vitalsRows, diagnoses };
}
