import { useMemo } from 'react';
import { formatDate } from '@/shared/utils/format';

export interface HistoryChange {
    field: string;
    from: unknown;
    to: unknown;
}

export interface HistoryEntry {
    id: number;
    event: string;
    entity_type: string;
    entity_id: number | null;
    entity_label: string | null;
    actor_name: string | null;
    actor_type: string;
    changes: HistoryChange[];
    ip_address: string | null;
    created_at: string | null;
}

/** How each event presents itself. Anything unknown still renders. */
const EVENTS: Record<string, { icon: string; tone: string; verb: string }> = {
    created: { icon: 'ti ti-plus', tone: 'emerald', verb: 'created' },
    updated: { icon: 'ti ti-pencil', tone: 'sky', verb: 'changed' },
    deleted: { icon: 'ti ti-trash', tone: 'rose', verb: 'removed' },
    restored: { icon: 'ti ti-arrow-back-up', tone: 'amber', verb: 'restored' },
};

/** `drug_license_expiry_date` reads as "Drug licence expiry date". */
function humanise(field: string): string {
    const words = field
        .replace(/_id$/, '')
        .replace(/_/g, ' ')
        .replace(/\blicense\b/g, 'licence')
        .trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A value as somebody reading a log wants to see it.
 *
 * Booleans as words, empty as "not set" — an empty cell beside an arrow
 * gives no way to tell "cleared" from "the screen failed to render it".
 */
function readValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return 'not set';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

/** "14:32" — the day is on the group heading, so the row only needs a time. */
function clockTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

/** "Today", "Yesterday", or the date. */
function dayHeading(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);
    const midnight = new Date().setHours(0, 0, 0, 0);
    const days = Math.floor((midnight - new Date(value).setHours(0, 0, 0, 0)) / 86_400_000);

    if (days === 0) {
        return 'Today';
    }

    if (days === 1) {
        return 'Yesterday';
    }

    return formatDate(date.toISOString());
}

/**
 * What happened to a record, newest first.
 *
 * Grouped by day, because that is how anybody reads a log: they arrive with
 * "what changed yesterday", not with a timestamp. The day is said once on a
 * sticky heading and each row then carries only a time, which is what lets
 * the rows stay short enough to scan.
 *
 * The same component serves one record's own story and the whole log — they
 * differ only in whether the subject is worth naming on every row, which is
 * what `showSubject` decides.
 *
 * Values read as `was → is`, because an audit trail that only says what
 * something became cannot answer the question people actually arrive with.
 */
export function HistoryTimeline({
    entries,
    showSubject = false,
    empty = 'Nothing has happened yet.',
}: {
    entries: HistoryEntry[];
    /** On the global log, where each row is about a different thing. */
    showSubject?: boolean;
    empty?: string;
}) {
    const days = useMemo(() => {
        const buckets = new Map<string, HistoryEntry[]>();

        for (const entry of entries) {
            const key = dayHeading(entry.created_at);
            buckets.set(key, [...(buckets.get(key) ?? []), entry]);
        }

        return [...buckets.entries()];
    }, [entries]);

    if (entries.length === 0) {
        return (
            <div className="ht-empty">
                <i className="ti ti-history-off" aria-hidden="true" />
                <b>No history</b>
                <span>{empty}</span>
            </div>
        );
    }

    return (
        <div className="ht">
            {days.map(([day, rows]) => (
                <section className="ht-day" key={day}>
                    <h6 className="ht-day-head">
                        <span>{day}</span>
                        <small>
                            {rows.length} change{rows.length === 1 ? '' : 's'}
                        </small>
                    </h6>

                    <ol className="ht-list">
                        {rows.map((entry) => {
                            const event = EVENTS[entry.event] ?? {
                                icon: 'ti ti-point',
                                tone: 'muted',
                                verb: entry.event,
                            };

                            return (
                                <li className="ht-item" key={entry.id}>
                                    <span className={`ht-dot is-${event.tone}`} aria-hidden="true">
                                        <i className={event.icon} />
                                    </span>

                                    <div className="ht-body">
                                        <p className="ht-line">
                                            <b>{entry.actor_name ?? 'The system'}</b>{' '}
                                            <span className="ht-verb">{event.verb}</span>
                                            {showSubject && (
                                                <>
                                                    {' '}
                                                    <span className="ht-subject">
                                                        {entry.entity_label ??
                                                            `#${entry.entity_id}`}
                                                    </span>
                                                    <span className="ht-kind">
                                                        {entry.entity_type}
                                                    </span>
                                                </>
                                            )}
                                            {/*
                                             * A platform administrator acting
                                             * inside an organization is a
                                             * different person from its own
                                             * staff, and the row must say so.
                                             */}
                                            {entry.actor_type === 'platform' && (
                                                <span className="ht-kind is-platform">
                                                    platform
                                                </span>
                                            )}
                                        </p>

                                        {entry.changes.length > 0 && (
                                            <ul className="ht-changes">
                                                {entry.changes.map((change) => (
                                                    <li key={change.field}>
                                                        <span className="ht-field">
                                                            {humanise(change.field)}
                                                        </span>

                                                        {/*
                                                         * A creation has no
                                                         * "from", so "not set
                                                         * →" on every field
                                                         * would be noise.
                                                         */}
                                                        {entry.event === 'created' ? (
                                                            <span className="ht-to">
                                                                {readValue(change.to)}
                                                            </span>
                                                        ) : (
                                                            <>
                                                                <span className="ht-from">
                                                                    {readValue(change.from)}
                                                                </span>
                                                                <i
                                                                    className="ti ti-arrow-narrow-right"
                                                                    aria-hidden="true"
                                                                />
                                                                <span className="ht-to">
                                                                    {readValue(change.to)}
                                                                </span>
                                                            </>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>

                                    <time
                                        className="ht-when"
                                        dateTime={entry.created_at ?? undefined}
                                        title={entry.ip_address ?? undefined}
                                    >
                                        {clockTime(entry.created_at)}
                                    </time>
                                </li>
                            );
                        })}
                    </ol>
                </section>
            ))}
        </div>
    );
}
