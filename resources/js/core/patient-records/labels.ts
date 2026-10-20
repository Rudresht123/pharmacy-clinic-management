import { VISIT_LABEL, VISIT_TONE } from '@/core/appointments/workflow';
import type { AppointmentStatus } from '@/core/appointments/types';
import { INVOICE_STATUS_LABELS } from '@/core/billing/api';
import { PAYMENT_STATUS_LABELS as SALE_PAYMENT_LABELS } from '@/core/pharmacy/sales';
import { STATUS_LABELS as PRESCRIPTION_LABELS } from '@/core/prescriptions/types';
import type { EventKind, SectionKey, Tab } from './types';

/*
 * How each module's records read on this screen.
 *
 * The WORDS come from each module's own label map wherever one exists, so a
 * state reads the same here as on the module's own screens. Only the lab has
 * no web screen yet, and so has its labels here.
 */

/** The colours a pill can take — kept to meaning, never decoration. */
export type Tone = 'green' | 'blue' | 'amber' | 'rose' | 'violet' | 'teal' | 'grey';

export interface StatusPill {
    label: string;
    tone: Tone;
}

/** The OPD's own tones, translated to this screen's pills. */
const VISIT_TONE_MAP: Record<string, Tone> = {
    slate: 'grey',
    amber: 'amber',
    violet: 'violet',
    teal: 'teal',
    sky: 'blue',
    rose: 'rose',
    emerald: 'green',
};

export const LAB_LABELS: Record<string, string> = {
    pending: 'Ordered',
    in_progress: 'In progress',
    completed: 'Report available',
    cancelled: 'Cancelled',
};

const LAB_TONES: Record<string, Tone> = {
    pending: 'blue',
    in_progress: 'amber',
    completed: 'green',
    cancelled: 'grey',
};

const PRESCRIPTION_TONES: Record<string, Tone> = {
    draft: 'grey',
    issued: 'blue',
    partially_dispensed: 'amber',
    dispensed: 'green',
    cancelled: 'grey',
    expired: 'grey',
};

/** Paid, part paid, unpaid — for an invoice and a pharmacy bill alike. */
const MONEY_TONES: Record<string, Tone> = {
    paid: 'green',
    partial: 'amber',
    partially_paid: 'amber',
    unpaid: 'rose',
    pending: 'rose',
    draft: 'grey',
    cancelled: 'grey',
    refunded: 'violet',
    partially_refunded: 'violet',
    billed_via_invoice: 'blue',
    completed: 'green',
};

export function visitPill(status: string): StatusPill {
    return {
        label: VISIT_LABEL[status as AppointmentStatus] ?? status,
        tone: VISIT_TONE_MAP[VISIT_TONE[status as AppointmentStatus]] ?? 'grey',
    };
}

export function prescriptionPill(status: string): StatusPill {
    return {
        label: PRESCRIPTION_LABELS[status as keyof typeof PRESCRIPTION_LABELS] ?? status,
        tone: PRESCRIPTION_TONES[status] ?? 'grey',
    };
}

export function labPill(status: string): StatusPill {
    return { label: LAB_LABELS[status] ?? status, tone: LAB_TONES[status] ?? 'grey' };
}

/** A pharmacy bill: cancelled outranks how it was paid. */
export function salePill(status: string, paymentStatus?: string): StatusPill {
    if (status === 'cancelled') {
        return { label: 'Cancelled', tone: 'grey' };
    }

    const key = paymentStatus ?? status;

    return { label: SALE_PAYMENT_LABELS[key] ?? key, tone: MONEY_TONES[key] ?? 'grey' };
}

/** An invoice reads by its status; "Pending" is how the register says unpaid. */
export function invoicePill(status: string): StatusPill {
    const labels: Record<string, string> = { ...INVOICE_STATUS_LABELS, unpaid: 'Unpaid', partial: 'Part paid' };

    return { label: labels[status] ?? status, tone: MONEY_TONES[status] ?? 'grey' };
}

