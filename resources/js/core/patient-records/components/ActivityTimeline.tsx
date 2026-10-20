import { Fragment } from 'react';
import { useNavigate } from 'react-router-dom';
import { EVENT_ICON, eventPill, rupees, when } from '../labels';
import type { SectionKey, TimelineEvent } from '../types';
import { Pill } from './Bits';

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
 * The patient's history as one line down the page, newest first.
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
        <ol className="ptr-timeline">
            {events.map((event, index) => {
                const mark = EVENT_ICON[event.kind];
                const detail = detailOf(event);
                const month = monthOf(event.at);
                const newMonth = grouped && (index === 0 || monthOf(events[index - 1].at) !== month);

                return (
                    <Fragment key={event.key}>
                        {newMonth && <li className="ptr-timeline-month">{month}</li>}

                        <li className={`ptr-event is-${mark.tone}`}>
                            <span className="ptr-event-mark" aria-hidden="true">
                                <i className={mark.icon} />
                            </span>

                            <button
                                type="button"
                                className="ptr-event-box"
                                onClick={() => (event.link ? navigate(event.link) : onOpen(event.section))}
                            >
                                <span className="ptr-event-top">
                                    <b>{event.title}</b>
                                    <time dateTime={event.at ?? undefined}>{when(event.at, event.has_time)}</time>
                                </span>

                                {(event.doctor_name || event.location_name) && (
                                    <span className="ptr-event-who">
                                        {event.doctor_name}
                                        {event.location_name && (
                                            <small>
                                                {event.doctor_name ? ' · ' : ''}
                                                {event.location_name}
                                            </small>
                                        )}
                                    </span>
                                )}

                                <span className="ptr-event-bottom">
                                    <small className="ptr-event-detail">{detail}</small>
                                    <Pill pill={eventPill(event.kind, event.status)} />
                                </span>
                            </button>
                        </li>
                    </Fragment>
                );
            })}
        </ol>
    );
}
