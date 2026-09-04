/** One sitting on one date, after exceptions have been applied. */
export interface AvailabilitySession {
    /** Null for an extra session — it has no weekly row behind it. */
    schedule_id: number | null;
    location_id: number;
    location_name: string | null;
    name: string | null;

    starts_at: string;
    ends_at: string;
    slot_minutes: number;
    max_walkins: number | null;

    /** True when an exception moved or created this sitting. */
    changed: boolean;
    reason: string | null;

    /** Derived, never stored: "10:00", "10:15", … */
    slots: string[];
}

export interface AvailabilityDoctor {
    doctor_id: number;
    doctor_name: string;
    specialisation: string | null;
    sessions: AvailabilitySession[];
}

export interface AvailabilityDay {
    date: string;
    location_id: number;
    doctors: AvailabilityDoctor[];
}

export type ExceptionType = 'unavailable' | 'changed_hours' | 'extra_session';

/** A departure from the weekly pattern on one date. */
export interface ScheduleException {
    id: number;
    doctor_id: number;
    doctor_name: string | null;
    doctor_schedule_id: number | null;
    location_id: number | null;
    location_name: string | null;

    date: string;
    type: ExceptionType;

    starts_at: string | null;
    ends_at: string | null;
    slot_minutes: number | null;
    max_walkins: number | null;
    reason: string | null;

    /** Leave that takes the day rather than one sitting. */
    whole_day: boolean;
}

export interface ScheduleExceptionInput {
    doctor_id: number | '';
    doctor_schedule_id?: number | null;
    location_id?: number | null;
    date: string;
    type: ExceptionType;
    starts_at?: string | null;
    ends_at?: string | null;
    slot_minutes?: number | null;
    reason?: string | null;
}
