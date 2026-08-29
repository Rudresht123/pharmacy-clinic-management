import { ensureCsrfCookie, http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';

/** One of the five panel roles — see Build Spec §18. */
export type PlatformRoleCode = 'super_admin' | 'ops' | 'support' | 'billing' | 'catalog';

export interface PlatformRole {
    code: PlatformRoleCode;
    name: string;
}

/**
 * An administrator of the platform.
 *
 * Named for what it is rather than "AuthUser": once the tenant application
 * exists there will be two kinds of signed-in user, and they authenticate
 * against different guards entirely.
 *
 * There is no `id` — the API exposes the ULID only.
 */
export interface PlatformUser {
    uuid: string;
    name: string;
    email: string;
    is_active: boolean;
    roles: PlatformRole[];
    two_factor_enabled: boolean;
    last_login_at: string | null;
    created_at: string | null;
}

export interface LoginPayload {
    email: string;
    password: string;
    remember?: boolean;
}

/**
 * Every path here is under /admin — the panel's own area. There is no
 * register endpoint: §18 says administrators are created by administrators.
 */
export const authApi = {
    async login(payload: LoginPayload): Promise<PlatformUser> {
        await ensureCsrfCookie();

        const { data } = await http.post<ApiResponse<PlatformUser>>('/admin/auth/login', payload);

        return data.data;
    },

    async logout(): Promise<void> {
        await http.post('/admin/auth/logout');
    },

    async me(): Promise<PlatformUser> {
        const { data } = await http.get<ApiResponse<PlatformUser>>('/admin/auth/me');

        return data.data;
    },

    async forgotPassword(email: string): Promise<string> {
        await ensureCsrfCookie();

        const { data } = await http.post<{ message: string }>('/admin/auth/forgot-password', {
            email,
        });

        return data.message;
    },

    async resetPassword(payload: {
        token: string;
        email: string;
        password: string;
        password_confirmation: string;
    }): Promise<void> {
        await ensureCsrfCookie();

        await http.post('/admin/auth/reset-password', payload);
    },
};

/** True when the admin holds any of the given roles. Super Admin passes everything. */
export function hasRole(user: PlatformUser | null, ...codes: PlatformRoleCode[]): boolean {
    if (!user) {
        return false;
    }

    const held = user.roles.map((role) => role.code);

    return held.includes('super_admin') || codes.some((code) => held.includes(code));
}
