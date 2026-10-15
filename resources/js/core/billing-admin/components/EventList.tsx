import type { SubscriptionEvent } from '../api';
import { EVENT_LABELS } from '../labels';

/** A subscription's history: what happened, who did it, and why. */
export function EventList({ events, showOrganization = false }: { events: SubscriptionEvent[]; showOrganization?: boolean }) {
    return (
        <ul className="sxa-events">
            {events.map((event) => (
                <li key={event.id}>
                    <b>{EVENT_LABELS[event.type] ?? event.type}</b>
                    {showOrganization && event.organization && <span className="text-muted"> · {event.organization}</span>}
                    {event.to_status && event.from_status && event.from_status !== event.to_status && (
                        <span className="text-muted">
                            {' '}
                            · {event.from_status} → {event.to_status}
                        </span>
                    )}
                    {event.reason && <div>{event.reason}</div>}
                    <small>
                        {new Date(event.occurred_at).toLocaleString('en-IN')} ·{' '}
                        {event.actor_type === 'system' ? (event.actor_name ?? 'System') : (event.actor_name ?? event.actor_type)}
                    </small>
                </li>
            ))}
        </ul>
    );
}
