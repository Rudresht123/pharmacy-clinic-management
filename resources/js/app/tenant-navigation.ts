import type { NavItem, NavSection } from './navigation';
import type { TenantUserRole } from '@/core/tenant-auth/api';
import { EXPORT_CAPABILITIES } from '@/core/exports/capabilities';

/**
 * The six reports, each its own capability — sold as its own module
 * (`reports`), separate from the rest of the pharmacy. Kept here rather than
 * imported from the reports screen itself, which the menu must not depend on.
 */
const REPORT_CAPABILITIES = [
    'reports.sales',
    'reports.purchases',
    'reports.stock',
    'reports.expiry',
    'reports.profit',
    'reports.gst',
];

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
    /** Until organisation setup is finished, the setup is the whole menu. */
    setupCompleted = true,
): NavSection[] {
    const isOwner = role === 'owner';

    /* A doctor login is a user whose record points at a doctor. */
    const isDoctor = Boolean(doctorId);
    const hasModule = (module: string) => modules.includes(module);
    const can = (capability: string) => capabilities.includes(capability);

    const sections: NavSection[] = [];

    /*
     * Nothing else opens until setup is finished (see TenantProtectedRoute),
     * so nothing else is offered: a menu full of entries that all lead back
     * to the setup screen would read as broken.
     */
    if (!setupCompleted) {
        return isOwner
            ? [
                  {
                      title: '',
                      items: [
                          {
                              label: 'Organisation setup',
                              to: '/setup',
                              icon: 'ti ti-list-check',
                              match: '/setup',
                          },
                      ],
                  },
              ]
            : [];
    }

    /* --- the business ---------------------------------------------------- */

    /*
     * Dashboard stands alone above everything, under no heading.
     *
     * It is not part of "the business" or "the work" — it is where you land,
     * and filing it under a section made the first thing in the menu look like
     * a member of a group it has nothing to do with.
     */
    /*
     * Each behind its own tick: what a role is given is what it sees, and a
     * role made for one job (only chat, only the lab) lands on that job rather
     * than on a dashboard it was never meant to read. "My day" also needs a
     * login that belongs to a doctor — that is data, not permission: there is
     * no day to show anybody else.
     */
    const landing: NavItem[] = [];

    if (isDoctor && hasModule('appointments') && can('appointments.my_day')) {
        landing.push({ label: 'My day', to: '/my-day', icon: 'ti ti-stethoscope', match: '/my-day' });
    }

    if (can('dashboard.view')) {
        landing.push({ label: 'Dashboard', to: '/dashboard', icon: 'ti ti-layout-dashboard' });
    }

    if (landing.length > 0) {
        sections.push({ title: '', items: landing });
    }

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
     * Its own tick, apart from the staff list. Writing a role is a separate
     * question again, answered per role on the screen: the organization's are
     * the owner's, a branch's are that branch's.
     */
    if (can('people.roles_view')) {
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
     * Organisation setup, and the departments it keeps.
     *
     * Departments are the doctor form's own list rather than a table, and
     * the setup screen is where they are edited, so the entry opens it on
     * that section. Both are the owner's.
     */
    if (isOwner) {
        organization.push({
            label: 'Organisation setup',
            to: '/setup',
            icon: 'ti ti-list-check',
            match: '/setup',
        });

    }

    /*
     * SETTINGS, WHOLE.
     *
     * Everything that configures the software rather than does the day's work
     * hangs off this one entry — what a patient record is called, what a
     * printed bill looks like, when the clinic bills, how the pharmacy
     * prices. They used to be scattered: field settings and departments up
     * here, the letterhead beside them, billing's own settings buried inside
     * the Billing group and the pharmacy's inside Pharmacy. Somebody looking
     * for "where do I change this" had four places to look and no way to
     * guess which.
     *
     * Each child still carries its OWN capability and module, so the group
     * shows only what this person may actually open — and the two settings
     * rows that used to live inside the Pharmacy and Billing groups are gone
     * from there, because a row appearing in two menus reads as two
     * different screens.
     *
     * The parent opens the first child rather than a landing page: there is
     * no "settings overview" screen, and a lid that leads nowhere is worse
     * than one that leads to the commonest thing under it.
     */
    const settings: NavItem[] = [];

    if (can('settings.manage')) {
        settings.push({
            label: 'Fields & naming',
            to: '/settings/fields',
            icon: 'ti ti-forms',
            // Keeps it highlighted on every entity tab.
            match: '/settings/fields',
        });

        // Departments and their sub-departments — the doctor form's own list.
        settings.push({
            label: 'Departments',
            to: '/departments',
            icon: 'ti ti-layout-grid',
            match: '/departments',
        });
    }

    /*
     * Which modules run where: the organisation's own switch, each branch's,
     * and which are compulsory. The owner's alone — no capability reaches it,
     * because a role able to switch a module on could re-open a door the
     * owner closed.
     */
    if (isOwner) {
        settings.push({
            label: 'Modules',
            to: '/settings/modules',
            icon: 'ti ti-puzzle',
            match: '/settings/modules',
        });
    }

    /*
     * The letterhead. A different capability from `settings.manage` on
     * purpose — a branch manager may redesign their own branch's printed
     * documents while holding none of the organisation's field settings.
     */
    if (hasModule('documents') && can('documents.template_view')) {
        settings.push({
            label: 'Document templates',
            to: '/settings/document-templates',
            icon: 'ti ti-file-text',
            match: '/settings/document-templates',
        });
    }

    /*
     * Which documents make themselves — a receipt on every payment, a slip on
     * every refund. Beside the letterhead because it is the same subject, but
     * its own capability: deciding what every branch's counter prints is an
     * organisation decision, where the letterhead may be a branch's own.
     */
    if (hasModule('documents') && can('documents.template_org')) {
        settings.push({
            label: 'Automatic documents',
            to: '/settings/document-automation',
            icon: 'ti ti-bolt',
            match: '/settings/document-automation',
        });
    }

    /*
     * When the clinic bills, what a patient may leave owing, which methods
     * the till takes. Set once and then left alone, like everything else
     * here — which is why it is here and no longer inside the Billing group,
     * whose rows are the screens a counter works all day.
     *
     * `billing.manage_settings` rather than `billing.view`: the route still
     * lets a reader open the page, but a menu row is an invitation, and the
     * page only lets its holder save. The price list is not a row of its
     * own; the page links to it.
     */
    if (hasModule('billing') && can('billing.manage_settings')) {
        settings.push({
            label: 'Billing',
            to: '/settings/billing',
            icon: 'ti ti-receipt',
            match: '/settings/billing',
        });
    }

    /*
     * Who runs the queue — the desk, the doctor or either, step by step — and
     * who hears about reschedule requests. Not every clinic has a
     * receptionist.
     */
    if (hasModule('appointments') && can('appointments.settings')) {
        settings.push({
            label: 'Appointments',
            to: '/settings/appointments',
            icon: 'ti ti-calendar-cog',
            match: '/settings/appointments',
        });
    }

    /*
     * How the Smart Queue is set up: who sits in which room and what their tokens
     * read, then the televisions. Set once and left alone, which is what makes
     * them settings; running the queue itself lives under OPD.
     */
    if (hasModule('queue') && can('queue.settings')) {
        settings.push({
            label: 'Queue settings',
            to: '/settings/queue',
            icon: 'ti ti-adjustments',
            match: '/settings/queue',
        });
    }

    if (hasModule('queue') && can('queue.displays')) {
        settings.push({
            label: 'TV displays',
            to: '/settings/tv-displays',
            icon: 'ti ti-device-tv',
            match: '/settings/tv-displays',
        });
    }

    /*
     * How the shop prices, rounds and warns.
     *
     * The condition is spelled out rather than reusing the `pharmacy` const
     * below — that one is declared further down, with the clinical group it
     * belongs to, and a `const` read before its declaration is a runtime
     * ReferenceError rather than an undefined.
     */
    const runsPharmacy = hasModule('pharmacy') && can('pharmacy.view');

    if (runsPharmacy) {
        settings.push({
            label: 'Pharmacy',
            to: '/pharmacy/settings',
            icon: 'ti ti-pill',
            match: '/pharmacy/settings',
        });
    }

    if (settings.length > 0) {
        organization.push({
            label: 'Settings',
            /* Opens the commonest thing under it — there is no settings
               overview screen to land on. */
            to: settings[0].to,
            icon: 'ti ti-settings',
            match: [
                '/settings',
                ...(can('settings.manage') ? ['/departments'] : []),
                ...(runsPharmacy ? ['/pharmacy/settings'] : []),
            ],
            children: settings,
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

    if (can('settings.subscription')) {
        organization.push({
            // The organization's own plan, usage, invoices and renewal —
            // the owner's, and a billing contact's if they name one.
            label: 'Subscription',
            to: '/subscription',
            icon: 'ti ti-crown',
            match: '/subscription',
        });
    }

    /* --- a doctor's own section ------------------------------------------ */

    /*
     * A doctor's own screens, one tick each.
     *
     * Every entry here is scoped to the signed-in doctor by its endpoint, so
     * it needs a doctor's login to have anything in it — but whether it shows
     * is the role's to say, the same as everything below. Nothing returns
     * early any more: a doctor whose role is also given the desk's screens
     * sees those too, and one given less sees less.
     *
     * Documents are a TAB on the consultation rather than a screen of their
     * own: a scan is read while the patient is in the room.
     */
    if (isDoctor && hasModule('appointments')) {
        const mine: NavItem[] = [];

        if (hasModule('queue') && can('queue.my_console')) {
            mine.push({ label: 'My live queue', to: '/my-live-queue', icon: 'ti ti-broadcast', match: '/my-live-queue' });
        }

        if (can('appointments.my_queue')) {
            mine.push(
                { label: 'My queue', to: '/my-queue', icon: 'ti ti-list-numbers', match: '/my-queue' },
                {
                    label: 'Consultations',
                    to: '/my-consultations',
                    icon: 'ti ti-clipboard-text',
                    match: '/my-consultations',
                },
            );
        }

        if (can('appointments.my_patients')) {
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
            );
        }

        if (can('appointments.my_schedule')) {
            mine.push({
                label: 'My schedule',
                to: '/my-schedule',
                icon: 'ti ti-clock-hour-4',
                match: '/my-schedule',
            });
        }

        if (mine.length > 0) {
            sections.push({ title: 'My work', items: mine });
        }
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

    /*
     * The pharmacy is one menu, and it borrows two rows from elsewhere.
     *
     * The module owns its stock, its counter and its paperwork, but a shop's
     * catalogue is the medicines module and its buyers are the customers
     * module. Both are decided here, once, because the question is not only
     * "may this person see it" but "which menu is already showing it" — a row
     * that appears in two places reads as two different screens.
     */
    const pharmacy = hasModule('pharmacy') && can('pharmacy.view');
    const medicines = hasModule('medicines') && can('medicines.view');

    /*
     * Sold as its own module, so it must not need `pharmacy.view` to be
     * reachable — a role can hold every report and nothing else the counter
     * does. Whether it sits inside the Pharmacy group or stands on its own
     * depends only on whether that group is rendering at all (below).
     */
    const reports = hasModule('reports') && REPORT_CAPABILITIES.some((capability) => can(capability));

    /*
     * A medical store's buyers belong in the only menu it has.
     *
     * With no OPD there is no clinical section for the customer book to sit
     * beside, so it moves into the pharmacy group and takes the name a shop
     * uses. A clinic keeps its patients where they are — this is the same
     * list either way, and it is never offered twice.
     */
    const customersUnderPharmacy =
        pharmacy && !hasModule('appointments') && can('customers.view');

    /*
     * One tick per screen: the board, the queue, the roster and the rota are
     * four different things to be allowed, and the group shows whichever of
     * them this role holds — opening on the first.
     */
    const opd: NavItem[] = [];

    if (hasModule('appointments')) {
        if (can('appointments.view')) {
            opd.push({ label: 'Dashboard', to: '/opd', icon: 'ti ti-layout-dashboard' });
        }

        if (can('appointments.queue_view')) {
            opd.push({
                label: 'Queue management',
                to: '/opd/queue',
                icon: 'ti ti-list-check',
                match: '/opd/queue',
            });
        }

        // Every doctor's queue at once: sold as its own module, opened by its own tick.
        if (hasModule('queue') && can('queue.board')) {
            opd.push({
                label: 'Live queue',
                to: '/opd/live-queue',
                icon: 'ti ti-broadcast',
                match: '/opd/live-queue',
            });
        }

        // Patients asking, from the app, to move a booking.
        if (can('appointments.reschedule')) {
            opd.push({
                label: 'Reschedule requests',
                to: '/opd/reschedule-requests',
                icon: 'ti ti-calendar-repeat',
                match: '/opd/reschedule-requests',
            });
        }

        if (can('appointments.doctors_view')) {
            opd.push({ label: 'Doctors', to: '/doctors', icon: 'ti ti-stethoscope', match: '/doctors' });
        }

        /*
         * Directly under Doctors, because it is the same subject one step on:
         * who they are, then when they sit. Its own capability — looking at
         * the roster is not rewriting it.
         */
        if (can('appointments.schedule')) {
            opd.push({ label: 'Schedules', to: '/schedules', icon: 'ti ti-calendar-cog', match: '/schedules' });
        }

        if (can('appointments.doctors_view')) {
            opd.push({
                label: 'Availability',
                to: '/availability',
                icon: 'ti ti-calendar-time',
                match: '/availability',
            });
        }
    }

    if (opd.length > 0) {
        clinical.push({
            label: 'OPD',
            to: opd[0].to,
            icon: 'ti ti-building-hospital',
            match: ['/opd', '/doctors', '/schedules', '/availability'],
            children: opd,
        });
    }

    if (can('customers.view') && !customersUnderPharmacy) {
        clinical.push({
            label: labels?.customer ?? 'Patients',
            to: '/customers',
            icon: 'ti ti-users',
            match: '/customers',
        });
    }

    /*
     * Directly under the register: one patient's appointments, visits,
     * prescriptions, reports, pharmacy and bills in one place. Its own tick;
     * each tab inside is then gated on its own module.
     */
    if (can('customers.records_view') && !customersUnderPharmacy) {
        clinical.push({
            label: 'Patient Records',
            to: '/patient-records',
            icon: 'ti ti-file-search',
            match: '/patient-records',
        });
    }

    /*
     * The medicine master, on its own, for anybody who has it without a
     * pharmacy.
     *
     * It stays: a clinic that dispenses nothing still needs its doctors to
     * find a medicine to prescribe, and that clinic has no pharmacy heading to
     * look under. It steps aside only when the pharmacy group below is on
     * screen and already carries it as Products — which also covers somebody
     * who has the pharmacy module but not `pharmacy.view`, where that group
     * never renders and this entry is their only way to the list.
     */
    if (medicines && !pharmacy) {
        clinical.push({
            label: labels?.medicine ?? 'Medicines',
            to: '/medicines',
            icon: 'ti ti-pill',
            match: '/medicines',
        });
    }

    /*
     * The pharmacy, whole.
     *
     * Everything the shop does hangs off this one entry, module-wise, rather
     * than being spread between a loose Medicines row up in the clinical work
     * and a Pharmacy group below it — which left a medical store's own
     * catalogue outside its own menu.
     *
     * The parent opens the overview rather than the stock list: a group header
     * here is a link as well as a lid, and "the pharmacy" is a dashboard
     * before it is a shelf.
     */
    if (pharmacy) {
        clinical.push({
            label: 'Pharmacy',
            to: '/pharmacy/dashboard',
            icon: 'ti ti-building-warehouse',
            /*
             * Two of the group's screens are other modules' routes, so the
             * /pharmacy prefix alone would leave the parent dark on them. Each
             * is claimed only when this menu is the one showing that row.
             */
            match: [
                '/pharmacy',
                ...(medicines ? ['/medicines'] : []),
                ...(customersUnderPharmacy ? ['/customers'] : []),
            ],
            /*
             * In the order a shop is run: how it is doing, what it sells, what
             * is on the shelf, what came in, the counter, what it billed, what
             * moved — then the people and the set-up behind all of it.
             */
            children: [
                {
                    label: 'Dashboard',
                    to: '/pharmacy/dashboard',
                    icon: 'ti ti-layout-dashboard',
                    match: '/pharmacy/dashboard',
                },

                /*
                 * The medicine master under the name a counter uses for it.
                 * Its own module and its own capability, because a store can
                 * be sold the pharmacy without the catalogue being readable by
                 * whoever is standing at the till.
                 */
                ...(medicines
                    ? [
                          {
                              label: labels?.medicine ?? 'Products',
                              to: '/medicines',
                              icon: 'ti ti-pill',
                              match: '/medicines',
                          },
                      ]
                    : []),

                {
                    label: 'Inventory',
                    to: '/pharmacy/stock',
                    icon: 'ti ti-packages',
                    match: '/pharmacy/stock',
                },
                {
                    label: 'Purchases',
                    to: '/pharmacy/inwards',
                    icon: 'ti ti-truck-delivery',
                    match: '/pharmacy/inwards',
                },

                // Only whoever may actually take money, as the route asks.
                ...(can('pharmacy.sell')
                    ? [
                          {
                              label: 'Sales (POS)',
                              to: '/pharmacy/pos',
                              icon: 'ti ti-building-store',
                              match: '/pharmacy/pos',
                          },
                      ]
                    : []),

                {
                    label: 'Bills',
                    to: '/pharmacy/sales',
                    icon: 'ti ti-receipt',
                    match: '/pharmacy/sales',
                },
                {
                    label: 'Movements',
                    to: '/pharmacy/movements',
                    icon: 'ti ti-arrows-exchange',
                    match: '/pharmacy/movements',
                },

                // The shop's own name for the customer book — see above.
                ...(customersUnderPharmacy
                    ? [
                          {
                              label: labels?.customer ?? 'Customers',
                              to: '/customers',
                              icon: 'ti ti-users',
                              match: '/customers',
                          },
                      ]
                    : []),

                /*
                 * Its own module, sold separately, and its own capability per
                 * report inside the screen — so the row itself needs both:
                 * the module bought at all, and at least one report this
                 * person may actually open. A row leading to a screen that
                 * would show nothing is worse than no row.
                 */
                ...(reports
                    ? [
                          {
                              label: 'Reports',
                              to: '/pharmacy/reports',
                              icon: 'ti ti-report-analytics',
                              match: '/pharmacy/reports',
                          },
                      ]
                    : []),
                {
                    label: 'Suppliers',
                    to: '/pharmacy/suppliers',
                    icon: 'ti ti-truck',
                    match: '/pharmacy/suppliers',
                },

                /*
                 * Prescriptions would belong here, between who buys and where
                 * it is kept, and the module exists in ModuleRegistry — but
                 * nothing is built behind it. The only prescriptions screen in
                 * the router is a doctor's own list, which a counter cannot
                 * open. The "Coming soon" row at the bottom is the honest
                 * answer until there is a screen to link to.
                 */

                {
                    label: 'Stores',
                    to: '/pharmacy/stores',
                    icon: 'ti ti-building-store',
                    match: '/pharmacy/stores',
                },

                /*
                 * The pharmacy's own settings used to sit here. They now live
                 * under the one Settings group with every other thing that
                 * configures the software — somebody looking for "where do I
                 * change this" should have one place to look, not four. The
                 * row is not repeated here, because the same link in two
                 * menus reads as two different screens.
                 */
            ],
        });
    }

    /*
     * A role that holds reports and nothing else the counter does — an
     * accountant, say — still needs a way in. Only when the Pharmacy group
     * itself is not already carrying it (see above): showing the same link
     * twice would read as two different screens.
     */
    if (reports && !pharmacy) {
        clinical.push({
            label: 'Reports',
            to: '/pharmacy/reports',
            icon: 'ti ti-report-analytics',
            match: '/pharmacy/reports',
        });
    }

    /*
     * Billing. The menu only shows where the module runs and this person may
     * look at an invoice. The screen inside decides what
     * they may then do — take payment, cancel, refund — from their own
     * capability list, same as the pharmacy.
     */
    if (hasModule('billing') && can('billing.view')) {
        clinical.push({
            label: 'Billing',
            to: '/billing',
            icon: 'ti ti-receipt',
            match: '/billing',
            /*
             * In the order somebody at the counter works through them: what
             * is the state of the money, what has been billed, what came in,
             * what is still owed.
             *
             * Billing's settings are not a row here — they are in the
             * Settings group with the rest of the set-up screens. The same
             * row in two menus reads as two different screens.
             */
            children: [
                { label: 'Overview', to: '/billing', icon: 'ti ti-layout-dashboard' },
                {
                    label: 'Invoices',
                    to: '/billing/invoices',
                    icon: 'ti ti-file-invoice',
                    match: '/billing/invoices',
                },
                {
                    label: 'Payments',
                    to: '/billing/payments',
                    icon: 'ti ti-cash',
                    match: '/billing/payments',
                },
                {
                    label: 'Outstanding',
                    to: '/billing/outstanding',
                    icon: 'ti ti-clock-dollar',
                    match: '/billing/outstanding',
                },
                /*
                 * Every department's money together — OPD, lab and pharmacy.
                 * Its own capability: a management view, not a counter one.
                 */
                ...(can('billing.finance')
                    ? [{ label: 'Finance', to: '/billing/finance', icon: 'ti ti-chart-pie', match: '/billing/finance' }]
                    : []),
                /*
                 * The price list is not a menu row either: it is set up once
                 * and then read by the software rather than by a person. It
                 * opens from Settings › Billing.
                 */
            ],
        });
    }

    if (hasModule('communication') && can('communication.view')) {
        clinical.push({
            label: 'Communication',
            to: '/communication/whatsapp',
            icon: 'ti ti-messages',
            match: '/communication',
            children: [
                {
                    label: 'WhatsApp',
                    to: '/communication/whatsapp',
                    icon: 'ti ti-brand-whatsapp',
                    match: '/communication/whatsapp',
                },
                {
                    label: 'Email',
                    to: '/communication/email',
                    icon: 'ti ti-mail',
                    match: '/communication/email',
                },
                {
                    // Push to the patient app — a channel like the two above.
                    label: 'Push notifications',
                    to: '/communication/push',
                    icon: 'ti ti-bell-ringing',
                    match: '/communication/push',
                },
                {
                    label: 'SMS',
                    to: '/communication/sms',
                    icon: 'ti ti-message',
                    soon: true,
                },
            ],
        });
    }

    /*
     * Every record this person may take away as a file, in one place — after
     * the modules whose records it holds. Any one of its four tabs is enough
     * for the row; the screen shows only the tabs a role gives.
     */
    if (EXPORT_CAPABILITIES.some((capability) => can(capability))) {
        clinical.push({
            label: 'Export Center',
            to: '/exports',
            icon: 'ti ti-file-export',
            match: '/exports',
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
        /*
         * "Clinical" only where there is a clinic. A standalone medical store
         * has no OPD and no patients — its whole day is the counter — and
         * filing that under a clinical heading describes somebody else's
         * business back at them.
         */
        sections.push({
            title: hasModule('appointments') ? 'Clinical' : 'The work',
            items: clinical,
        });
    }

    if (here.length > 0) {
        sections.push({ title: 'This branch', items: here });
    }

    if (organization.length > 0) {
        sections.push({ title: 'Organisation', items: organization });
    }

    /* --- help -------------------------------------------------------------- */

    /*
     * How the software works — what each screen does and where each button
     * is. For everybody signed in, whatever they may open: a receptionist
     * asking "where is the receipt button" is the reader it is written for.
     */
    sections.push({
        title: 'Help',
        items: [{ label: 'Guide', to: '/guide', icon: 'ti ti-book', match: '/guide' }],
    });

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
            // Listed as coming only where the real entry above is not.
            ...(hasModule('pharmacy')
                ? []
                : [{ label: 'Pharmacy', to: '/pharmacy', icon: 'ti ti-vaccine', soon: true }]),
            /*
             * Billing was here as "coming soon" — it is real now, and it is
             * a CORE module, so it is always available; whether the row shows
             * depends only on this person's capability, handled above. It
             * therefore never appears in this "coming soon" list at all.
             */
            /*
             * Reports is built now, sold as its own module (see `reports`
             * above) — "coming soon" described the product's build status,
             * not this role's access, so it steps aside the same way Pharmacy
             * does: once the organization has bought it, it is real, even for
             * a role that holds none of its capabilities yet.
             */
            ...(hasModule('reports')
                ? []
                : [{ label: 'Reports', to: '/reports', icon: 'ti ti-chart-bar', soon: true }]),
        ],
    });

    return sections;
}

/**
 * The first screen in the menu this person may actually open — where they
 * land. Built from the same menu, so a role given only Messages lands on
 * Messages and never on a dashboard it was not given.
 */
export function firstScreen(sections: NavSection[]): string | null {
    for (const section of sections) {
        for (const item of section.items) {
            if (item.soon) {
                continue;
            }

            const child = item.children?.find((c) => !c.soon);

            if (child) {
                return child.to;
            }

            if (!item.children) {
                return item.to;
            }
        }
    }

    return null;
}
