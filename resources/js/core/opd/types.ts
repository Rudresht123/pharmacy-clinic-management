import type {
    AppointmentStatus,
    AppointmentType,
    ConsultationStatus,
    NextAction,
    QueueStatus,
} from '@/core/appointments/types';

/**
 * A branch this person may actually run an OPD day at.
 *
 * Not the same list as /tenant/locations. That one needs `branches.view` —
 * which somebody who runs the desk has no reason to hold — and returns every
 * branch in the organization, including the ones they would be refused at.
 */
export interface OpdBranch {
    id: number;
    name: string;
    code: string | null;
}

/**
 * A figure beside what it was yesterday.
 *
 * `delta_pct` is null when yesterday was zero: a clinic that saw nobody
 * yesterday and four people today has not improved by four hundred per cent,
 * it has opened.
 */
export interface OpdDelta {
    value: number;
    delta: number;
    delta_pct: number | null;
}

export interface OpdCounts {
    total: OpdDelta;
    /** Nobody has called them yet — the figure the desk has to act on. */
    waiting: { value: number; average_wait: number | null; longest_wait: number };
    /** Called and not gone in. A number that stays up is a fixable problem. */
    called: { value: number; longest_wait: number };
    in_consultation: { value: number; doctors: number };

    /** Write-ups finished, wherever the patient is now. */
    consulted: { value: number; average_minutes: number | null; of_total: number };

    /** Seen, and still here for somebody else's queue. */
    awaiting: { pharmacy: number; laboratory: number; payment: number };

    /** Visits actually over — everything downstream settled. */
    completed: OpdDelta & { average_minutes: number | null; of_total: number };
    no_show: { value: number; of_total: number };
    expected: { value: number; overdue: number };
    cancelled: { value: number };
}

/** How many rows sit behind each filter, counted from the whole day. */
export interface OpdTabs {
    all: number;
    checked_in: number;
    waiting: number;
    called: number;
    in_consultation: number;
    awaiting: number;
    completed: number;
    booked: number;
}

/** One line of the dashboard's own copy of the queue. */
export interface OpdQueueRow {
    id: number;
    token_no: number | null;
    customer_name: string | null;
    customer_code: string | null;
    /** Worked out per request — a cached age is wrong on a birthday. */
    age: number | null;
    gender: string | null;
    doctor_name: string | null;

    status: AppointmentStatus;
    queue_status: QueueStatus | null;
    consultation_status: ConsultationStatus;
    next_action: NextAction | null;

    type: AppointmentType;
    slot_at: string | null;
    waiting_minutes: number | null;
    called_at: string | null;
    next_states: AppointmentStatus[];

    /** What this row's state allows. Paired with `can()` to decide a button. */
    available: {
        check_in: boolean;
        call: boolean;
        cancel: boolean;
        no_show: boolean;
    };
}

/**
 * A doctor who has finished and has somebody to see.
 *
 * THE NEXT-PATIENT NOTIFICATION, as a fact on the board rather than a message
 * pushed anywhere. The desk already polls this endpoint; a doctor finishing
 * changes what it says, and the screen raises a toast off the change. Nothing
 * extra has to be running for it to work.
 *
 * `finished_at` is when their last consultation ended — what lets the screen
 * tell "just now" from "this has been true for ten minutes", and notify once
 * rather than on every poll.
 */
export interface OpdReady {
    doctor_id: number;
    doctor_name: string | null;
    finished_at: string | null;
    next: {
        id: number;
        token_no: number | null;
        customer_name: string | null;
        queue_status: QueueStatus | null;
        waiting_minutes: number;
    };
    waiting: number;
}

/**
 * How deep the queue was at the end of one hour, split by whether the patient
 * had been to the clinic before.
 *
 * There is no visit type on an appointment yet, so "new" and "follow-up" are
 * read off the record itself: somebody with an earlier appointment is
 * returning. That stops being a stand-in the moment visits get a type.
 */
export interface OpdFlowPoint {
    label: string;
    title: string;
    value: number;
    returning: number;
    fresh: number;
}

/** Today's list split by what the doctors do. */
export interface OpdDepartment {
    label: string;
    value: number;
    share: number;
}

export interface OpdWaiting {
    id: number;
    token_no: number | null;
    customer_name: string | null;
    doctor_name: string | null;
    waiting_minutes: number;
}

/**
 * What a doctor is doing right now.
 *
 * `free` means the room is empty and people are still waiting — the state that
 * wants somebody to do something. `clear` means their list is empty, which is
 * not a problem. Three states and no fourth: the software has no way of
 * knowing whether a doctor with an empty list is idle or at lunch.
 */
export type DoctorState = 'with_patient' | 'free' | 'clear';

export interface OpdDoctor {
    id: number;
    name: string | null;
    specialisation: string | null;
    state: DoctorState;
    /** Who is in the room, when there is somebody. */
    with: string | null;
    waiting: number;
    seen: number;
    average_minutes: number | null;
    longest_wait: number;
}

