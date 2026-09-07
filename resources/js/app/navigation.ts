/**
 * Sidebar structure, declared as data.
 *
 * Adding a module means adding an entry here — the Sidebar component never
 * changes. Every `to` must correspond to a real route in router.tsx; a SPA
 * has no equivalent of the theme's placeholder .html links.
 */
export interface NavItem {
    label: string;
    to: string;
    icon: string;
    /**
     * Also highlight the parent when a child route is active.
     *
     * Several prefixes where a group's screens do not share one: OPD holds the
     * board, the queue, the doctors and their availability, and those live at
     * four unrelated paths. Nesting the routes to make one prefix cover them
     * would be moving four URLs to fix a menu.
     */
    match?: string | string[];
    /**
     * A module that is planned but has not shipped.
     *
     * Rendered as a labelled, unclickable row rather than a link. A menu entry
     * that leads to a 404 is worse than no entry — but showing the shape of
     * what is coming, clearly marked, is honest and answers "where is billing"
     * before anybody has to ask.
     */
    soon?: boolean;
    /**
     * Screens that are the same subject at a different depth.
     *
     * OPD's board and its queue are one thing seen two ways, not two peers —
     * putting them side by side in a flat list made the menu read as a pile of
     * unrelated screens. A parent with children is still a link itself: the
     * group header goes somewhere, rather than being a lid you have to lift
     * before anything happens.
     */
    children?: NavItem[];
}

export interface NavSection {
    title: string;
    items: NavItem[];
}

export const navigation: NavSection[] = [
    {
        title: 'Main',
        items: [{ label: 'Dashboard', to: '/dashboard', icon: 'ti ti-layout-dashboard' }],
    },
    {
        title: 'Global Settings',
        items: [
            {
                label: 'Organizations',
                to: '/organizations',
                icon: 'ti ti-building-store',
                match: '/organizations',
            },
            {
                label: 'Organization Types',
                to: '/organization-types',
                icon: 'ti ti-category',
                match: '/organization-types',
            },
            {
                // The catalogue is read-only; modules are assigned from an
                // organization's own Modules tab, which is where the
                // commercial decision actually gets made.
                label: 'Modules',
                to: '/modules',
                icon: 'ti ti-puzzle',
                match: '/modules',
            },
            {
                label: 'Audit Log',
                to: '/audit',
                icon: 'ti ti-history',
                match: '/audit',
            },
        ],
    },
    {
        title: 'Account',
        items: [{ label: 'My Profile', to: '/profile', icon: 'ti ti-user-circle' }],
    },
];
