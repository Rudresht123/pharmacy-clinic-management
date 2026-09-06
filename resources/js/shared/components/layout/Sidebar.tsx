import { useEffect, useState } from 'react';
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
    /** Between the menu and the workspace card. */
    extra?: React.ReactNode;
}

export function Sidebar({
    navigation,
    collapsed,
    onToggleCollapse,
    onNavigate,
    footer,
    help,
    extra,
}: SidebarProps) {
    const { pathname } = useLocation();
    const { settings } = useAppSettings();

    /*
     * Which groups are open, by their own path.
     *
     * An earlier version opened a group whenever one of its screens was on,
     * which meant the chevron was decoration — it pointed down and nothing
     * could make it point right. Held as state, the group is something you
     * open and close, and the arrow says which it is.
     */
    const [open, setOpen] = useState<string[]>([]);

    /*
     * Navigating INTO a group opens it, once.
     *
     * Not on every render: that would be the old behaviour wearing a chevron,
     * and closing a group you are inside would spring straight back open.
     */
    useEffect(() => {
        const inside = navigation
            .flatMap((section) => section.items)
            .filter((item) => item.children?.length)
            .filter((item) => pathname.startsWith(item.match ?? item.to))
            .map((item) => item.to);

        if (inside.length > 0) {
            setOpen((was) => Array.from(new Set([...was, ...inside])));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pathname]);

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
                {navigation.map((section, index) => (
                    <div className="app-nav-section" key={section.title || `section-${index}`}>
                        {/* A section can be untitled — Dashboard sits above
                            every heading, because it is where you land rather
                            than a member of any group. An empty label would
                            still reserve its own line. */}
                        {section.title && (
                            <div className="app-nav-label">{section.title}</div>
                        )}

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

                                const expanded = open.includes(item.to);

                                /*
                                 * A parent whose group is open does not also
                                 * wear the active pill.
                                 *
                                 * Being on /opd made both the parent and its
                                 * Dashboard child light up — two filled rows
                                 * stacked, saying the same thing twice, and
                                 * the heaviest object on the rail. Open, the
                                 * parent keeps only the bright text; the child
                                 * list underneath says where you actually are.
                                 */
                                const parentOfOpenGroup = Boolean(item.children) && expanded;

                                return (
                                    <li key={item.to}>
                                        <NavLink
                                            to={item.to}
                                            className={cn(
                                                'app-nav-item',
                                                active && 'is-active',
                                                active && parentOfOpenGroup && 'is-open-parent',
                                            )}
                                            onClick={onNavigate}
                                            // Tooltip is the only label left when collapsed.
                                            title={collapsed ? item.label : undefined}
                                        >
                                            <i className={item.icon} />
                                            <span>{item.label}</span>

                                            {/*
                                                The chevron is its own button
                                                inside the link, so the row
                                                still goes somewhere and the
                                                arrow only opens the group.
                                                A parent that can ONLY be
                                                expanded makes its own screen
                                                unreachable from the menu.
                                            */}
                                            {item.children && !collapsed && (
                                                <span
                                                    role="button"
                                                    tabIndex={0}
                                                    aria-label={`${expanded ? 'Collapse' : 'Expand'} ${item.label}`}
                                                    aria-expanded={expanded}
                                                    className={cn(
                                                        'app-nav-caret',
                                                        expanded && 'is-open',
                                                    )}
                                                    onClick={(event) => {
                                                        event.preventDefault();
                                                        event.stopPropagation();

                                                        setOpen((was) =>
                                                            was.includes(item.to)
                                                                ? was.filter(
                                                                      (at) => at !== item.to,
                                                                  )
                                                                : [...was, item.to],
                                                        );
                                                    }}
                                                    onKeyDown={(event) => {
                                                        if (
                                                            event.key !== 'Enter' &&
                                                            event.key !== ' '
                                                        ) {
                                                            return;
                                                        }

                                                        event.preventDefault();
                                                        event.stopPropagation();

                                                        setOpen((was) =>
                                                            was.includes(item.to)
                                                                ? was.filter(
                                                                      (at) => at !== item.to,
                                                                  )
                                                                : [...was, item.to],
                                                        );
                                                    }}
                                                >
                                                    <i className="ti ti-chevron-right" />
                                                </span>
                                            )}
                                        </NavLink>

                                        {/*
                                            Open because somebody opened it —
                                            or because they navigated in, which
                                            counts as opening it. Hidden
                                            entirely when the rail is collapsed
                                            to icons, where an indented row has
                                            nothing left to indent from.
                                        */}
                                        {item.children && expanded && !collapsed && (
                                            <ul className="app-nav-sub">
                                                {item.children.map((child) => {
                                                    /*
                                                     * A child whose link carries a
                                                     * query string is an action,
                                                     * not a place — it shares its
                                                     * pathname with a sibling, and
                                                     * without this both rows would
                                                     * light up together, saying
                                                     * you are in two places at
                                                     * once.
                                                     */
                                                    const isAction = child.to.includes('?');

                                                    return (
                                                        <li key={child.to}>
                                                            <NavLink
                                                                to={child.to}
                                                                end={!child.match}
                                                                className={({ isActive }) =>
                                                                    cn(
                                                                        'app-nav-child',
                                                                        !isAction &&
                                                                            (child.match
                                                                                ? pathname.startsWith(
                                                                                      child.match,
                                                                                  )
                                                                                : isActive) &&
                                                                            'is-active',
                                                                    )
                                                                }
                                                                onClick={onNavigate}
                                                            >
                                                                {child.label}
                                                            </NavLink>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </nav>

            {extra && !collapsed && <div className="app-sidebar-extra">{extra}</div>}

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
