import { useMemo } from 'react';
import { AppShell } from '@/shared/components/layout/AppShell';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { BranchSwitcher } from '@/core/tenant-auth/BranchSwitcher';
import { PatientSearch } from '@/core/opd/components/PatientSearch';
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
    const { user, logout, modules, capabilities, organization } = useTenantAuth();

    // The sidebar says whatever this organization calls its records.
    const { data: entities } = useConfigurableEntities();

    // The menu is built from what this person may actually do — every
    // entry names the capability its screen needs, and the server refuses
    // the same one, so a listed screen can never answer 403.
    const labels = useMemo(
        () => Object.fromEntries((entities ?? []).map((e) => [e.entity, e.label])),
        [entities],
    );

    const navigation = useMemo(
        () => tenantNavigation(user?.role, labels, modules, capabilities),
        [user?.role, labels, modules, capabilities],
    );

    return (
        <AppShell
            navigation={navigation}
            user={user}
            onLogout={logout}
            sidebarFooter={
                organization && (
                    <div className="app-org">
                        <span className="app-org-mark">
                            {organization.has_logo ? (
                                <img src={organization.logo_url} alt="" />
                            ) : (
                                <i className="ti ti-building" />
                            )}
                        </span>

                        <span className="app-org-text">
                            <b>{organization.name}</b>
                            <small>
                                Organization · {modules.length} module
                                {modules.length === 1 ? '' : 's'}
                            </small>
                        </span>
                    </div>
                )
            }
            headerSearch={<PatientSearch />}
            sidebarExtra={<BranchSwitcher />}
            sidebarHelp={
                <a
                    className="app-help"
                    href="mailto:support@hms.local"
                    title="Email support"
                >
                    <i className="ti ti-help-circle" />
                    <span>Help &amp; Support</span>
                </a>
            }
        />
    );
}
