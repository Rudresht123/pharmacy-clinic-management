import {
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type FocusEvent,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import type { NavItem, NavSection } from '@/app/navigation';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { useMediaQuery } from '@/shared/hooks/useMediaQuery';
import { cn } from '@/shared/utils/cn';
import { NavChildLink } from './NavChildLink';
import { isItemActive } from './navMatch';

const MORE = '__more';
const WORKSPACE = '__workspace';

/** Long enough to cross the gap between a menu entry and its dropdown. */
const CLOSE_DELAY_MS = 140;

/** The logo for the header. The top menu has no sidebar to hold it. */
export function TopNavBrand() {
    const { settings, resolvedTheme } = useAppSettings();

    return (
        <NavLink to="/dashboard" className="app-header-brand">
            <img
                src={
                    settings.topbar === 'dark' || resolvedTheme === 'dark'
                        ? '/assets/img/zevro-logo-dark.png'
                        : '/assets/img/zevro-logo.png'
                }
                alt="ZEVRO"
            />
        </NavLink>
    );
}

interface TopNavProps {
    navigation: NavSection[];
    /** Whose workspace this is, and the rest of what the sidebar holds below its menu. */
    footer?: ReactNode;
    help?: ReactNode;
    extra?: ReactNode;
    /** Named on the strip, so where you are working stays on screen. */
    workspaceName?: string;
}

/**
 * The menu as one strip under the header.
 *
 * Only for a window wide enough for it; below that AppShell uses the slide-out
 * sidebar instead. As the window narrows, whatever no longer fits moves into
 * More, so the strip never wraps or scrolls and every screen stays reachable.
 *
 * Planned modules are left out: a row that goes nowhere is not worth a place on
 * a strip this short.
 */
export function TopNav({ navigation, footer, help, extra, workspaceName }: TopNavProps) {
    const { pathname } = useLocation();
    const canHover = useMediaQuery('(hover: hover)');

    const items = useMemo(
        () => navigation.flatMap((section) => section.items).filter((item) => !item.soon),
        [navigation],
    );

    const [openKey, setOpenKey] = useState<string | null>(null);
    const [fit, setFit] = useState(items.length);

    const listRef = useRef<HTMLDivElement>(null);
    const ghostRef = useRef<HTMLDivElement>(null);
    const popRef = useRef<HTMLDivElement>(null);
    const timer = useRef<number | undefined>(undefined);

    const cancelClose = () => window.clearTimeout(timer.current);

    const closeSoon = () => {
        cancelClose();
        timer.current = window.setTimeout(() => setOpenKey(null), CLOSE_DELAY_MS);
    };

    /*
     * How many entries fit, read from an invisible copy of the whole strip.
     *
     * The copy keeps every entry measurable after it has moved into More, and
     * it never affects the real strip, whose dropdowns rule out overflow:hidden.
     */
    useLayoutEffect(() => {
        const list = listRef.current;
        const ghost = ghostRef.current;

        if (!list || !ghost) {
            return;
        }

        function measure() {
            if (!list || !ghost) {
                return;
            }

            const widths = Array.from(ghost.children, (el) => (el as HTMLElement).offsetWidth);
            const more = widths.pop() ?? 0;
            const gap = parseFloat(getComputedStyle(list).columnGap) || 0;
            const room = list.clientWidth;

            if (widths.reduce((sum, width) => sum + width + gap, -gap) <= room) {
                setFit(widths.length);

                return;
            }

            let used = more;
            let count = 0;

            for (const width of widths) {
                if (used + gap + width > room) {
                    break;
                }

                used += gap + width;
                count += 1;
            }

            setFit(count);
        }

        measure();

        let live = true;
        void document.fonts?.ready.then(() => live && measure());

        const observer = new ResizeObserver(measure);
        observer.observe(list);

        return () => {
            live = false;
            observer.disconnect();
        };
    }, [items]);

    useEffect(() => cancelClose, []);

    useEffect(() => {
        cancelClose();
        setOpenKey(null);
    }, [pathname]);

    useEffect(() => {
        if (!openKey) {
            return;
        }

        function onPointerDown(event: PointerEvent) {
            if (!(event.target as Element).closest?.('.app-tn-entry')) {
                setOpenKey(null);
            }
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key !== 'Escape') {
                return;
            }

            // Back to the arrow that opens it, so the keyboard keeps its place.
            document
                .querySelector<HTMLElement>('.app-tn-entry.is-open [aria-expanded]')
                ?.focus();
            setOpenKey(null);
        }

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [openKey]);

    // A dropdown that would run off the right of the window opens leftwards.
    useLayoutEffect(() => {
        const pop = popRef.current;

        if (!pop) {
            return;
        }

        pop.classList.remove('is-end');

        if (pop.getBoundingClientRect().right > window.innerWidth - 8) {
            pop.classList.add('is-end');
        }
    }, [openKey, fit]);

    /** Hover and focus for one entry, so only one dropdown is ever open. */
    function entryProps(key: string | null) {
        return {
            onMouseEnter: canHover
                ? () => {
                      cancelClose();
                      setOpenKey(key);
                  }
                : undefined,
            onMouseLeave: canHover ? closeSoon : undefined,
            onFocus: cancelClose,
            onBlur: (event: FocusEvent<HTMLDivElement>) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    closeSoon();
                }
            },
        };
    }

    /*
     * A mouse has already opened it by hovering, and people click as they
     * arrive, so a click must not close it again. A keyboard press (detail 0)
     * or a tap still toggles.
     */
    function press(key: string, event: ReactMouseEvent) {
        cancelClose();
        setOpenKey((was) => (was === key && !(canHover && event.detail > 0) ? null : key));
    }

    const shown = items.slice(0, fit);
    const rest = items.slice(fit);
    const hasWorkspace = Boolean(footer || help || extra);

    return (
        <nav className="app-tn" aria-label="Main menu">
            <div className="app-tn-list" ref={listRef}>
                <div className="app-tn-ghost" ref={ghostRef} aria-hidden="true">
                    {items.map((item) => (
                        <span className="app-tn-entry" key={item.to}>
                            <span className={cn('app-tn-item', item.children && 'has-caret')}>
                                <i className={item.icon} />
                                <span>{item.label}</span>
                            </span>
                            {item.children && <span className="app-tn-caret" />}
                        </span>
                    ))}
                    <span className="app-tn-entry">
                        <span className="app-tn-item has-caret">
                            <i className="ti ti-dots" />
                            <span>More</span>
                        </span>
                        <span className="app-tn-caret" />
                    </span>
                </div>

                {shown.map((item) => {
                    const isOpen = openKey === item.to;

                    return (
                        <div
                            key={item.to}
                            className={cn(
                                'app-tn-entry',
                                isItemActive(item, pathname) && 'is-active',
                                isOpen && 'is-open',
                            )}
                            {...entryProps(item.children ? item.to : null)}
                        >
                            <NavLink
                                to={item.to}
                                className={() =>
                                    cn('app-tn-item', item.children && 'has-caret')
                                }
                            >
                                <i className={item.icon} />
                                <span>{item.label}</span>
                            </NavLink>

                            {/*
                                Its own button, so the entry still goes to its
                                screen, and a touch screen with no hover can
                                still reach the dropdown.
                            */}
                            {item.children && (
                                <button
                                    type="button"
                                    className="app-tn-caret"
                                    aria-label={`${isOpen ? 'Close' : 'Open'} ${item.label} menu`}
                                    aria-expanded={isOpen}
                                    onClick={(event) => press(item.to, event)}
                                >
                                    <i className="ti ti-chevron-down" />
                                </button>
                            )}

                            {isOpen && (
                                <div className="app-tn-pop" ref={popRef}>
                                    {item.children?.map((child) => (
                                        <NavChildLink key={child.to} child={child} />
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}

                {rest.length > 0 && (
                    <div
                        className={cn(
                            'app-tn-entry',
                            rest.some((item) => isItemActive(item, pathname)) && 'is-active',
                            openKey === MORE && 'is-open',
                        )}
                        {...entryProps(MORE)}
                    >
                        <button
                            type="button"
                            className="app-tn-item has-caret"
                            aria-expanded={openKey === MORE}
                            onClick={(event) => press(MORE, event)}
                        >
                            <i className="ti ti-dots" />
                            <span>More</span>
                            <i className="ti ti-chevron-down app-tn-more-caret" />
                        </button>

                        {openKey === MORE && (
                            <div className="app-tn-pop app-tn-pop--more" ref={popRef}>
                                {rest.map((item) => (
                                    <MoreEntry key={item.to} item={item} />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {hasWorkspace && (
                <div
                    className={cn(
                        'app-tn-entry app-tn-workspace',
                        openKey === WORKSPACE && 'is-open',
                    )}
                    {...entryProps(WORKSPACE)}
                >
                    <button
                        type="button"
                        className="app-tn-item has-caret"
                        aria-label="Workspace"
                        aria-expanded={openKey === WORKSPACE}
                        onClick={(event) => press(WORKSPACE, event)}
                    >
                        <i className="ti ti-building-store" />
                        {workspaceName && <span className="app-tn-name">{workspaceName}</span>}
                        <i className="ti ti-chevron-down app-tn-more-caret" />
                    </button>

                    {openKey === WORKSPACE && (
                        <div className="app-tn-pop app-tn-pop--workspace is-end" ref={popRef}>
                            {extra && <div className="app-sidebar-extra">{extra}</div>}
                            {footer && <div className="app-sidebar-card">{footer}</div>}
                            {help && <div className="app-sidebar-help">{help}</div>}
                        </div>
                    )}
                </div>
            )}
        </nav>
    );
}

/** An entry that moved into More: itself, then its own screens underneath. */
function MoreEntry({ item }: { item: NavItem }) {
    const { pathname } = useLocation();

    return (
        <>
            <NavLink
                to={item.to}
                className={() => cn('app-tn-more', isItemActive(item, pathname) && 'is-current')}
            >
                <i className={item.icon} />
                <span>{item.label}</span>
            </NavLink>

            {item.children?.map((child) => <NavChildLink key={child.to} child={child} />)}
        </>
    );
}
