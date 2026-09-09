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
 * Grouped by SCOPE, because that is the question somebody actually has when
 * they look at this menu: is this about my branch, or about the whole
 * organisation?
 *
 *   THE WORK       what happens at a branch — the OPD, its queue, its patients
 *   THIS BRANCH    who works here and what they may do
 *   ORGANISATION   things that mean nothing at one branch: the network's list
 *                  of sites, what every branch calls a patient, the audit log
 *
 * That last section is the important one. `settings.manage` changes what every
 * branch calls a patient; there is no version of it that applies to Lucknow
 * and not Delhi. `customers.view` is the opposite — it means "here". The
 * capability registry already records this as a `scope`, and the role screen
 * filters by it; this menu is the same distinction made visible.
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
    /** Set when this login belongs to a doctor, from the session. */
    doctorId?: number | null,
): NavSection[] {
    const isOwner = role === 'owner';

    /* A doctor login is a user whose record points at a doctor. */
    const isDoctor = Boolean(doctorId);
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
        items: [
            /*
             * A doctor lands on their own list, not the organization's.
             *
             * The dashboard counts branches, staff and revenue — a manager's
             * reading of the business. A doctor opening it is being shown
             * somebody else's job before their own.
             */
            isDoctor
                ? { label: 'My day', to: '/my-day', icon: 'ti ti-stethoscope', match: '/my-day' }
                : { label: 'Dashboard', to: '/dashboard', icon: 'ti ti-layout-dashboard' },
        ],
    });

    /*
     * Who works here, and what they may do.
     *
     * Branch-scoped both: a branch manager administers their own people and
     * writes their own branch's roles, and the server holds them to that
     * whatever this menu shows.
     */
    const here: NavItem[] = [];

    if (can('people.view')) {
        here.push({
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
        here.push({
            label: 'Roles & Permissions',
            to: '/roles',
            icon: 'ti ti-shield-lock',
            match: '/roles',
        });
    }

    /*
     * The network, not a branch.
     *
     * Everything here means nothing said of one site: the list of sites
     * itself, what every branch calls a patient, and the log of what happened
     * across all of them. A branch manager holds none of it, so for them the
     * heading does not appear at all rather than appearing empty.
     */
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

    /* --- a doctor's own section ------------------------------------------ */

    /*
     * A doctor's menu is their work, not the department's.
     *
     * They hold `appointments.queue` and `customers.view` and nothing wider, so
     * the OPD group below — the board, the desk's queue, the doctor registry,
     * the branch rota — never renders for them.
     *
     * Every entry here is scoped to the signed-in doctor by its endpoint. The
     * department's versions of the same screens exist already, behind
     * capabilities a doctor does not hold.
     */
    if (isDoctor) {
        const mine: NavItem[] = [
            { label: 'My queue', to: '/my-queue', icon: 'ti ti-list-numbers', match: '/my-queue' },
            {
                label: 'Consultations',
                to: '/my-consultations',
                icon: 'ti ti-clipboard-text',
                match: '/my-consultations',
            },
        ];

        if (can('customers.view')) {
            mine.push({
                label: labels?.customer ?? 'Patients',
                to: '/customers',
                icon: 'ti ti-users',
                match: '/customers',
            });
        }

        mine.push(
            {
                label: 'Appointments',
                to: '/my-appointments',
                icon: 'ti ti-calendar',
                match: '/my-appointments',
            },
            {
                label: 'Prescriptions',
                to: '/my-prescriptions',
                icon: 'ti ti-file-text',
                match: '/my-prescriptions',
            },
            {
                label: 'Investigations',
                to: '/my-investigations',
                icon: 'ti ti-microscope',
                match: '/my-investigations',
            },
            {
                label: 'Follow-ups',
                to: '/my-follow-ups',
                icon: 'ti ti-calendar-repeat',
                match: '/my-follow-ups',
            },
            {
                label: 'My schedule',
                to: '/my-schedule',
                icon: 'ti ti-clock-hour-4',
                match: '/my-schedule',
            },

            /*
             * The last one still to build. Attaching scans and reports needs a
             * file store wired to a visit, which is its own piece of work — and
             * a row that answered 404 would be worse than one that says so.
             */
            { label: 'Documents', to: '/documents', icon: 'ti ti-folder', soon: true },
        );

        sections.push({ title: 'My work', items: mine });

        /*
         * And nothing else. A doctor has no branches to run, no staff to
         * administer and no field settings to change — returning here rather
         * than falling through means none of those sections can appear by
         * accident as capabilities move around.
         */
        return sections;
    }

    /* --- the work -------------------------------------------------------- */

    /*
     * OPD asks two questions, not one: the module has to be running here at
     * all, and this person has to be allowed to look at it.
     *
     * All four screens hang off the one entry, in the order somebody works
     * through them: what is the state of the place, what do I do about it, who
     * is doing it, and when are they here. They were a parent and two loose
     * siblings, which read as three unrelated subjects when they are one — and
     * the loose pair sat below Patients, so the menu put a doctor's timetable
     * further from the OPD board than the patient list was.
     *
     * The paths stay where they are. Nesting the routes under /opd to give the
     * group one prefix would be moving four URLs, and every link and bookmark
     * to them, to tidy a menu; `match` takes the four instead.
     */
    const clinical: NavItem[] = [];

    if (hasModule('appointments') && can('appointments.view')) {
        clinical.push({
            label: 'OPD',
            to: '/opd',
            icon: 'ti ti-building-hospital',
            match: ['/opd', '/doctors', '/schedules', '/availability'],
            children: [
                { label: 'Dashboard', to: '/opd', icon: 'ti ti-layout-dashboard' },
                {
                    label: 'Queue management',
                    to: '/opd/queue',
                    icon: 'ti ti-list-check',
                    match: '/opd/queue',
                },
                {
                    label: 'Doctors',
                    to: '/doctors',
                    icon: 'ti ti-stethoscope',
                    match: '/doctors',
                },
                /*
                 * Directly under Doctors, because it is the same subject one
                 * step on: who they are, then when they sit. Its own capability
                 * too — a receptionist who may look at the roster is not
                 * necessarily somebody who may rewrite it.
                 */
                ...(can('appointments.schedule')
                    ? [
                          {
                              label: 'Schedules',
                              to: '/schedules',
                              icon: 'ti ti-calendar-cog',
                              match: '/schedules',
                          },
                      ]
                    : []),
                {
                    label: 'Availability',
                    to: '/availability',
                    icon: 'ti ti-calendar-time',
                    match: '/availability',
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
     * The work comes first.
     *
     * Everybody who signs in does it; the two sections under it are the
     * settings behind it, which most people open once a month. A menu ordered
     * by how often a row is pressed beats one ordered by hierarchy.
     */
    if (clinical.length > 0) {
        sections.push({ title: 'Clinical', items: clinical });
    }

    if (here.length > 0) {
        sections.push({ title: 'This branch', items: here });
    }

    if (organization.length > 0) {
        sections.push({ title: 'Organisation', items: organization });
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
