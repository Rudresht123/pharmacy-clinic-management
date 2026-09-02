import { useMemo } from 'react';
import { AppShell } from '@/shared/components/layout/AppShell';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { tenantNavigation } from './tenant-navigation';
import { useConfigurableEntities } from '@/core/field-settings/api';

/**
 * An organization's own chrome — the same AppShell the platform panel uses,
 * wired to the `web` guard instead.
 *
 * No lock screen and no profile link: LockProvider is built on the platform
 * AuthProvider, and the tenant side has no profile route yet. Both items
 * hide themselves when their handler is absent rather than rendering a
 * control that goes nowhere.
 */
export function TenantShell() {
    const { user, logout } = useTenantAuth();

    // The sidebar says whatever this organization calls its records.
    const { data: entities } = useConfigurableEntities();

    // The menu depends on the role: owner-only screens are not listed for
    // staff, who would only reach a 403.
    const labels = useMemo(
        () => Object.fromEntries((entities ?? []).map((e) => [e.entity, e.label])),
        [entities],
    );

    const navigation = useMemo(
        () => tenantNavigation(user?.role, labels),
        [user?.role, labels],
    );

    return <AppShell navigation={navigation} user={user} onLogout={logout} />;
}
