import type { DoctorAvailability, QueuePriority } from '../types';

/**
 * A priority, in a word and an icon. Normal says nothing: most of a queue is
 * normal, and a column of "Normal" would hide the two that matter.
 */
export function PriorityBadge({ priority, reason }: { priority: QueuePriority; reason?: string | null }) {
    if (priority === 'normal') {
        return null;
    }

    return (
        <span className={`sq-priority is-${priority}`} title={reason ?? undefined}>
            <i className={priority === 'emergency' ? 'ti ti-urgent' : 'ti ti-alert-triangle'} aria-hidden="true" />
            {priority === 'emergency' ? 'Emergency' : 'Urgent'}
        </span>
    );
}

const AVAILABILITY: Record<DoctorAvailability, { label: string; tone: string }> = {
    in_session: { label: 'In session', tone: 'is-on' },
    later: { label: 'Later today', tone: 'is-later' },
    finished: { label: 'Finished', tone: 'is-off' },
    off: { label: 'Not sitting', tone: 'is-off' },
};

/** Where the doctor is in their day, and whether their queue is calling. */
export function QueueStateBadge({
    paused,
    availability,
    startsAt,
}: {
    paused: boolean;
    availability: DoctorAvailability;
    startsAt?: string | null;
}) {
    if (paused) {
        return (
            <span className="sq-state is-paused">
                <i className="ti ti-player-pause" aria-hidden="true" />
                Paused
            </span>
        );
    }

    const { label, tone } = AVAILABILITY[availability];

    return (
        <span className={`sq-state ${tone}`}>
            <i aria-hidden="true" className="sq-dot" />
            {availability === 'later' && startsAt ? `From ${startsAt}` : label}
        </span>
    );
}
