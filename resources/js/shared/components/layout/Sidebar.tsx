import { useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import type { NavSection } from '@/app/navigation';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { cn } from '@/shared/utils/cn';
import { NavChildLink } from './NavChildLink';
import { isItemActive, under } from './navMatch';
import { useRailFlyout } from './useRailFlyout';

const WORKSPACE = '__workspace';

interface SidebarProps {
    /**
     * Passed in rather than imported: the admin panel and an organization's
     * own workspace are different menus over the same chrome.
     */
    navigation: NavSection[];
    /** Down to the icon rail, whose labels and sub-menus open in a card. */
    collapsed: boolean;
    /** Off where the rail is the chosen menu style, not a state of the sidebar. */
    canCollapse: boolean;
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
    canCollapse,
    onToggleCollapse,
    onNavigate,
    footer,
    help,
    extra,
}: SidebarProps) {
    const { pathname } = useLocation();
    const { settings, resolvedTheme } = useAppSettings();
    const flyout = useRailFlyout();

    /*
     * Which group is open, by its own path. Only ever one: opening a group
     * closes the last, so the rail never grows into a wall of sub-menus.
     *
     * An earlier version opened a group whenever one of its screens was on,
     * which meant the chevron was decoration — it pointed down and nothing
     * could make it point right. Held as state, the group is something you
     * open and close, and the arrow says which it is.
     */
    const [open, setOpen] = useState<string | null>(null);

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
            .find((item) => under(pathname, item.match ?? item.to));

        if (inside) {
            setOpen(inside.to);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pathname]);

    // The navy wordmark disappears on a dark sidebar, and the dark theme turns
    // every sidebar dark whatever its own setting says. A brand-coloured one
    // puts the logo on a white cap, so the navy version is the one that reads.
    const onDarkSurface = settings.sidebar === 'dark' || resolvedTheme === 'dark';

    const hasWorkspace = Boolean(footer || help || extra);

    const flyoutItem =
        flyout.openKey && flyout.openKey !== WORKSPACE
            ? navigation
                  .flatMap((section) => section.items)
                  .find((item) => item.to === flyout.openKey)
            : undefined;

    return (
        <aside className="app-sidebar">
            <div className="app-brand">
                <NavLink to="/dashboard" onClick={onNavigate}>
                    <img
                        src={
                            onDarkSurface
                                ? '/assets/img/zevro-logo-dark.png'
                                : '/assets/img/zevro-logo.png'
                        }
                        alt="ZEVRO"
                        className="app-brand-full"
                    />
                    <img src="/assets/img/zevro-mark.png" alt="" className="app-brand-mark" />
                </NavLink>

                {canCollapse && (
                    <button
                        type="button"
                        className="app-collapse-btn d-none d-lg-grid"
                        onClick={onToggleCollapse}
                        aria-label="Collapse sidebar"
                        title="Collapse sidebar"
                    >
                        <i className="ti ti-layout-sidebar-left-collapse" />
                    </button>
                )}
            </div>

            <nav className="app-nav" onScroll={collapsed ? flyout.close : undefined}>
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
                                const active = isItemActive(item, pathname);

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

                                const expanded = open === item.to;

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
                                const parentOfOpenGroup =
                                    Boolean(item.children) && expanded && !collapsed;

                                const anchor = collapsed
                                    ? flyout.anchorProps(item.to, Boolean(item.children))
                                    : undefined;

                                return (
                                    <li
                                        key={item.to}
                                        className={cn(parentOfOpenGroup && 'is-open-group')}
                                    >
                                        <NavLink
                                            to={item.to}
                                            className={cn(
                                                'app-nav-item',
                                                active && 'is-active',
                                                active && parentOfOpenGroup && 'is-open-parent',
                                                collapsed &&
                                                    flyout.openKey === item.to &&
                                                    'is-flyout',
                                            )}
                                            // The label is hidden on the rail.
                                            aria-label={collapsed ? item.label : undefined}
                                            {...anchor}
                                            onClick={(event) => {
                                                anchor?.onClick?.(event);
                                                onNavigate();
                                            }}
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
                                                            was === item.to ? null : item.to,
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
                                                            was === item.to ? null : item.to,
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
                                            counts as opening it. On the rail
                                            these rows live in the card instead.
                                        */}
                                        {item.children && expanded && !collapsed && (
                                            <ul className="app-nav-sub">
                                                {item.children.map((child) => (
                                                    <li key={child.to}>
                                                        <NavChildLink
                                                            child={child}
                                                            onClick={onNavigate}
                                                        />
                                                    </li>
                                                ))}
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

            {collapsed && (hasWorkspace || canCollapse) && (
                <div className="app-sidebar-foot">
                    {/* The branch switcher has to stay reachable on the rail. */}
                    {hasWorkspace && (
                        <button
                            type="button"
                            className={cn(
                                'app-nav-item app-rail-btn',
                                flyout.openKey === WORKSPACE && 'is-flyout',
                            )}
                            aria-label="Workspace"
                            aria-expanded={flyout.openKey === WORKSPACE}
                            {...flyout.anchorProps(WORKSPACE, true)}
                        >
                            <i className="ti ti-building-store" />
                        </button>
                    )}

                    {canCollapse && (
                        <button
                            type="button"
                            className="app-expand-btn"
                            onClick={onToggleCollapse}
                            aria-label="Expand sidebar"
                            title="Expand sidebar"
                        >
                            <i className="ti ti-layout-sidebar-right-collapse" />
                        </button>
                    )}
                </div>
            )}

            {collapsed && flyout.openKey && (
                <div
                    ref={flyout.popRef}
                    className={cn(
                        'app-flyout',
                        flyoutItem && !flyoutItem.children && 'app-flyout--label',
                        flyout.openKey === WORKSPACE && 'app-flyout--workspace',
                    )}
                    {...flyout.popProps}
                >
                    {flyoutItem ? (
                        <>
                            <NavLink
                                to={flyoutItem.to}
                                className="app-flyout-title"
                                onClick={onNavigate}
                            >
                                {flyoutItem.label}
                            </NavLink>

                            {flyoutItem.children?.map((child) => (
                                <NavChildLink key={child.to} child={child} onClick={onNavigate} />
                            ))}
                        </>
                    ) : (
                        <>
                            {extra && <div className="app-sidebar-extra">{extra}</div>}
                            {footer && <div className="app-sidebar-card">{footer}</div>}
                            {help && <div className="app-sidebar-help">{help}</div>}
                        </>
                    )}
                </div>
            )}
        </aside>
    );
}
