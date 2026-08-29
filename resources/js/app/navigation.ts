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
    /** Also highlight the parent when a child route is active. */
    match?: string;
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
        ],
    },
    {
        title: 'Account',
        items: [{ label: 'My Profile', to: '/profile', icon: 'ti ti-user-circle' }],
    },
];
