import { useNavigate } from 'react-router-dom';
import { ActivityFeed } from '@/shared/components/ui/ActivityFeed';
import { EVENT_ICON, eventPill, rupees, when } from '../labels';
import type { SectionKey, TimelineEvent } from '../types';

/** The second line of an event — what happened, in the module's own terms. */
function detailOf(event: TimelineEvent): string | null {
    switch (event.kind) {
        case 'pharmacy':
            return [
                event.count !== null ? `${event.count} ${event.count === 1 ? 'item' : 'items'}` : null,
                event.amount !== null ? rupees(event.amount) : null,
            ]
                .filter(Boolean)
                .join(', ');
        case 'prescription':
            return event.count
                ? `${event.count} ${event.count === 1 ? 'medicine' : 'medicines'} prescribed`
                : event.detail;
        case 'invoice':
            return [event.detail, event.amount !== null ? rupees(event.amount) : null].filter(Boolean).join(' · ');
        default:
            return event.detail;
    }
}

function monthOf(value: string | null): string {
    if (!value) return '';

    return new Date(value).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

/**
 * The patient's history, newest first, in the activity feed every screen
 * shares — the dot and icon for what kind of event it is, who and where on
 * the line under it, the date, and its status as the pill.
 *
 * Each event opens where it lives in full — its tab here, or for a bill the
 * invoice itself — rather than a second copy of it. `grouped` adds a heading
 * per month, for the full timeline where the list runs long.
 */
export function ActivityTimeline({
    events,
    onOpen,
    grouped = false,
}: {
    events: TimelineEvent[];
    onOpen: (section: SectionKey) => void;
    grouped?: boolean;
}) {
    const navigate = useNavigate();

    return (
        <ActivityFeed
            items={events.map((event) => {
                const mark = EVENT_ICON[event.kind];
                const pill = eventPill(event.kind, event.status);

                return {
                    key: event.key,
                    icon: mark.icon,
                    tone: mark.tone,
                    title: event.title,
                    detail: [event.doctor_name, event.location_name, detailOf(event)].filter(Boolean).join(' · ') || undefined,
                    at: event.at,
                    // A history reads by its dates, not by "3 weeks ago".
                    when: when(event.at, event.has_time),
                    tag: pill ? { label: pill.label, tone: pill.tone } : null,
                    group: grouped ? monthOf(event.at) : undefined,
                    onOpen: () => (event.link ? navigate(event.link) : onOpen(event.section)),
                };
            })}
        />
    );
}
