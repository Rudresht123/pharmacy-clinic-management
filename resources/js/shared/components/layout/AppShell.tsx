import { useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Header, type HeaderUser } from './Header';
import { Sidebar } from './Sidebar';
import { SettingsPanel } from './SettingsPanel';
import type { NavSection } from '@/app/navigation';
import { useAppSettings } from '@/shared/hooks/useAppSettings';

interface AppShellProps {
    navigation: NavSection[];
    user: HeaderUser | null;
    onLogout: () => Promise<void> | void;
    /** Omitted where there is no lock screen. */
    onLock?: () => void;
    /** Omitted where there is no profile screen yet. */
    profileTo?: string;
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
export function AppShell({ navigation, user, onLogout, onLock, profileTo }: AppShellProps) {
    const { settings, update } = useAppSettings();

    /**
     * Collapse lives in the settings, not in a second localStorage key.
     *
     * With two stores the drawer's own toggle and the "Start collapsed"
     * switch each owned half the truth, so flipping the switch did nothing
     * until a reload. One value keeps them in sync, both ways.
     */
    const collapsed = settings.compactSidebar;

    const [mobileOpen, setMobileOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const { pathname } = useLocation();

    // A route change always closes the mobile drawer.
    useEffect(() => {
        setMobileOpen(false);
    }, [pathname]);

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
        <div className="app-shell" data-collapsed={collapsed} data-mobile-open={mobileOpen}>
            <Sidebar
                navigation={navigation}
                collapsed={collapsed}
                onToggleCollapse={() => update('compactSidebar', !collapsed)}
                onNavigate={() => setMobileOpen(false)}
            />

            {mobileOpen && (
                <div
                    className="app-sidebar-overlay d-lg-none"
                    onClick={() => setMobileOpen(false)}
                    aria-hidden="true"
                />
            )}

            <Header
                onOpenSidebar={() => setMobileOpen(true)}
                onOpenSettings={() => setSettingsOpen(true)}
                user={user}
                onLogout={onLogout}
                onLock={onLock}
                profileTo={profileTo}
            />

            <main className="app-content">
                <Outlet />
            </main>

            <SettingsPanel open={settingsOpen} onClose={() => setSettingsOpen(false)} />
        </div>
    );
}
