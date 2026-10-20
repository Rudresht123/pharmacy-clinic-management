import { Fragment, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { formatRelative } from '@/shared/utils/format';

/**
 * "Recent activity", drawn one way everywhere it appears — the organization
 * and branch dashboards, the super admin's, the OPD board, a patient's record.
 *
 * Each row hangs on a thread: a dot in the entry's colour, a tinted icon, what
 * happened with the line under it, when, and a pill naming the area it belongs
 * to. Built after the dashboard reference; one component so the screens cannot
 * drift apart again.
 */

export type FeedTone = 'blue' | 'green' | 'amber' | 'violet' | 'rose' | 'teal' | 'grey';

export interface FeedItem {
    key: string | number;
    icon: string;
    tone: FeedTone;
    title: ReactNode;
    detail?: ReactNode;
    /** When it happened. Read as "6 hours ago" unless `when` says otherwise. */
    at?: string | null;
    /** The time already written out — a patient's history reads by date. */
    when?: string;
    tag?: { label: string; tone: FeedTone } | null;
    /** A heading the entry sits under — the month, on a long history. */
    group?: string;
    /** Where the entry opens, as a route or a handler. */
    to?: string;
    onOpen?: () => void;
}

export function ActivityFeed({ items, className }: { items: FeedItem[]; className?: string }) {
    // A written-out date needs a wider column than "6 hours ago".
    const dated = items.some((item) => item.when !== undefined);

    return (
        <ol className={`afeed${dated ? ' has-dates' : ''}${className ? ` ${className}` : ''}`}>
            {items.map((item, index) => {
                const heading = item.group && item.group !== items[index - 1]?.group ? item.group : null;
                const when = item.when ?? formatRelative(item.at);

                const body = (
                    <>
                        <span className={`afeed-icon is-${item.tone}`} aria-hidden="true">
                            <i className={item.icon} />
                        </span>

                        <span className="afeed-text">
                            <b>{item.title}</b>
                            {item.detail && <small>{item.detail}</small>}
                        </span>

                        <time
                            className="afeed-when"
                            dateTime={item.at ?? undefined}
                            title={item.at ? new Date(item.at).toLocaleString('en-IN') : undefined}
                        >
                            {when}
                        </time>

                        <span className="afeed-tag-slot">
                            {item.tag && <em className={`afeed-tag is-${item.tag.tone}`}>{item.tag.label}</em>}
                        </span>
                    </>
                );

                return (
                    <Fragment key={item.key}>
                        {heading && <li className="afeed-group">{heading}</li>}

                        <li className={`afeed-item is-${item.tone}`}>
                            <span className="afeed-dot" aria-hidden="true" />

                            {item.to ? (
                                <Link className="afeed-row is-link" to={item.to}>
                                    {body}
                                </Link>
                            ) : item.onOpen ? (
                                <button type="button" className="afeed-row is-link" onClick={item.onOpen}>
                                    {body}
                                </button>
                            ) : (
                                <div className="afeed-row">{body}</div>
                            )}
                        </li>
                    </Fragment>
                );
            })}
        </ol>
    );
}

/* ------------------------------------------------------------------------ */
/*  How each kind of record looks in a feed                                 */
/* ------------------------------------------------------------------------ */

interface Look {
    icon: string;
    tone: FeedTone;
    tag: string;
    tagTone: FeedTone;
}

const BILLING: Pick<Look, 'tag' | 'tagTone'> = { tag: 'Billing', tagTone: 'blue' };
const PHARMACY: Pick<Look, 'tag' | 'tagTone'> = { tag: 'Pharmacy', tagTone: 'green' };
const PEOPLE: Pick<Look, 'tag' | 'tagTone'> = { tag: 'User', tagTone: 'grey' };

/**
 * One look per record type, shared by every feed, so a bill is the same green
 * receipt with a "Billing" pill on the super admin's screen as on a branch's.
 * Keyed by the activity log's `entity_type`.
 */
const LOOKS: Record<string, Look> = {
    // Money
    Invoice: { icon: 'ti ti-file-invoice', tone: 'green', ...BILLING },
    Payment: { icon: 'ti ti-cash', tone: 'green', ...BILLING },
    Refund: { icon: 'ti ti-receipt-refund', tone: 'rose', ...BILLING },
    BillableService: { icon: 'ti ti-tag', tone: 'amber', ...BILLING },

    // Patients and the clinic floor
    Customer: { icon: 'ti ti-user-plus', tone: 'blue', tag: 'Patient', tagTone: 'teal' },
    Appointment: { icon: 'ti ti-calendar-event', tone: 'amber', tag: 'Appointment', tagTone: 'violet' },
    Consultation: { icon: 'ti ti-stethoscope', tone: 'blue', tag: 'OPD', tagTone: 'blue' },
    Prescription: { icon: 'ti ti-prescription', tone: 'teal', tag: 'Prescription', tagTone: 'teal' },
    LabOrder: { icon: 'ti ti-flask', tone: 'violet', tag: 'Lab', tagTone: 'violet' },
    LabResult: { icon: 'ti ti-flask', tone: 'violet', tag: 'Lab', tagTone: 'violet' },
    LabReport: { icon: 'ti ti-file-certificate', tone: 'violet', tag: 'Lab', tagTone: 'violet' },

    // The pharmacy
    PharmacySale: { icon: 'ti ti-shopping-cart', tone: 'rose', ...PHARMACY },
    PharmacySaleReturn: { icon: 'ti ti-arrow-back-up', tone: 'rose', ...PHARMACY },
    StockInward: { icon: 'ti ti-package-import', tone: 'green', ...PHARMACY },
    StockTransfer: { icon: 'ti ti-truck', tone: 'amber', ...PHARMACY },
    StockAdjustment: { icon: 'ti ti-adjustments', tone: 'amber', ...PHARMACY },
    Medicine: { icon: 'ti ti-pill', tone: 'rose', ...PHARMACY },

    // The organization and its people
    Doctor: { icon: 'ti ti-stethoscope', tone: 'teal', tag: 'Doctor', tagTone: 'green' },
    DoctorSchedule: { icon: 'ti ti-calendar-time', tone: 'teal', tag: 'Doctor', tagTone: 'green' },
    Location: { icon: 'ti ti-building-hospital', tone: 'violet', tag: 'Branch', tagTone: 'violet' },
    Department: { icon: 'ti ti-sitemap', tone: 'violet', tag: 'Branch', tagTone: 'violet' },
    BranchMembership: { icon: 'ti ti-id-badge-2', tone: 'blue', ...PEOPLE },
    User: { icon: 'ti ti-user', tone: 'grey', ...PEOPLE },
    Role: { icon: 'ti ti-shield', tone: 'grey', ...PEOPLE },

    // The platform, for the super admin
    Organization: { icon: 'ti ti-building', tone: 'blue', tag: 'Organization', tagTone: 'blue' },
    OrganizationModule: { icon: 'ti ti-box', tone: 'violet', tag: 'Module', tagTone: 'violet' },
    OrganizationType: { icon: 'ti ti-category', tone: 'teal', tag: 'Organization', tagTone: 'blue' },
    PlatformUser: { icon: 'ti ti-user-shield', tone: 'grey', ...PEOPLE },
    Subscription: { icon: 'ti ti-refresh', tone: 'amber', tag: 'Subscription', tagTone: 'amber' },
    SubscriptionPlan: { icon: 'ti ti-crown', tone: 'amber', tag: 'Subscription', tagTone: 'amber' },
};

/** "PharmacySaleReturn" → "Pharmacy sale return". */
function spoken(entity: string): string {
    const words = entity.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** The icon, colour and pill a record type is drawn with. */
export function activityLook(entityType: string): Look {
    return (
        LOOKS[entityType] ?? {
            icon: 'ti ti-point',
            tone: 'grey',
            tag: spoken(entityType).split(' ')[0],
            tagTone: 'grey',
        }
    );
}

/** A log entry's own sentence where the server sent none — "Patient added". */
export function activitySentence(event: string, entityType: string): string {
    const noun = spoken(
        { Customer: 'Patient', Location: 'Branch', Invoice: 'Bill', BranchMembership: 'Staff posting' }[entityType] ??
            entityType,
    );

    const verb: Record<string, string> = {
        created: 'added',
        updated: 'updated',
        deleted: 'removed',
        restored: 'restored',
    };

    return `${noun} ${verb[event] ?? event}`;
}

/** A feed entry for one activity-log row, in the shared look. */
export function feedItemFromLog(entry: {
    id: number | string;
    entity_type: string;
    event?: string | null;
    text?: string | null;
    detail?: (string | null | undefined)[];
    created_at: string | null;
}): FeedItem {
    const look = activityLook(entry.entity_type);
    const detail = (entry.detail ?? []).filter(Boolean).join(' · ');

    return {
        key: entry.id,
        icon: look.icon,
        tone: look.tone,
        title: entry.text || activitySentence(entry.event ?? 'updated', entry.entity_type),
        detail: detail || undefined,
        at: entry.created_at,
        tag: { label: look.tag, tone: look.tagTone },
    };
}
