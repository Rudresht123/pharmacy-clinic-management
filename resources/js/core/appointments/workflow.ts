import type {
    Appointment,
    AppointmentStatus,
    ConsultationStatus,
    NextAction,
    QueueStatus,
} from './types';

/**
 * How the three workflow columns are said out loud.
 *
 * One file, because the desk, the doctor's list, the board and the patient
 * timeline all show the same states and had better use the same words for
 * them. A receptionist reading "Awaiting pharmacy" on one screen and "With
 * pharmacy" on the next has to work out whether they mean the same thing.
 */

/** THE VISIT — what the whole episode is doing. */
export const VISIT_LABEL: Record<AppointmentStatus, string> = {
    booked: 'Expected',
    checked_in: 'In the department',
    in_consultation: 'With doctor',

    /*
     * Said from the patient's side, not the department's. "Awaiting
     * pharmacy" reads as a queue somebody is in; "Waiting for pharmacy" is
     * what the person at the desk actually tells them.
     */
    awaiting_pharmacy: 'Waiting for pharmacy',
    awaiting_lab: 'Waiting for laboratory',
    awaiting_payment: 'Payment pending',

    completed: 'Visit completed',
    cancelled: 'Cancelled',
    no_show: 'Did not come',
};

/** THE QUEUE — where the desk has got to. */
export const QUEUE_LABEL: Record<QueueStatus, string> = {
    waiting: 'Waiting',
    called: 'Called',
    with_doctor: 'With doctor',
};

/** THE CONSULTATION — where the doctor has got to. */
export const CONSULTATION_LABEL: Record<ConsultationStatus, string> = {
    not_started: 'Not started',
    in_progress: 'In progress',
    completed: 'Completed',
};

/** The colour each visit state carries, so one state looks the same everywhere. */
export const VISIT_TONE: Record<AppointmentStatus, string> = {
    booked: 'slate',
    checked_in: 'amber',
    in_consultation: 'violet',
    awaiting_pharmacy: 'teal',
    awaiting_lab: 'sky',
    awaiting_payment: 'rose',
    completed: 'emerald',
    cancelled: 'slate',
    no_show: 'slate',
};

/**
 * What to offer the patient next, once the doctor has finished.
 *
 * `to` is where the STAFF member goes to deal with it — the pharmacist's
 * worklist, the lab's, the bills. Null for the two that are not a queue
 * anybody works: a follow-up is something to say on the way out, and a
 * finished visit needs nowhere to go.
 */
export const NEXT_ACTION_STEP: Record<NextAction, {
    label: string;
    icon: string;
    to: string | null;
    /** The capability that makes the link worth showing at all. */
    needs: string | null;
}> = {
    pharmacy: {
        label: 'Go to pharmacy',
        icon: 'ti ti-pill',
        to: '/pharmacy/dispensing',
        needs: 'pharmacy.dispense',
    },
    laboratory: {
        label: 'View lab order',
        icon: 'ti ti-flask',
        to: '/lab/orders',
        needs: 'laboratory.view',
    },
    billing: {
        label: 'Collect payment',
        icon: 'ti ti-cash',
        to: '/pharmacy/sales?unpaid_only=1',
        needs: 'pharmacy.view',
    },
    follow_up: {
        label: 'Follow-up booked in',
        icon: 'ti ti-calendar-repeat',
        to: null,
        needs: null,
    },
    none: {
        label: 'Visit completed',
        icon: 'ti ti-circle-check',
        to: null,
        needs: null,
    },
};

/**
 * The one line a DESK row should read.
 *
 * Deliberately not just `VISIT_LABEL[row.status]`. While somebody is in the
 * department the desk cares about the queue — waiting or called, which the
 * visit column cannot tell apart — and once they have seen the doctor it
 * cares about the visit, because that is the bit that says where they went.
 */
export function deskStatus(row: Appointment): string {
    if (row.status === 'checked_in' && row.queue_status) {
        return QUEUE_LABEL[row.queue_status];
    }

    return VISIT_LABEL[row.status] ?? row.status;
}

/**
 * Whether this row is finished as far as the department is concerned.
 *
 * Used to grey a row rather than to hide it: the day's record is what the
 * desk scrolls back through when somebody rings up asking what happened.
 */
export function isClosed(row: Appointment): boolean {
    return row.status === 'completed'
        || row.status === 'cancelled'
        || row.status === 'no_show';
}
