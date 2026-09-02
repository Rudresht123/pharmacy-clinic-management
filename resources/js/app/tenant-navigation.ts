import type { NavSection } from './navigation';
import type { TenantUserRole } from '@/core/tenant-auth/api';

/**
 * An organization's own menu, kept separate from the platform panel's.
 *
 * Built from the signed-in role rather than filtered afterwards: People and
 * the field settings are owner-only on the server, and showing a staff
 * member a link that answers 403 is worse than not showing it at all.
 *
 * Every `to` has to be a real route in tenant-router.tsx.
 */
export function tenantNavigation(
    role: TenantUserRole | undefined,
    /** What this organization calls each record; falls back to the default. */
    labels?: Partial<Record<string, string>>,
): NavSection[] {
    const isOwner = role === 'owner';

    const sections: NavSection[] = [
        {
            title: 'Main',
            items: [
                { label: 'Dashboard', to: '/dashboard', icon: 'ti ti-layout-dashboard' },
                {
                    label: labels?.customer ?? 'Customers',
                    to: '/customers',
                    icon: 'ti ti-users',
                    match: '/customers',
                },
            ],
        },
        {
            title: 'Network',
            items: [
                {
                    label: labels?.location ?? 'Locations',
                    to: '/locations',
                    icon: 'ti ti-building-store',
                    // Keeps the parent highlighted on /locations/create and /:id/edit.
                    match: '/locations',
                },
            ],
        },
    ];

    if (!isOwner) {
        return sections;
    }

    return [
        ...sections,
        {
            title: 'Organization',
            items: [
                {
                    label: labels?.user ?? 'People',
                    to: '/people',
                    icon: 'ti ti-users-group',
                    match: '/people',
                },
                {
                    label: 'Field Settings',
                    to: '/settings/fields',
                    icon: 'ti ti-adjustments',
                    // Keeps the entry highlighted on every tab.
                    match: '/settings/fields',
                },
            ],
        },
    ];
}
