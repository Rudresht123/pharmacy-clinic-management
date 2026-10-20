import type { Timestamps } from '@/shared/types/api';

/**
 * A doctor the organization's patients are seen by.
 *
 * Has no branch of its own. Where a doctor works comes from their schedules
 * — `locations` below is derived from them, not stored.
 */
export interface Doctor extends Timestamps {
    id: number;
    name: string;
    code: string | null;

    /** The department's name, as text — what boards and filters read. */
    specialisation: string | null;

    /** Their department or sub-department, from the department tree. */
    department_id: number | null;
    /** "Cardiology › Interventional Cardiology" — on the detail endpoint. */
    department_name?: string | null;

    /** A list. It was one comma-joined string until the column became jsonb. */
    qualifications: string[];
    registration_no: string | null;

    phone: string | null;
    email: string | null;

    /** The fallback: what a branch without a fee of its own charges. */
    default_consultation_fee: string | null;

    /**
     * What they charge at each branch they are posted to. A null fee is
     * "the default", which is what a bill at that branch then uses.
     */
    branch_fees?: DoctorBranchFee[];
    is_active: boolean;
    notes: string | null;

    /** Absent rather than null when the photograph relation was not loaded. */
    photo_url?: string | null;

    /**
     * Their login, when they have one.
     *
     * Email only — a password is write-only, and a hash sent to a screen with
     * no use for it is how hashes reach browser caches and bug reports. Absent
     * rather than null when the relation was not loaded.
     */
    account?: { id: number; email: string } | null;

    /** Only on the detail endpoint — on a list it would be a query per row. */
    locations?: string[];

    /**
     * The branches this doctor covers, as ids the form can tick.
     *
     * Separate from `locations`, which is the same set as names for reading.
     * The screen needs both — one to render, one to post back.
     */
    location_ids?: number[];
    schedule_count?: number;

    custom_fields: Record<string, unknown>;
}

export interface DoctorBranchFee {
    location_id: number;
    location_name: string;
    consultation_fee: string | null;
}

/** One sitting: a branch, a weekday, and the hours. */
export interface DoctorSchedule {
    id: number;
    location_id: number;
    location_name?: string | null;

    /** "Morning OPD" — what the queue board calls this sitting. */
    name: string | null;

    /** Monday 0 … Sunday 6. */
    weekday: number;
    weekday_label: string;

    /** "10:00" — trimmed of the seconds Postgres returns. */
    starts_at: string;
    ends_at: string;

    slot_minutes: number;
    max_walkins: number | null;
    is_active: boolean;

    /** When the pattern starts applying; null means it already does. */
    effective_from?: string | null;
}

/** One sitting as the editor submits it; new rows have no id yet. */
export interface DoctorScheduleInput {
    location_id: number | '';
    name: string | null;
    weekday: number;
    starts_at: string;
    ends_at: string;
    slot_minutes: number;
    max_walkins: number | null;
    is_active: boolean;

    /** When the pattern starts applying; null means it already does. */
    effective_from?: string | null;
}
