import { NavLink, useLocation } from 'react-router-dom';
import type { NavItem } from '@/app/navigation';
import { cn } from '@/shared/utils/cn';
import { under } from './navMatch';

/** One screen inside a main menu group, wherever that group is being shown. */
export function NavChildLink({ child, onClick }: { child: NavItem; onClick?: () => void }) {
    const { pathname } = useLocation();

    /*
     * A child whose link carries a query string is an action, not a place — it
     * shares its pathname with a sibling, and without this both rows would
     * light up together, saying you are in two places at once.
     */
    const isAction = child.to.includes('?');

    return (
        <NavLink
            to={child.to}
            end={!child.match}
            className={({ isActive }) =>
                cn(
                    'app-nav-child',
                    !isAction &&
                        (child.match ? under(pathname, child.match) : isActive) &&
                        'is-active',
                )
            }
            onClick={onClick}
        >
            {child.label}
        </NavLink>
    );
}
