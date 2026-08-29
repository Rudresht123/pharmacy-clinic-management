import { cn } from '@/shared/utils/cn';
import type { OrganizationStatus } from '../types';

interface Descriptor {
    label: string;
    /** Maps to a .org-status--* rule in vendor/css/table.css. */
    variant: string;
    title: string;
}

/**
 * How each lifecycle state reads to a human — Build Spec §5, §9.
 *
 * The titles are deliberately plain: an admin scanning this column should not
 * have to remember the difference between cancelled and suspended.
 */
const STATUSES: Record<OrganizationStatus, Descriptor> = {
    pending: {
        label: 'Pending',
        variant: 'muted',
        title: 'Created, but nothing has been provisioned yet.',
    },
    provisioning: {
        label: 'Provisioning',
        variant: 'sky',
        title: 'The tenant database is being created.',
    },
    active: {
        label: 'Active',
        variant: 'emerald',
        title: 'Live. The organization can sign in.',
    },
    suspended: {
        label: 'Suspended',
        variant: 'amber',
        title: 'Logins are blocked. Data is untouched, and this is reversible.',
    },
    cancelled: {
        label: 'Cancelled',
        variant: 'muted',
        title: 'Subscription closed. Read-only, then blocked.',
    },
    failed: {
        label: 'Failed',
        variant: 'rose',
        title: 'Provisioning stopped part-way. It can be retried.',
    },
};

export function OrganizationStatusBadge({ status }: { status: OrganizationStatus }) {
    // An unrecognised value still renders rather than crashing the row — the
    // server owns this list and may add to it before the front end knows.
    const descriptor = STATUSES[status] ?? {
        label: status,
        variant: 'muted',
        title: status,
    };

    return (
        <span
            className={cn('org-status', `org-status--${descriptor.variant}`)}
            title={descriptor.title}
        >
            <span className="org-status-dot" />
            {descriptor.label}
        </span>
    );
}
