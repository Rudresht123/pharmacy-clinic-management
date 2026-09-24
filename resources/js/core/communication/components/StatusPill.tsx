import type { CampaignStatus, DeliveryStatus, TemplateStatus } from '../types';

/**
 * Delivery states are five different answers and must not read as one.
 *
 * `read` is the end of the funnel and `delivered` one short of it, so they get
 * neighbouring tints rather than the same one — the difference between "it
 * arrived" and "they read it" is most of what a clinic wants to know.
 */
const DELIVERY: Record<DeliveryStatus, { tone: string; label: string }> = {
    queued: { tone: 'is-wait', label: 'Queued' },
    sent: { tone: 'is-wait', label: 'Sent' },
    delivered: { tone: 'is-live', label: 'Delivered' },
    read: { tone: 'is-read', label: 'Read' },
    failed: { tone: 'is-fail', label: 'Failed' },
};

const TEMPLATE: Record<TemplateStatus, { tone: string; label: string }> = {
    draft: { tone: 'is-muted', label: 'Draft' },
    pending: { tone: 'is-wait', label: 'Pending' },
    approved: { tone: 'is-live', label: 'Approved' },
    rejected: { tone: 'is-fail', label: 'Rejected' },
};

export function DeliveryPill({ status }: { status: DeliveryStatus }) {
    const state = DELIVERY[status] ?? DELIVERY.queued;

    return <span className={`comm-pill ${state.tone}`}>{state.label}</span>;
}

export function TemplatePill({ status }: { status: TemplateStatus }) {
    const state = TEMPLATE[status] ?? TEMPLATE.draft;

    return <span className={`comm-pill ${state.tone}`}>{state.label}</span>;
}

/** A category label — a classification, not a state, so it carries no dot. */
export function CategoryTag({ label }: { label: string }) {
    return <span className="comm-tag">{label}</span>;
}

/**
 * A campaign's seven states, in the order one passes through them.
 *
 * `paused` and `cancelled` sit apart from `failed` on purpose: somebody chose
 * those, nothing went wrong, and a red pill against a deliberate pause sends
 * people looking for a fault that is not there.
 */
const CAMPAIGN: Record<CampaignStatus, { tone: string; label: string }> = {
    draft: { tone: 'is-muted', label: 'Draft' },
    scheduled: { tone: 'is-wait', label: 'Scheduled' },
    sending: { tone: 'is-wait', label: 'Sending' },
    completed: { tone: 'is-live', label: 'Completed' },
    paused: { tone: 'is-muted', label: 'Paused' },
    cancelled: { tone: 'is-muted', label: 'Cancelled' },
    failed: { tone: 'is-fail', label: 'Failed' },
};

export function CampaignPill({ status }: { status: CampaignStatus }) {
    const state = CAMPAIGN[status] ?? CAMPAIGN.draft;

    return <span className={`comm-pill ${state.tone}`}>{state.label}</span>;
}
