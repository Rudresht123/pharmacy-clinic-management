import type { Role, RoleScope } from './types';

/**
 * The two kinds of access, in the words every screen uses for them.
 *
 * One place, so the role screen, the person form and the branch panel never
 * describe the same thing three ways. `scope` is the role's own field — this
 * only says it in plain language.
 */
export const SCOPE_INFO: Record<
    RoleScope,
    { label: string; short: string; icon: string; description: string }
> = {
    organization: {
        label: 'Organization-wide access',
        short: 'Organization',
        icon: 'ti ti-building',
        description:
            'Works at every branch. Given to the person directly — for head office, accounts and anyone who works across the network.',
    },
    branch: {
        label: 'Branch-specific access',
        short: 'Branch',
        icon: 'ti ti-map-pin',
        description:
            'Works only at the branch where it is given. Someone can hold a different branch role at each branch they work at.',
    },
};

/** The scope beside a role's name, wherever a role is named. */
export function ScopeBadge({ scope, className }: { scope: RoleScope; className?: string }) {
    const info = SCOPE_INFO[scope];

    return (
        <span
            className={`ras-badge is-${scope}${className ? ` ${className}` : ''}`}
            title={info.label}
        >
            <i className={info.icon} aria-hidden="true" />
            {info.short}
        </span>
    );
}

/** A role for people at work — never the patient app's own. */
export function isStaffRole(role: Role): boolean {
    return role.audience !== 'patient';
}

/** "12 permissions" */
export function permissionCount(role: Role): string {
    const count = role.capabilities.length;

    return `${count} permission${count === 1 ? '' : 's'}`;
}

/**
 * The roles a staff dropdown offers for one slot.
 *
 * Staff roles of the right scope that this person may hand out — plus the one
 * already chosen, even if it is no longer theirs to give, so an edit never
 * blanks a field it did not touch.
 */
export function eligibleRoles(
    roles: Role[] | undefined,
    scope: RoleScope,
    current?: number | null,
): Role[] {
    return (roles ?? []).filter(
        (role) =>
            role.scope === scope &&
            isStaffRole(role) &&
            (role.assignable !== false || role.id === current),
    );
}
