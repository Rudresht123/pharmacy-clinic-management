import type { NavItem, NavSection } from './navigation';
import type { TenantUserRole } from '@/core/tenant-auth/api';

/**
 * An organization's own menu.
 *
 * Built from what the signed-in person may actually do, not filtered
 * afterwards: every entry names the capability its screen needs, and the
 * server refuses the same capability from the same service. Showing somebody
 * a link that answers 403 is worse than not showing it at all.
 *
 * Hiding is a courtesy, never the enforcement. Typing the URL still reaches
 * the route, and the route still refuses it — nothing here is load-bearing.
 *
 * Two sections, matching how a clinic thinks about itself: ORGANIZATION is
 * the business — its branches, its people, its rules; CLINICAL is the work.
 *
 * Every `to` has to be a real route in tenant-router.tsx, unless the entry is
 * marked `soon`, which renders as an unclickable row.
 */
export function tenantNavigation(
    role: TenantUserRole | undefined,
    /** What this organization calls each record; falls back to the default. */
    labels?: Partial<Record<string, string>>,
    /**
     * The modules running where this person works.
     *
     * Sold to the organization AND switched on at their branch. An entry for
     * a module that is not here is not merely hidden — the API refuses it too,
     * from the same source.
     */
    modules: string[] = [],
    /** What this person's role holds — level three, already resolved. */
    capabilities: string[] = [],
): NavSection[] {
    const isOwner = role === 'owner';
    const hasModule = (module: string) => modules.includes(module);
    const can = (capability: string) => capabilities.includes(capability);

    const sections: NavSection[] = [];

    /* --- the business ---------------------------------------------------- */

    const organization: NavItem[] = [
        { label: 'Dashboard', to: '/dashboard', icon: 'ti ti-layout-dashboard' },
    ];

    if (can('branches.view')) {
        organization.push({
            label: labels?.location ?? 'Branches',
            to: '/locations',
            icon: 'ti ti-building-store',
            // Keeps the parent highlighted on /locations/create and /:id/edit.
            match: '/locations',
        });
    }

    if (can('people.view')) {
        organization.push({
            label: labels?.user ?? 'Staff',
            to: '/people',
            icon: 'ti ti-users-group',
            match: '/people',
        });
    }

    /*
     * Roles are the owner's alone and not reachable through a capability: a
     * role able to edit roles could grant itself every other one, so there
     * would be nothing left for the other levels to decide.
     */
    if (isOwner) {
        organization.push({
            label: 'Roles & Permissions',
            to: '/roles',
            icon: 'ti ti-shield-lock',
            match: '/roles',
        });
    }

    /*
     * Planned, not built — no table, model or routes. Shown as a marked,
     * unclickable row so the shape of the product is visible without a menu
     * entry that answers 404.
     */
    if (isOwner) {
        organization.push({
            label: 'Departments',
            to: '/departments',
            icon: 'ti ti-layout-grid',
            soon: true,
        });
    }

    if (can('settings.manage')) {
        organization.push({
            label: 'Settings',
            to: '/settings/fields',
            icon: 'ti ti-settings',
            // Keeps the entry highlighted on every tab.
            match: '/settings/fields',
        });
    }

    if (can('settings.audit')) {
        organization.push({
            // The whole log needs this; one record's history is open to
            // anybody who can already see that record.
            label: 'Activity',
            to: '/activity',
            icon: 'ti ti-history',
            match: '/activity',
        });
    }

    sections.push({ title: 'Organization', items: organization });

    /* --- the work -------------------------------------------------------- */

    const clinical: NavItem[] = [];

    if (can('customers.view')) {
        clinical.push({
            label: labels?.customer ?? 'Patients',
            to: '/customers',
            icon: 'ti ti-users',
            match: '/customers',
        });
    }

    /*
     * OPD asks two questions, not one: the module has to be running here at
     * all, and this person has to be allowed to look at it.
     */
    if (hasModule('appointments') && can('appointments.view')) {
        clinical.push(
            { label: 'Doctors', to: '/doctors', icon: 'ti ti-stethoscope', match: '/doctors' },
            { label: 'Appointments', to: '/queue', icon: 'ti ti-calendar-event', match: '/queue' },
            {
                label: 'Availability',
                to: '/availability',
                icon: 'ti ti-calendar-time',
                match: '/availability',
            },
        );
    }

    /*
     * Planned, not built. Shown as marked, unclickable rows rather than links
     * to nowhere — a 404 from the menu reads as a broken product, while an
     * honest "Soon" answers "where is billing" before anybody asks. Each one
     * becomes a real entry when its module ships, guarded by hasModule() like
     * the OPD block above.
     */
    if (isOwner) {
        clinical.push(
            { label: 'Billing', to: '/billing', icon: 'ti ti-receipt', soon: true },
            { label: 'Inventory', to: '/inventory', icon: 'ti ti-package', soon: true },
            { label: 'Reports', to: '/reports', icon: 'ti ti-chart-bar', soon: true },
        );
    }

    if (clinical.length > 0) {
        sections.push({ title: 'Clinical', items: clinical });
    }

    return sections;
}
