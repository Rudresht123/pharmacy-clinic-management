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

    /*
     * Dashboard stands alone above everything, under no heading.
     *
     * It is not part of "the business" or "the work" — it is where you land,
     * and filing it under a section made the first thing in the menu look like
     * a member of a group it has nothing to do with.
     */
    sections.push({
        title: '',
        items: [{ label: 'Dashboard', to: '/dashboard', icon: 'ti ti-layout-dashboard' }],
    });

    const organization: NavItem[] = [];

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
     * Anybody who administers staff has to be able to see the roles they are
     * assigning. Writing them is a separate question, answered per role on the
     * screen: the organization's are the owner's, a branch's are that
     * branch's.
     */
    if (can('people.view')) {
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

    if (organization.length > 0) {
        sections.push({ title: 'Organization', items: organization });
    }

    /* --- the work -------------------------------------------------------- */

    /*
     * OPD asks two questions, not one: the module has to be running here at
     * all, and this person has to be allowed to look at it.
     *
     * The board comes first and the queue under it, because that is the order
     * somebody arriving in the morning wants them — what is the state of the
     * place, then what do I do about it. The queue is a child rather than a
     * sibling: it is the same subject at a different depth.
     */
    const clinical: NavItem[] = [];

    if (hasModule('appointments') && can('appointments.view')) {
        clinical.push({
            label: 'OPD',
            to: '/opd',
            icon: 'ti ti-building-hospital',
            match: '/opd',
            children: [
                { label: 'Dashboard', to: '/opd', icon: 'ti ti-layout-dashboard' },
                {
                    label: 'Queue management',
                    to: '/opd/queue',
                    icon: 'ti ti-list-check',
                    match: '/opd/queue',
                },
            ],
        });
    }

    if (can('customers.view')) {
        clinical.push({
            label: labels?.customer ?? 'Patients',
            to: '/customers',
            icon: 'ti ti-users',
            match: '/customers',
        });
    }

    /*
     * Doctors and their timings sit with the work rather than in a section of
     * their own. They were split off into "Clinical setup" on the grounds that
     * nobody edits a weekly sitting mid-clinic — true, but it bought a whole
     * extra heading for two rows, and headings are what the menu had too many
     * of.
     */
    if (hasModule('appointments') && can('appointments.view')) {
        clinical.push(
            { label: 'Doctors', to: '/doctors', icon: 'ti ti-stethoscope', match: '/doctors' },
            {
                label: 'Availability',
                to: '/availability',
                icon: 'ti ti-calendar-time',
                match: '/availability',
            },
        );
    }

    if (clinical.length > 0) {
        sections.push({ title: 'Clinical', items: clinical });
    }

    /* --- what is coming -------------------------------------------------- */

    /*
     * One section, at the bottom, for everything not built yet.
     *
     * These used to be scattered through four headings of their own —
     * Pharmacy & inventory, Billing & payments, Reports — so seven of the
     * menu's fifteen rows were things nobody could click, filed as though they
     * were as real as the rest. Gathered and labelled honestly they answer
     * "where is billing" without pretending to be a working part of the
     * product, and the eight rows above them are all things that work.
     *
     * Prescriptions is the sharpest case: its module and both capabilities are
     * already in ModuleRegistry, so an organization can be sold it and a role
     * granted it while nothing at all sits behind them.
     */
    sections.push({
        title: 'Coming soon',
        items: [
            { label: 'Prescriptions', to: '/prescriptions', icon: 'ti ti-file-text', soon: true },
            { label: 'Lab tests', to: '/lab', icon: 'ti ti-microscope', soon: true },
            { label: 'Pharmacy', to: '/pharmacy', icon: 'ti ti-vaccine', soon: true },
            { label: 'Billing', to: '/billing', icon: 'ti ti-receipt', soon: true },
            { label: 'Reports', to: '/reports', icon: 'ti ti-chart-bar', soon: true },
        ],
    });

    return sections;
}
