import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { initials } from '@/shared/utils/format';

/** Only what the header displays — either guard's user satisfies this. */
export interface HeaderUser {
    name: string;
    email: string;
}

interface HeaderProps {
    onOpenSidebar: () => void;
    onOpenSettings: () => void;
    /**
     * Who is signed in, and how to sign them out. Passed in rather than read
     * from a provider: this header serves the platform guard and a tenant's
     * own guard, which are deliberately separate all the way down.
     */
    user: HeaderUser | null;
    onLogout: () => Promise<void> | void;
    /** Omitted where there is no lock screen, and the item is then hidden. */
    onLock?: () => void;
    /** Omitted where there is no profile screen yet. */
    profileTo?: string;
    /**
     * Rendered on the left of the header, before everything else.
     *
     * A slot rather than a built-in search box: the platform header has
     * nothing to search across, and a tenant's search is over its own
     * patients — which this component has no business knowing about.
     */
    search?: ReactNode;
}

export function Header({
    onOpenSidebar,
    onOpenSettings,
    user,
    onLogout,
    onLock,
    profileTo,
    search,
}: HeaderProps) {
    const confirm = useConfirm();
    const navigate = useNavigate();
    const { resolvedTheme, toggleTheme } = useAppSettings();

    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    // Close the dropdown on any outside click.
    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        function onClick(event: MouseEvent) {
            if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
                setMenuOpen(false);
            }
        }

        document.addEventListener('mousedown', onClick);

        return () => document.removeEventListener('mousedown', onClick);
    }, [menuOpen]);

    async function handleLogout() {
        setMenuOpen(false);

        const confirmed = await confirm({
            title: 'Log out?',
            message: 'You will need to sign in again to continue.',
            confirmLabel: 'Log out',
            danger: true,
        });

        if (!confirmed) {
            return;
        }

        await onLogout();
        navigate('/login', { replace: true });
    }

    return (
        <header className="app-header">
            <button
                type="button"
                className="app-menu-btn"
                onClick={onOpenSidebar}
                aria-label="Open navigation"
            >
                <i className="ti ti-menu-2 fs-20" />
            </button>

            {search}

            <div className="ms-auto d-flex align-items-center gap-1">
                {/* Day/night is the one appearance setting people reach for
                    often enough to deserve a button rather than a drawer. */}
                <button
                    type="button"
                    className="app-menu-btn d-grid"
                    onClick={toggleTheme}
                    aria-label={
                        resolvedTheme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'
                    }
                    title={resolvedTheme === 'dark' ? 'Light theme' : 'Dark theme'}
                >
                    <i
                        className={
                            resolvedTheme === 'dark' ? 'ti ti-sun fs-20' : 'ti ti-moon fs-20'
                        }
                    />
                </button>

                <button
                    type="button"
                    className="app-menu-btn d-grid"
                    onClick={onOpenSettings}
                    aria-label="Appearance settings"
                    title="Appearance"
                >
                    <i className="ti ti-settings fs-20" />
                </button>

                <div className="dropdown" ref={menuRef}>
                    <button
                        type="button"
                        className="btn border-0 d-flex align-items-center gap-2 px-2"
                        onClick={() => setMenuOpen((open) => !open)}
                        aria-expanded={menuOpen}
                    >
                        <span
                            className="avatar rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                            style={{ width: 34, height: 34, fontSize: 13, fontWeight: 600 }}
                        >
                            {user ? initials(user.name) : '?'}
                        </span>

                        <span className="d-none d-md-block text-start lh-sm">
                            <span className="d-block fw-semibold fs-14">{user?.name}</span>
                            <span className="d-block text-muted fs-12">{user?.email}</span>
                        </span>

                        <i className="ti ti-chevron-down fs-14 text-muted" />
                    </button>

                    {menuOpen && (
                        <div
                            className="dropdown-menu dropdown-menu-end show mt-1"
                            style={{ right: 0 }}
                        >
                            {profileTo && (
                                <Link
                                    to={profileTo}
                                    className="dropdown-item"
                                    onClick={() => setMenuOpen(false)}
                                >
                                    <i className="ti ti-user-circle me-1 align-middle" />
                                    <span className="align-middle">My Profile</span>
                                </Link>
                            )}

                            {onLock && (
                                <button
                                    type="button"
                                    className="dropdown-item"
                                    onClick={() => {
                                        setMenuOpen(false);
                                        onLock();
                                    }}
                                >
                                    <i className="ti ti-lock me-1 align-middle" />
                                    <span className="align-middle">Lock Screen</span>
                                </button>
                            )}

                            <div
                                className={profileTo || onLock ? 'pt-2 mt-2 border-top' : undefined}
                            >
                                <button
                                    type="button"
                                    className="dropdown-item text-danger"
                                    onClick={handleLogout}
                                >
                                    <i className="ti ti-logout me-1 fs-17 align-middle" />
                                    <span className="align-middle">Log Out</span>
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
