import { AppShell } from '@/shared/components/layout/AppShell';
import { useAuth } from '@/core/auth/AuthProvider';
import { useLock } from '@/core/auth/LockProvider';
import { navigation } from './navigation';

/**
 * The platform panel's chrome — AppShell wired to the `platform` guard.
 *
 * Exists so AppShell itself stays free of either guard: the tenant tree
 * renders the same component through TenantShell instead.
 */
export function AdminShell() {
    const { user, logout } = useAuth();
    const { lock } = useLock();

    return (
        <AppShell
            navigation={navigation}
            user={user}
            onLogout={logout}
            onLock={lock}
            profileTo="/profile"
        />
    );
}
