import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { initials } from '@/shared/utils/format';

/** Only what the header displays — either guard's user satisfies this. */
export interface HeaderUser {
    name: string;
    email: string;
    avatar_url?: string | null;
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
    const triggerRef = useRef<HTMLButtonElement>(null);

    // Close the dropdown on any outside click, or on Escape — which hands
    // focus back to the avatar so a keyboard user isn't dropped on <body>.
    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        function onClick(event: MouseEvent) {
            if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
                setMenuOpen(false);
            }
        }

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                triggerRef.current?.focus();
            }
        }

        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
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

                <div className="position-relative" ref={menuRef}>
                    <button
                        ref={triggerRef}
                        type="button"
                        className="acct-trigger"
                        onClick={() => setMenuOpen((open) => !open)}
                        aria-expanded={menuOpen}
                        aria-controls="account-menu"
                        aria-label="Account menu"
                    >
                        {user?.avatar_url ? (
                            <img src={user.avatar_url} alt="" className="acct-trigger-photo" />
                        ) : (
                            <span className="avatar acct-trigger-photo bg-primary text-white">
                                {user ? initials(user.name) : '?'}
                            </span>
                        )}

                        <span className="d-none d-md-block text-start lh-sm">
                            <span className="d-block fw-semibold fs-14">{user?.name}</span>
                            <span className="d-block text-muted fs-12">{user?.email}</span>
                        </span>

                        <i className="ti ti-chevron-down fs-14 text-muted" />
                    </button>

                    {menuOpen && (
                        <div className="acct-menu" id="account-menu">
                            {/* Who this is — the trigger hides the name and
                                email below md, so a phone sees them here. */}
                            <div className="acct-head">
                                {user?.avatar_url ? (
                                    <img src={user.avatar_url} alt="" className="acct-photo" />
                                ) : (
                                    <span className="acct-photo">
                                        {user ? initials(user.name) : '?'}
                                    </span>
                                )}

                                <span className="acct-who">
                                    <b title={user?.name}>{user?.name}</b>
                                    <small title={user?.email}>{user?.email}</small>
                                </span>
                            </div>

                            {profileTo && (
                                <Link
                                    to={profileTo}
                                    className="acct-item"
                                    onClick={() => setMenuOpen(false)}
                                >
                                    <span className="acct-icon">
                                        <i className="ti ti-user-circle" />
                                    </span>
                                    <span className="acct-text">
                                        <b>My Profile</b>
                                        <small>Your details and password</small>
                                    </span>
                                    <i className="ti ti-chevron-right acct-go" />
                                </Link>
                            )}

                            {onLock && (
                                <button
                                    type="button"
                                    className="acct-item"
                                    onClick={() => {
                                        setMenuOpen(false);
                                        onLock();
                                    }}
                                >
                                    <span className="acct-icon">
                                        <i className="ti ti-lock" />
                                    </span>
                                    <span className="acct-text">
                                        <b>Lock Screen</b>
                                        <small>Step away without signing out</small>
                                    </span>
                                    <i className="ti ti-chevron-right acct-go" />
                                </button>
                            )}

                            {(profileTo || onLock) && <div className="acct-sep" />}

                            <button
                                type="button"
                                className="acct-item acct-item--danger"
                                onClick={handleLogout}
                            >
                                <span className="acct-icon">
                                    <i className="ti ti-logout" />
                                </span>
                                <span className="acct-text">
                                    <b>Log Out</b>
                                    <small>End this session</small>
                                </span>
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
