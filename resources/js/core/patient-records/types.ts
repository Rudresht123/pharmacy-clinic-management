import type { Customer } from '@/core/customers/types';
import type { Appointment } from '@/core/appointments/types';
import type { Consultation } from '@/core/customers/components/patientFile';
import type { Prescription } from '@/core/prescriptions/types';

/** A row of the register: the patient, plus when they were last seen. */
export interface PatientRow extends Customer {
    /** Absent when the reader cannot see visits; null when never seen. */
    last_visit_on?: string | null;
}

/** The sections of the record, each the module behind it. */
export type SectionKey =
    | 'appointments'
    | 'visits'
    | 'prescriptions'
    | 'laboratory'
    | 'pharmacy'
    | 'billing'
    | 'documents';

/** The tab strip: the overview, then one tab per section. */
export type Tab = 'overview' | SectionKey;

/** GET /tenant/patient-records/stats */
export interface RecordCard {
    key: 'total' | 'new' | 'upcoming' | 'recent';
    label: string;
    value: number;
    /** Percent against the period before; null when there is nothing to compare. */
    change: number | null;
    hint: string;
}

export interface BranchOption {
    id: number;
    name: string;
}

export interface RecordStats {
    cards: RecordCard[];
    branches: BranchOption[];
}

export type EventKind = 'visit' | 'appointment' | 'prescription' | 'lab' | 'pharmacy' | 'invoice' | 'document';

/** One row of the timeline, the same shape whichever module it came from. */
export interface TimelineEvent {
    key: string;
    kind: EventKind;
    /** The tab that holds the record in full. */
    section: SectionKey;
    title: string;
    at: string | null;
    /** False when only the day is known — print the date alone. */
    has_time: boolean;
    status: string | null;
    doctor_name: string | null;
    location_name: string | null;
    detail: string | null;
    count: number | null;
    amount: number | null;
    link: string | null;
}

/** GET /tenant/patient-records/{id} */
export interface PatientRecordSummary {
    patient: Customer;
    /** A count per section; null where this reader may not look. */
    sections: Record<SectionKey, number | null>;
    billing: { billed: number; paid: number; outstanding: number } | null;
    pharmacy_due: number | null;
    last_visit: { on: string | null; doctor_name: string | null; location_name: string | null } | null;
    next_visit: {
        on: string | null;
        at: string | null;
        doctor_name: string | null;
        location_name: string | null;
    } | null;
    /** Every branch this patient has records at, among the ones the reader sees. */
    branches: BranchOption[];
    activity: TimelineEvent[];
}

/** An appointment with what was written up at it. */
export interface PatientVisit extends Appointment {
    mode?: 'in_clinic' | 'video';
    consultation?: Consultation | null;
}

/** A prescription as the module's list returns it — counted, not loaded. */
export interface PrescriptionRow extends Prescription {
    items_count?: number;
}

/*
|--------------------------------------------------------------------------
| The laboratory — no other screen on the web reads lab orders yet
|--------------------------------------------------------------------------
*/

export interface LabOrderItem {
    id: number;
    test_name: string;
    test_code: string | null;
    specimen: string | null;
    status: 'pending' | 'completed' | 'cancelled';
    result_value: string | null;
    result_unit: string | null;
    reference_range: string | null;
    is_abnormal: boolean;
    result_text: string | null;
    notes: string | null;
    completed_at: string | null;
}

export interface LabOrder {
    id: number;
    order_number: string;
    location_id: number;
    location_name?: string | null;
    doctor_name?: string | null;
    order_date: string | null;
    status: 'pending' | 'in_progress' | 'completed' | 'cancelled';
    clinical_notes: string | null;
    completed_at: string | null;
    cancellation_reason: string | null;
    tests_summary?: string;
    pending_count?: number;
    items?: LabOrderItem[];
    invoice?: { id: number; invoice_number: string; outstanding: number } | null;
}
