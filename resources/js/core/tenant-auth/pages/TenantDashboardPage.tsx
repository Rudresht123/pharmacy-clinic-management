import { initials } from '@/shared/utils/format';
import { useTenantAuth } from '../TenantAuthProvider';

/**
 * Just enough for a login to land somewhere real. An actual tenant app
 * shell (navigation, clinical modules) is real product work with nothing
 * to gate yet — this is deliberately a placeholder, not a first draft of it.
 */
export default function TenantDashboardPage() {
    const { user, organization, logout } = useTenantAuth();

    return (
        <div>
            <header className="tenant-topbar">
                <div className="tenant-topbar-brand">
                    {organization?.has_logo ? (
                        <img
                            src={organization.logo_url}
                            alt=""
                            className="tenant-topbar-logo"
                        />
                    ) : (
                        <span
                            className="avatar-box avatar-box--initials tenant-topbar-logo"
                            aria-hidden="true"
                        >
                            {initials(organization?.name ?? '')}
                        </span>
                    )}

                    <span className="tenant-topbar-name">{organization?.name}</span>
                </div>

                <div className="tenant-topbar-user">
                    <div className="tenant-topbar-user-info">
                        <div className="tenant-topbar-user-name">{user?.name}</div>
                        <div className="tenant-topbar-user-role">{user?.role}</div>
                    </div>

                    <span className="avatar-box avatar-box--initials" style={{ width: 38, height: 38 }}>
                        {initials(user?.name ?? '')}
                    </span>

                    <button
                        type="button"
                        className="tenant-topbar-logout"
                        onClick={() => logout()}
                        title="Log out"
                        aria-label="Log out"
                    >
                        <i className="ti ti-logout" />
                    </button>
                </div>
            </header>

            <div className="tenant-welcome">
                <h2>Welcome, {user?.name}</h2>
                <p>
                    {organization?.name}'s clinical modules — patients, billing, inventory — will
                    appear here as they are built.
                </p>
            </div>
        </div>
    );
}
