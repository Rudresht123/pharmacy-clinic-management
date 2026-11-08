import type { ItemStatus, OrderStatus } from './types';

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
    pending: 'Pending',
    in_progress: 'Processing',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

/** Where a test request stands, as a soft coloured chip. */
export function LabStatus({ status }: { status: OrderStatus | ItemStatus }) {
    return <span className={`lab-status is-${status}`}>{ORDER_STATUS_LABELS[status as OrderStatus] ?? status}</span>;
}

/** "34 y · Male" — what the header line says about the patient. */
export function patientFacts(age?: number | null, gender?: string | null): string {
    const parts = [
        age !== null && age !== undefined ? `${age} y` : null,
        gender ? gender.charAt(0).toUpperCase() + gender.slice(1) : null,
    ].filter(Boolean);

    return parts.join(' · ');
}

const time = new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit' });
const day = new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });

/** "08 Nov 2026, 10:15 am" */
export function stamp(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return `${day.format(date)}, ${time.format(date)}`;
}

/** "10:15 am" today, else "08 Nov, 10:15 am". */
export function shortStamp(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);
    const today = new Date();

    if (date.toDateString() === today.toDateString()) {
        return time.format(date);
    }

    return `${new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short' }).format(date)}, ${time.format(date)}`;
}
