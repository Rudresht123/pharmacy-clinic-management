import { Suspense, useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Header, type HeaderUser } from './Header';
import { Sidebar } from './Sidebar';
import { SettingsPanel } from './SettingsPanel';
import { TopNav, TopNavBrand } from './TopNav';
import type { NavSection } from '@/app/navigation';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { useMediaQuery } from '@/shared/hooks/useMediaQuery';
import { FullPageLoader } from '@/shared/components/ui/Loader';

const DESKTOP = '(min-width: 992px)';

interface AppShellProps {
    navigation: NavSection[];
    user: HeaderUser | null;
    onLogout: () => Promise<void> | void;
    /** Omitted where there is no lock screen. */
    onLock?: () => void;
    /** Omitted where there is no profile screen yet. */
    profileTo?: string;
    /**
     * Sits below the menu — whose workspace this is.
     *
     * The platform panel passes nothing: an administrator moving between
     * organizations has no single one to name there.
     */
    sidebarFooter?: React.ReactNode;
    /** Sits at the very bottom, below the workspace card. */
    sidebarHelp?: React.ReactNode;
    /** Between the menu and the workspace card — the branch switcher. */
    sidebarExtra?: React.ReactNode;
    /** Where this person is working, for the menu styles that have no card on show. */
    workspaceName?: string;
    /**
     * Sits in the header, before everything else.
     *
     * A slot rather than a built-in: the platform panel has nothing to search
     * across, and a tenant's search is over its own patients.
     */
    headerSearch?: React.ReactNode;
    /** Sits in the header on the right, before the theme button. */
    headerExtra?: React.ReactNode;
    /**
     * A notice above every page's content — the tenant's subscription
     * warning (expiring soon, in grace). Nothing when there is nothing to say.
     */
    contentBanner?: React.ReactNode;
}

/**
 * The authenticated chrome: sidebar + topbar + routed content.
 *
 * Replaces layouts/common.blade.php. Sidebar collapse (desktop) and the
 * drawer (mobile) are React state — the jQuery in public/vendor/js/script.js
 * is not involved.
 *
 * The menu and the signed-in user arrive as props so the admin panel and an
 * organization's own workspace render the same chrome without either one
 * reaching into the other's auth.
 */
export function AppShell({
    navigation,
    user,
    onLogout,
    onLock,
    profileTo,
    sidebarFooter,
    sidebarHelp,
    sidebarExtra,
    workspaceName,
    headerSearch,
    headerExtra,
    contentBanner,
}: AppShellProps) {
    const { settings, update } = useAppSettings();

    // Below this the menu is the slide-out drawer, whatever style was chosen.
    const isDesktop = useMediaQuery(DESKTOP);
    const layout = settings.menuLayout;
    const showTopNav = isDesktop && layout === 'top';

    /**
     * Collapse lives in the settings, not in a second localStorage key.
     *
     * With two stores the drawer's own toggle and the "Start collapsed"
     * switch each owned half the truth, so flipping the switch did nothing
     * until a reload. One value keeps them in sync, both ways.
     *
     * Never on the drawer, which would otherwise lose its labels and sub-menus.
     */
    const collapsed =
        isDesktop && (layout === 'icons' || (layout === 'sidebar' && settings.compactSidebar));

    const [mobileOpen, setMobileOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const { pathname } = useLocation();

    // A route change always closes the mobile drawer.
    useEffect(() => {
        setMobileOpen(false);
    }, [pathname]);

    // Growing the window past the drawer's size must not leave it marked open,
    // or the page stays locked with nothing on screen to close it.
    useEffect(() => {
        if (isDesktop) {
            setMobileOpen(false);
        }
    }, [isDesktop]);

    // Stop the page scrolling behind the open drawer.
    useEffect(() => {
        if (!mobileOpen) {
            return;
        }

        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = previous;
        };
    }, [mobileOpen]);

    return (
        <div
            className="app-shell"
            data-menu={layout}
            data-collapsed={collapsed}
            data-mobile-open={mobileOpen}
        >
            {!showTopNav && (
                <Sidebar
                    navigation={navigation}
                    collapsed={collapsed}
                    canCollapse={layout === 'sidebar'}
                    onToggleCollapse={() => update('compactSidebar', !collapsed)}
                    onNavigate={() => setMobileOpen(false)}
                    footer={sidebarFooter}
                    help={sidebarHelp}
                    extra={sidebarExtra}
                />
            )}

            {mobileOpen && (
                <div
                    className="app-sidebar-overlay d-lg-none"
                    onClick={() => setMobileOpen(false)}
                    aria-hidden="true"
                />
            )}

            <Header
                brand={showTopNav ? <TopNavBrand /> : undefined}
                search={headerSearch}
                extra={headerExtra}
                onOpenSidebar={() => setMobileOpen(true)}
                onOpenSettings={() => setSettingsOpen(true)}
                user={user}
                onLogout={onLogout}
                onLock={onLock}
                profileTo={profileTo}
            />

            {showTopNav && (
                <TopNav
                    navigation={navigation}
                    footer={sidebarFooter}
                    help={sidebarHelp}
                    extra={sidebarExtra}
                    workspaceName={workspaceName}
                />
            )}

            <main className="app-content">
                {contentBanner}
                {/* Its own boundary, so a page still downloading its chunk
                    suspends here and the menu and header stay on screen. */}
                <Suspense fallback={<FullPageLoader />}>
                    <Outlet />
                </Suspense>
            </main>

            <SettingsPanel open={settingsOpen} onClose={() => setSettingsOpen(false)} />
        </div>
    );
}
