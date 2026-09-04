import { NavLink, useLocation } from 'react-router-dom';
import type { NavSection } from '@/app/navigation';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { cn } from '@/shared/utils/cn';

interface SidebarProps {
    /**
     * Passed in rather than imported: the admin panel and an organization's
     * own workspace are different menus over the same chrome.
     */
    navigation: NavSection[];
    collapsed: boolean;
    onToggleCollapse: () => void;
    /** Closes the mobile drawer after a link is followed. */
    onNavigate: () => void;
    /** Whose workspace this is — shown above the collapse control. */
    footer?: React.ReactNode;
    /** The very last row, below the workspace card. */
    help?: React.ReactNode;
}

export function Sidebar({
    navigation,
    collapsed,
    onToggleCollapse,
    onNavigate,
    footer,
    help,
}: SidebarProps) {
    const { pathname } = useLocation();
    const { settings } = useAppSettings();

    // The dark-text logo disappears on a dark or brand-coloured sidebar.
    const onDarkSurface = settings.sidebar !== 'light';

    return (
        <aside className="app-sidebar">
            <div className="app-brand">
                <NavLink to="/dashboard" onClick={onNavigate}>
                    <img
                        src={onDarkSurface ? '/assets/img/logo-white.svg' : '/assets/img/logo.svg'}
                        alt=""
                        className="app-brand-full"
                    />
                    <img src="/assets/img/logo-small.svg" alt="" className="app-brand-mark" />
                </NavLink>

                <button
                    type="button"
                    className="app-collapse-btn d-none d-lg-grid"
                    onClick={onToggleCollapse}
                    aria-label="Collapse sidebar"
                    title="Collapse sidebar"
                >
                    <i className="ti ti-layout-sidebar-left-collapse" />
                </button>
            </div>

            <nav className="app-nav">
                {navigation.map((section) => (
                    <div className="app-nav-section" key={section.title}>
                        <div className="app-nav-label">{section.title}</div>

                        <ul className="app-nav-list">
                            {section.items.map((item) => {
                                // `match` lets a parent stay active on its child routes,
                                // e.g. /organizations/3/edit.
                                const active = item.match
                                    ? pathname.startsWith(item.match)
                                    : pathname === item.to;

                                if (item.soon) {
                                    return (
                                        <li key={item.to}>
                                            <span
                                                className="app-nav-item is-soon"
                                                title={`${item.label} — not built yet`}
                                                aria-disabled="true"
                                            >
                                                <i className={item.icon} />
                                                <span>{item.label}</span>
                                                <em className="app-nav-soon">Soon</em>
                                            </span>
                                        </li>
                                    );
                                }

                                return (
                                    <li key={item.to}>
                                        <NavLink
                                            to={item.to}
                                            className={cn('app-nav-item', active && 'is-active')}
                                            onClick={onNavigate}
                                            // Tooltip is the only label left when collapsed.
                                            title={collapsed ? item.label : undefined}
                                        >
                                            <i className={item.icon} />
                                            <span>{item.label}</span>
                                        </NavLink>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </nav>

            {footer && !collapsed && <div className="app-sidebar-card">{footer}</div>}

            {help && !collapsed && <div className="app-sidebar-help">{help}</div>}

            {collapsed && (
                <div className="app-sidebar-foot d-none d-lg-block">
                    <button
                        type="button"
                        className="app-expand-btn"
                        onClick={onToggleCollapse}
                        aria-label="Expand sidebar"
                        title="Expand sidebar"
                    >
                        <i className="ti ti-layout-sidebar-right-collapse" />
                    </button>
                </div>
            )}
        </aside>
    );
}