export interface OpdUpcoming {
    id: number;
    slot_at: string;
    customer_name: string | null;
    doctor_name: string | null;
    specialisation: string | null;
    /** Their time has passed and they are still not here. */
    overdue: boolean;
}

export interface OpdActivity {
    id: number;
    event: string;
    entity_type: string;
    entity_label: string | null;
    actor_name: string | null;
    created_at: string | null;
}

export interface OpdToday {
    date: string;
    location_id: number;
    branch_name: string | null;
    /** What the "Live · last updated" pill reads, from the server's clock. */
    updated_at: string;

    counts: OpdCounts;
    tabs: OpdTabs;
    queue: OpdQueueRow[];
    /** Empty before anybody has arrived — the panel says so rather than drawing nothing. */
    flow: OpdFlowPoint[];
    departments: OpdDepartment[];
    waiting_longest: OpdWaiting[];
    /** Doctors free with somebody to see — the next-patient notification. */
    ready: OpdReady[];
    doctors: OpdDoctor[];
    upcoming: OpdUpcoming[];
    activity: OpdActivity[];

    /**
     * How long is too long, in minutes — the server's numbers, not the
     * screen's. Stated out loud in the interface rather than implied by a
     * colour, so somebody can learn the rule instead of guessing it.
     */
    thresholds: { warn: number; critical: number };
}

/** One row of a doctor's own list, shaped the same wherever it appears. */
export interface MyRow {
    id: number;
    token_no: number | null;
    /** The patient this visit is for — what documents hang off. */
    customer_id: number;
    customer_name: string | null;
    customer_code: string | null;
    age: number | null;
    gender: string | null;

    status: AppointmentStatus;
    /** Has the desk called them, and has the write-up been opened. */
    queue_status: QueueStatus | null;
    consultation_status: ConsultationStatus;
    next_action: NextAction | null;

    type: string;

    /** Have we seen them before? Not how the appointment was made. */
    is_new: boolean;

    slot_at: string | null;
    waiting_minutes: number | null;
    called_at: string | null;
    completed_at: string | null;
    next_states: string[];

    /**
     * What this row's state allows — the doctor's three, plus the desk's two
     * for the rare doctor who also runs their own queue.
     */
    available: {
        check_in: boolean;
        call: boolean;
        consult_start: boolean;
        consult_complete: boolean;
        consult_reopen: boolean;
    };
}

/** One line of a prescription. Free text until a drug catalogue exists. */
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

/** What was measured. Every field optional — most visits record two of them. */
export interface Vitals {
    bp_systolic?: number | null;
    bp_diastolic?: number | null;
    pulse?: number | null;
    temperature?: number | null;
    spo2?: number | null;
    weight?: number | null;
    height?: number | null;
}

/**
 * What happened in the room.
 *
 * `id` is null until somebody writes something, so a screen can tell "nothing
 * recorded" from "recorded as empty".
 */
export interface Consultation {
    id: number | null;
    chief_complaint: string | null;
    diagnoses: string[];
    vitals: Vitals;
    prescription: PrescriptionLine[];
    investigations: InvestigationLine[];
    advice: string | null;
    notes: string | null;
    follow_up_days: number | null;
}

/** A visit this patient had before today. */
export interface PastConsultation {
    id: number;
    on: string | null;
    doctor_name: string | null;
    chief_complaint: string | null;
    diagnoses: string[];
}

/** Everything a doctor's own screens read, from one request. */
export interface MyDay {
    date: string;

    doctor: {
        id: number;
        name: string;
        specialisation: string | null;
        photo_url: string | null;
    };

    counts: {
        total: number;
        seen: number;
        waiting: number;
        expected: number;
        new: number;
        returning: number;
        follow_ups: number;
        no_show: number;
    };
    queue: MyRow[];

    current:
        | (MyRow & {
              customer_id: number;
              phone: string | null;
              location_name: string | null;
              in_room_minutes: number | null;

              /** City and state as one line, or null when neither is set. */
              where: string | null;

              /** "14:32" — when the consultation began. */
              started_at: string | null;

              /** Null when this organization does not record allergies at all. */
              allergies: string | null;

              history: PastConsultation[];

              /** This doctor's own recent wording, most used first. */
              suggestions: { complaints: string[]; diagnoses: string[] };

              consultation: Consultation;
          })
        | null;

    /** What is actually happening today — leave applied, hours moved. */
    schedule: {
        starts_at: string;
        ends_at: string;
        name: string | null;
        location_name: string | null;
        changed: boolean;
        state: 'now' | 'later' | 'done';
    }[];

    /**
     * The usual weekly pattern, wherever they sit.
     *
     * A different question from `schedule`, which they resemble: this is "when
     * am I normally in", that is "what is happening today".
     */
    week: {
        weekday: number;
        label: string;
        sittings: {
            starts_at: string;
            ends_at: string;
            name: string | null;
            location_name: string | null;
            slot_minutes: number;
        }[];
    }[];

    upcoming: {
        id: number;
        slot_at: string;
        customer_name: string | null;
        type: string;
        is_new: boolean;
    }[];
}
