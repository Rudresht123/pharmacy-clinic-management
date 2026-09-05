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
    /** The account's kind. Not the same question as `role_id` below. */
    role: TenantUserRole;

    /** The set of capabilities they hold. Null for an owner, who bypasses roles. */
    role_id: number | null;
    role_name?: string | null;

    /**
     * Where they work, and what they hold at each place.
     *
     * Only present where the memberships were loaded — the list screen does
     * not need them, the form does.
     */
    branches?: {
        location_id: number;
        location: string | null;
        role_id: number | null;
        role: string | null;
        is_primary: boolean;
    }[];

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

/** One branch this person works at, and what they hold there. */
export interface TenantBranch {
    id: number;
    name: string | null;
    role: string | null;
    is_primary: boolean;
}

export interface TenantSession {
    user: TenantUser;
    organization: TenantOrganization;

    /**
     * Where this person may work.
     *
     * Empty for the owner and for head office, who work across the network
     * rather than at a counter — the switcher hides itself for both.
     */
    branches: TenantBranch[];

    /** Which of them the modules and capabilities below are about. */
    active_branch: number | null;

    /**
     * Which doctor this account belongs to, when it belongs to one.
     *
     * Null for almost everybody — a doctor may have no login at all, which is
     * why doctors are their own table. Where it is set, the queue opens on
     * their own list rather than the whole department.
     */
    doctor_id: number | null;

    /**
     * The modules running where this person works — sold to the organization
     * AND switched on at their branch.
     *
     * The sidebar hides what is not here and the API refuses it, both from
     * App\Services\Permissions\Permission — so a menu entry can never exist
     * for something the server would answer 403 to.
     */
    modules: string[];

    /**
     * What THIS PERSON may do, not the organization's pool.
     *
     * All three levels already resolved: sold to the organization, running at
     * their branch, and held by their role. An owner gets the whole pool
     * because they bypass roles; a member of staff gets the intersection.
     */
    capabilities: string[];
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
