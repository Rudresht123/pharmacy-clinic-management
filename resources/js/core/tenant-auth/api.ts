import { ensureCsrfCookie, http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';

/** The one role distinction that exists on the tenant side so far. */
export type TenantUserRole = 'owner' | 'staff';

/**
 * A member of one organization's own staff — distinct from PlatformUser,
 * which authenticates on a completely separate guard against a completely
 * separate (central) database.
 *
 * Has a plain `id`, not a `uuid` — there is no tenant-facing route yet that
 * addresses a user by URL, so there is nothing to keep off of it.
 */
export interface TenantUser {
    id: number;
    name: string;
    email: string;
    role: TenantUserRole;
    is_active: boolean;
    last_login_at: string | null;
    /** Values for the fields the organization added itself. */
    custom_fields?: Record<string, unknown>;
}

/** The signed-in user's own organization — just enough to greet them by name/logo. */
export interface TenantOrganization {
    name: string;
    code: string;
    subdomain: string;
    logo_url: string;
    has_logo: boolean;
}

export interface TenantSession {
    user: TenantUser;
    organization: TenantOrganization;
}

export interface TenantLoginPayload {
    email: string;
    password: string;
    remember?: boolean;
}

/**
 * Every path here is under /tenant — an organization's own area, on the
 * `web` guard. Which tenant database this resolves to comes from the
 * request's own Host header (see LoginRequest::resolveSubdomain()), not
 * anything sent here.
 */
/**
 * Public and unauthenticated — the one thing the login screen can show
 * about an organization before anyone has signed in. Resolved server-side
 * from the request's own Host header, same as login itself.
 */
export const tenantBrandingApi = {
    async get(): Promise<TenantOrganization> {
        const { data } = await http.get<ApiResponse<TenantOrganization>>('/tenant/branding');

        return data.data;
    },
};

export const tenantAuthApi = {
    async login(payload: TenantLoginPayload): Promise<TenantSession> {
        await ensureCsrfCookie();

        const { data } = await http.post<ApiResponse<TenantSession>>('/tenant/auth/login', payload);

        return data.data;
    },

    async logout(): Promise<void> {
        await http.post('/tenant/auth/logout');
    },

    async me(): Promise<TenantSession> {
        const { data } = await http.get<ApiResponse<TenantSession>>('/tenant/auth/me');

        return data.data;
    },
};
