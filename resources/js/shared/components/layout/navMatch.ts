import type { NavItem } from '@/app/navigation';

/**
 * Whether the current path sits under a menu entry.
 *
 * A prefix, or any of several — a group whose screens live at unrelated paths
 * still has to light up when you are on one of them.
 *
 * The prefix is compared at a segment boundary, so /doctors does not claim
 * /doctors-on-call. Startswith alone is the bug where an unrelated screen
 * silently highlights somebody else's menu.
 */
export function under(pathname: string, match: string | string[]): boolean {
    return (Array.isArray(match) ? match : [match]).some(
        (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
    );
}

/**
 * Whether a main menu entry is the current one.
 *
 * `match` lets a parent stay active on its child routes, e.g.
 * /organizations/3/edit.
 */
export function isItemActive(item: NavItem, pathname: string): boolean {
    return item.match ? under(pathname, item.match) : pathname === item.to;
}
