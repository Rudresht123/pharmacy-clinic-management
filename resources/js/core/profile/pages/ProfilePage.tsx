import { useAuth } from '@/core/auth/AuthProvider';
import { authApi, type PlatformUser } from '@/core/auth/api';
import { http } from '@/shared/api/http';
import { formatDate } from '@/shared/utils/format';
import type { ApiResponse } from '@/shared/types/api';
import { ProfileScreen } from '../components/ProfileScreen';
import type { ProfileSummaryRow } from '../components/ProfileSummaryCard';

const api = {
    ...authApi,

    async updateProfile(values: { name: string; email: string }) {
        const { data } = await http.put<ApiResponse<PlatformUser>>('/admin/auth/profile', values);

        return data.data;
    },

    async updatePassword(values: {
        current_password: string;
        password: string;
        password_confirmation: string;
    }) {
        await http.put('/admin/auth/password', values);
    },
};

/** My Profile for a platform administrator. */
export default function ProfilePage() {
    const { user, setUser } = useAuth();

    const roleLabel = user?.roles?.map((role) => role.name).join(', ') || 'Platform admin';

    const rows: ProfileSummaryRow[] = [
        { icon: 'ti ti-user-shield', label: 'Role', value: roleLabel },
        {
            icon: 'ti ti-shield-lock',
            label: 'Two-factor Sign-in',
            value: user?.two_factor_enabled ? 'Enabled' : 'Not enabled',
        },
    ];

    if (user?.created_at) {
        rows.push({ icon: 'ti ti-calendar', label: 'Member Since', value: formatDate(user.created_at) });
    }

    return (
        <ProfileScreen<PlatformUser> user={user} roleLabel={roleLabel} rows={rows} onSaved={setUser} api={api} />
    );
}
