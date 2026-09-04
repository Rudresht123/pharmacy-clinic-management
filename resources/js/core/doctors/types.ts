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

    specialisation: string | null;
    qualification: string | null;
    registration_no: string | null;

    phone: string | null;
    email: string | null;

    /** The fallback, not the price. Branch pricing arrives with billing. */
    default_consultation_fee: string | null;
    is_active: boolean;
    notes: string | null;

    /** Only on the detail endpoint — on a list it would be a query per row. */
    locations?: string[];
    schedule_count?: number;

    custom_fields: Record<string, unknown>;
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
}
