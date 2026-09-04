/** One thing a role can be allowed to do. */
export interface Capability {
    key: string;
    name: string;
}

/**
 * A module, with the capabilities it grants.
 *
 * Only modules the organization actually holds ever appear — a capability
 * belonging to something nobody bought is not shown and refused, it is not
 * offered at all, which is the honest way to present something that does not
 * exist here.
 */
export interface GrantableModule {
    key: string;
    name: string;
    icon: string;
    capabilities: Capability[];
}

export interface Role {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    /** A Tabler class from the closed list on the Role model. */
    icon: string;
    capabilities: string[];
    users_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface RolePayload {
    name: string;
    description: string | null;
    icon: string;
    capabilities: string[];
}

/** Somebody who holds a role. */
export interface RoleMember {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    /** The branch they work at; null for somebody not tied to one. */
    location: string | null;
}

/**
 * The marks a role may wear — mirrors Role::ICONS on the server, which
 * validates against the same closed list. Kept in this order so the picker
 * reads the same way every time.
 */
export const ROLE_ICONS = [
    'ti ti-shield-lock',
    'ti ti-users',
    'ti ti-headset',
    'ti ti-stethoscope',
    'ti ti-nurse',
    'ti ti-pill',
    'ti ti-briefcase',
    'ti ti-receipt',
    'ti ti-cash',
    'ti ti-clipboard-list',
    'ti ti-microscope',
    'ti ti-eye',
] as const;

/** One switchable module at one branch — level two of the permission flow. */
export interface BranchModule {
    key: string;
    name: string;
    description: string;
    icon: string;
    group: string;
    is_enabled: boolean;
}
