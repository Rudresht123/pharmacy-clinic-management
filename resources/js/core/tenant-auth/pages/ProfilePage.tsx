import { ProfileScreen } from '@/core/profile/components/ProfileScreen';
import type { ProfileSummaryRow } from '@/core/profile/components/ProfileSummaryCard';
import { useTenantAuth } from '../TenantAuthProvider';
import { tenantAuthApi, type TenantUser } from '../api';

/** My Profile for clinic staff, against the tenant auth context. */
export default function ProfilePage() {
    const { user, setUser, organization, branches } = useTenantAuth();

    // The session's user carries no role name; what they hold lives on each
    // branch, so the primary branch's role is the headline.
    const roleLabel =
        user?.role === 'owner'
            ? 'Owner'
            : (user?.role_name ??
              branches.find((branch) => branch.is_primary)?.role ??
              branches.find((branch) => branch.role)?.role ??
              'Staff');

    const rows: ProfileSummaryRow[] = [{ icon: 'ti ti-user-shield', label: 'Role', value: roleLabel }];

    if (organization) {
        rows.push({ icon: 'ti ti-building-hospital', label: 'Organization', value: organization.name });
    }

    if (branches.length > 0) {
        rows.push({
            icon: 'ti ti-map-pin',
            label: branches.length === 1 ? 'Branch' : 'Branches',
            // Someone at several branches can hold a different role at each.
            value: branches.map((branch) => (
                <div key={branch.id}>
                    {branch.name}
                    {branches.length > 1 && branch.role && <span className="ppf-id-row-note"> · {branch.role}</span>}
                </div>
            )),
        });
    }

    return (
        <ProfileScreen<TenantUser>
            user={user}
            roleLabel={roleLabel}
            rows={rows}
            onSaved={setUser}
            api={tenantAuthApi}
        />
    );
}
