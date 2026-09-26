export type AppointmentType = 'booked' | 'walk_in';

/**
 * THE VISIT. Where the whole episode has got to.
 *
 * The system's column: nobody clicks it, and no screen offers it as a button.
 * The three `awaiting_*` are what a finished consultation actually produces —
 * a doctor signing off is not a patient going home.
 */
export type AppointmentStatus =
    | 'booked'
    | 'checked_in'
    | 'in_consultation'
    | 'awaiting_pharmacy'
    | 'awaiting_lab'
    | 'awaiting_payment'
    | 'completed'
    | 'cancelled'
    | 'no_show';

/**
 * THE RECEPTION DESK. Null before they arrive and again once the doctor is
 * done — somebody who has left the department is not in a queue.
 */
export type QueueStatus = 'waiting' | 'called' | 'with_doctor';

/** THE DOCTOR. Never null: every visit has a write-up, started or not. */
export type ConsultationStatus = 'not_started' | 'in_progress' | 'completed';

/** Where to send the patient once the doctor has finished. */
export type NextAction = 'pharmacy' | 'laboratory' | 'billing' | 'follow_up' | 'none';

/**
 * Which workflow verbs this row's STATE allows — one per button.
 *
 * Half of button visibility, and deliberately only half: this says what is
 * possible, `can()` says who may. Both have to be true to render, which is
 * what stops a receptionist being shown Start consultation and stops a doctor
 * being shown it on somebody reception has not called.
 *
 * It comes from the server because the state machine is the server's, and a
 * screen that worked out for itself when Complete applies would be a second
 * implementation that drifts from the first one.
 */
export interface AppointmentActions {
    check_in: boolean;
    call: boolean;
    consult_start: boolean;
    consult_complete: boolean;
    consult_reopen: boolean;
    cancel: boolean;
    no_show: boolean;
}

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

    /** Three columns, three questions. Read whichever one your screen is about. */
    status: AppointmentStatus;
    queue_status: QueueStatus | null;
    consultation_status: ConsultationStatus;
    next_action: NextAction | null;

    /** Booked only. A walk-in has no promised time. */
    slot_at: string | null;
    /** Issued at check-in, never at booking. */
    token_no: number | null;

    checked_in_at: string | null;
    called_at: string | null;

    /** `started_at` / `completed_at` are the CONSULTATION's clocks. */
    started_at: string | null;
    completed_at: string | null;
    /** When the EPISODE closed, which is not when the doctor finished. */
    visit_completed_at: string | null;

    /** Sent as names, not ids — the only consumer is a line on a screen. */
    called_by_name?: string | null;
    consultation_started_by_name?: string | null;
    consultation_completed_by_name?: string | null;

    /** How long they have been waiting; computed server-side. */
    waiting_minutes: number | null;

    cancellation_reason: string | null;
    notes: string | null;

    /** What the VISIT may become next. No longer what buttons are built from. */
    next_states: AppointmentStatus[];

    /** What buttons ARE built from, together with the viewer's capabilities. */
    available: AppointmentActions;

    created_at: string | null;
}

export interface QueuePayload {
    queue: Appointment[];

    /** Nobody has called them yet — the number the desk has to act on. */
    waiting: number;
    /** Called and not yet taken in. Should be near zero on a good morning. */
    called: number;
    with_doctor: number;
    /** Write-ups finished, wherever the patient is now. */
    seen: number;
    expected: number;

    /** Seen, and still in the building for somebody else's queue. */
    awaiting: { pharmacy: number; laboratory: number; payment: number };

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
