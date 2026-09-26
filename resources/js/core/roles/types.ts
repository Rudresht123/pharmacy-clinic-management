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
    /**
     * Where it can be assigned: across the network, or at one branch.
     *
     * An organization role sits on the person; a branch role sits on a
     * membership and means nothing anywhere else.
     */
    scope: 'organization' | 'branch';

    /**
     * Which branch wrote it.
     *
     * Null means the organization did, and every branch may use it — but only
     * an owner may change it. Set means it belongs to that branch alone.
     */
    location_id: number | null;
    location?: string | null;
    capabilities: string[];

    /**
     * The subset of `capabilities` a branch may not take away for itself.
     *
     * The organization's answer to branch customisation: everything else on
     * this role, a branch manager may switch off for their own branch alone.
     */
    locked?: string[];

    /**
     * Whether branches may customise it at all — organization-wide AND
     * branch-assigned. Decided on the server so the rule lives in one place.
     */
    is_customisable_by_branch?: boolean;

    users_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface RolePayload {
    name: string;
    description: string | null;
    icon: string;
    capabilities: string[];

    /**
     * Omitted — never sent empty — by a screen not in a position to set locks.
     * The server reads a missing `locked` as "leave them alone" and an empty
     * one as "unlock everything", which are different intentions.
     */
    locked?: string[];
}

/**
 * What one branch may change about one of the organization's roles.
 *
 * Three lists rather than a matrix, because the screen already draws the
 * capability vocabulary from `grantable` like every other permission view —
 * a second, differently shaped copy of it would be two things to keep in step.
 */
export interface BranchRolePermissions {
    location_id: number;
    role: { id: number; name: string; scope: 'organization' | 'branch'; icon: string | null };

    /** What the organization grants this role, everywhere. */
    inherited: string[];

    /** What this branch has taken away — the only thing a branch may write. */
    removed: string[];

    /** What it may not take away, whatever it sends. */
    locked: string[];
}

/**
 * Which of the six levels decided one capability.
 *
 * Mirrors the constants on EffectivePermissions, which is the only thing that
 * writes them — the point of naming them at all is that five of these are
 * fixed by a different person on a different screen, and "you do not have
 * permission" cannot tell them apart.
 */
export type PermissionSource =
    | 'not_sold'
    | 'module_off'
    | 'owner'
    | 'organization_role'
    | 'branch_role'
    | 'branch_override'
    | 'user_denied'
    | 'no_role';

export interface EffectivePermissionRow {
    capability: string;
    module: string | null;
    allowed: boolean;
    source: PermissionSource;
    is_locked: boolean;
}

export interface EffectivePermissions {
    user_id: number;
    location_id: number | null;
    role: { organization: string | null; branch: string | null };
    modules: string[];
    capabilities: EffectivePermissionRow[];
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

    /**
     * Made compulsory by the organization, so this branch may not switch it
     * off. Sent so the screen can show a padlock instead of a switch that
     * would be refused on save — the refusal is still the enforcement.
     */
    is_locked?: boolean;
}
