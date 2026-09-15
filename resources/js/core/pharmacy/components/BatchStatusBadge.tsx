import type { MedicineBatch } from '../inventory';

const TONES: Record<string, { label: string; className: string }> = {
    active: { label: 'Active', className: 'bg-success-subtle text-success' },
    blocked: { label: 'Blocked', className: 'bg-warning-subtle text-warning' },
    recalled: { label: 'Recalled', className: 'bg-danger-subtle text-danger' },
    exhausted: { label: 'Empty', className: 'bg-secondary-subtle text-secondary' },
    expired: { label: 'Expired', className: 'bg-danger-subtle text-danger' },
};

/**
 * A batch's standing, in form as well as words. An active batch whose date
 * has passed reads as expired before the nightly job has caught up.
 */
export function BatchStatusBadge({ batch }: { batch: Pick<MedicineBatch, 'status' | 'is_past_expiry'> }) {
    const key = batch.status === 'active' && batch.is_past_expiry ? 'expired' : batch.status;
    const tone = TONES[key] ?? TONES.active;

    return <span className={`badge ${tone.className}`}>{tone.label}</span>;
}

/** "in 12 days", "today", "3 days ago" — how near the expiry is. */
export function ExpiryNote({ days }: { days: number | null }) {
    if (days === null) {
        return null;
    }

    const text = days === 0 ? 'today' : days > 0 ? `in ${days} days` : `${-days} days ago`;
    const tone = days <= 0 ? 'text-danger' : days <= 90 ? 'text-warning' : 'text-muted';

    return <small className={`d-block ${tone}`}>{text}</small>;
}