/** The pill a timeline event carries, read by the module it came from. */
export function eventPill(kind: EventKind, status: string | null): StatusPill | null {
    if (!status) return null;

    switch (kind) {
        case 'visit':
        case 'appointment':
            return visitPill(status);
        case 'prescription':
            return prescriptionPill(status);
        case 'lab':
            return labPill(status);
        case 'pharmacy':
            return salePill(status);
        case 'invoice':
            return invoicePill(status);
        default:
            return null;
    }
}

/** Each kind of event's mark on the timeline. */
export const EVENT_ICON: Record<EventKind, { icon: string; tone: Tone }> = {
    visit: { icon: 'ti ti-stethoscope', tone: 'blue' },
    appointment: { icon: 'ti ti-calendar-event', tone: 'rose' },
    prescription: { icon: 'ti ti-prescription', tone: 'green' },
    lab: { icon: 'ti ti-flask', tone: 'violet' },
    pharmacy: { icon: 'ti ti-pill', tone: 'amber' },
    invoice: { icon: 'ti ti-receipt', tone: 'teal' },
    document: { icon: 'ti ti-file-text', tone: 'grey' },
};

/** The tab strip, in the order somebody reads a record. */
export const TABS: readonly { value: Tab; label: string; icon: string; section?: SectionKey }[] = [
    { value: 'overview', label: 'Overview', icon: 'ti ti-layout-dashboard' },
    { value: 'appointments', label: 'Appointments', icon: 'ti ti-calendar', section: 'appointments' },
    { value: 'visits', label: 'OPD Visits', icon: 'ti ti-stethoscope', section: 'visits' },
    { value: 'prescriptions', label: 'Prescriptions', icon: 'ti ti-prescription', section: 'prescriptions' },
    { value: 'laboratory', label: 'Lab Reports', icon: 'ti ti-flask', section: 'laboratory' },
    { value: 'pharmacy', label: 'Pharmacy', icon: 'ti ti-pill', section: 'pharmacy' },
    { value: 'billing', label: 'Billing', icon: 'ti ti-receipt', section: 'billing' },
    { value: 'documents', label: 'Documents', icon: 'ti ti-files', section: 'documents' },
];

export function isTab(value: string | null): value is Tab {
    return TABS.some((tab) => tab.value === value);
}

/*
|--------------------------------------------------------------------------
| Numbers and dates
|--------------------------------------------------------------------------
*/

const WHOLE = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
const PAISE = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 });

/** "₹1,250", or "₹92.10" — paise only when there are some, and then both digits. */
export function rupees(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return Number.isInteger(Math.round(value * 100) / 100) ? WHOLE.format(value) : PAISE.format(value);
}

/** "12 Oct 2026" from a date or a date-time; a bare date is read as local, not UTC. */
export function day(value: string | null | undefined): string {
    if (!value) return '—';

    const date = /^\d{4}-\d{2}-\d{2}$/.test(value)
        ? new Date(Number(value.slice(0, 4)), Number(value.slice(5, 7)) - 1, Number(value.slice(8, 10)))
        : new Date(value);

    if (Number.isNaN(date.getTime())) return '—';

    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** "10:30 AM" from a date-time, or from a bare "10:30" slot. */
export function clock(value: string | null | undefined): string | null {
    if (!value) return null;

    const date = /^\d{2}:\d{2}/.test(value) ? new Date(`2000-01-01T${value.slice(0, 5)}:00`) : new Date(value);

    if (Number.isNaN(date.getTime())) return null;

    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

/** "12 Oct 2026, 10:30 AM", or the date alone when the time is not known. */
export function when(value: string | null | undefined, hasTime = true): string {
    const time = hasTime ? clock(value) : null;

    return time ? `${day(value)}, ${time}` : day(value);
}

/** A ten-digit Indian mobile read the way it is said: "98765 43210". */
export function mobile(value: string | null | undefined): string | null {
    if (!value) return null;

    const digits = value.replace(/\D/g, '');

    return digits.length === 10 ? `${digits.slice(0, 5)} ${digits.slice(5)}` : value;
}

export function capitalise(value: string | null | undefined): string | null {
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : null;
}
