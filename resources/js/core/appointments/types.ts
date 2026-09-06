export type AppointmentType = 'booked' | 'walk_in';

export type AppointmentStatus =
    'booked' | 'checked_in' | 'in_consultation' | 'completed' | 'cancelled' | 'no_show';

/** Somebody intending to see a doctor. An intent, not an outcome. */
export interface Appointment {
    id: number;

    customer_id: number;
    customer_name?: string | null;
    customer_code?: string | null;
    customer_phone?: string | null;
    /** Age is derived on the screen — it is a fact about today, not the row. */
    customer_dob?: string | null;
    customer_gender?: string | null;

    doctor_id: number;
    doctor_name?: string | null;
    /** Standing in for a department, which does not exist as a table yet. */
    doctor_specialisation?: string | null;

    location_id: number;
    location_name?: string | null;

    /** Which sitting produced this — provenance, null once it is deleted. */
    doctor_schedule_id: number | null;

    appointment_date: string;
    type: AppointmentType;
    status: AppointmentStatus;

    /** Booked only. A walk-in has no promised time. */
    slot_at: string | null;
    /** Issued at check-in, never at booking. */
    token_no: number | null;

    checked_in_at: string | null;
    started_at: string | null;
    completed_at: string | null;

    /** How long they have been waiting; computed server-side. */
    waiting_minutes: number | null;

    cancellation_reason: string | null;
    notes: string | null;

    /** What this may become next — the screen offers exactly these. */
    next_states: AppointmentStatus[];

    created_at: string | null;
}

export interface QueuePayload {
    queue: Appointment[];

    waiting: number;
    with_doctor: number;
    seen: number;
    expected: number;

    /**
     * The doctors who appear in THIS list, so the filter is built from the
     * day rather than from every doctor on the books. One with nobody booked
     * is not a filter anybody wants.
     */
    doctors: { id: number; name: string | null }[];

    /** How long is too long — the server's numbers, not the screen's. */
    thresholds: { warn: number; critical: number };
}

/** One sitting with what is still free, and what has gone. */
export interface OpenSession {
    schedule_id: number | null;
    location_id: number;
    location_name: string | null;
    name: string | null;
    starts_at: string;
    ends_at: string;
    slot_minutes: number;
    changed: boolean;
    reason: string | null;
    slots: string[];
    taken: string[];
}

export interface BookingInput {
    customer_id: number | '';
    doctor_id: number | '';
    location_id: number | '';
    appointment_date: string;
    type: AppointmentType;
    slot_at?: string | null;
    notes?: string | null;
}
