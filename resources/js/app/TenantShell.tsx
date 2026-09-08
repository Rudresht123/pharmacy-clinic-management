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
    const { user, logout, modules, capabilities, organization, branches, activeBranch, doctorId } =
        useTenantAuth();

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
        () => tenantNavigation(user?.role, labels, modules, capabilities, doctorId),
        [user?.role, labels, modules, capabilities, doctorId],
    );

    /*
     * Where this person actually works.
     *
     * An owner works across the network, so the organization is their scope
     * and the card says so. Everybody else works at a branch, and the card was
     * telling them the name of a thing they cannot act on — while the branch
     * switcher, which is the only other place a branch name appears, hides
     * itself for anybody with just the one. So a receptionist at Lucknow had
     * nowhere on screen that said Lucknow.
     */
    const here = branches.find((branch) => branch.id === activeBranch) ?? branches[0];
    const atOneBranch = user?.role !== 'owner' && Boolean(here);

    return (
        <AppShell
            navigation={navigation}
            user={user}
            onLogout={logout}
            sidebarFooter={
                organization && (
                    <div className="app-org">
                        <span className="app-org-mark">
                            {atOneBranch ? (
                                <i className="ti ti-building-store" />
                            ) : organization.has_logo ? (
                                <img src={organization.logo_url} alt="" />
                            ) : (
                                <i className="ti ti-building" />
                            )}
                        </span>

                        <span className="app-org-text">
                            <b>{atOneBranch ? (here.name ?? 'This branch') : organization.name}</b>

                            {/*
                                The organisation stays on the second line for a
                                branch person: it is context, not their scope.
                                Their role there is the more useful half — it
                                is the answer to "why can I not see that", and
                                it is otherwise only on a screen they may not
                                be able to open.
                            */}
                            <small>
                                {atOneBranch ? (
                                    <>
                                        {organization.name}
                                        {here.role ? ` · ${here.role}` : ''}
                                    </>
                                ) : (
                                    <>
                                        Organisation · {modules.length} module
                                        {modules.length === 1 ? '' : 's'}
                                    </>
                                )}
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
