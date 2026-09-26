import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type {
    BranchModule,
    BranchRolePermissions,
    EffectivePermissions,
    GrantableModule,
    Role,
    RoleMember,
    RolePayload,
} from './types';

export const rolesApi = createResourceApi<Role, RolePayload>('tenant/roles');

export const rolesHooks = createResourceHooks(rolesApi, {
    singular: 'Role',
    plural: 'Roles',
});

/**
 * The pool this organization may hand out, grouped by module.
 *
 * `scope` narrows it to what a role of THAT scope may actually hold —
 * `'branch'` drops every organization-only capability (`settings.manage`,
 * `branches.manage_manager`, …), because a branch role could never save with
 * one ticked. Every role this screen creates comes out branch-scoped, so the
 * caller passes `'branch'` whenever it knows that; omitting it (or passing
 * `'organization'`) returns everything, since an organization-wide role may
 * hold a branch capability too.
 *
 * Keyed inside the roles namespace, and by scope, so saving a role refreshes
 * both pools and switching between an organization role and a branch role
 * does not show one's list while the other's request is still in flight.
 */
export function useGrantable(scope?: 'organization' | 'branch') {
    return useQuery({
        queryKey: resourceKey(rolesApi.endpoint, 'grantable', scope ?? 'all'),
        queryFn: async (): Promise<GrantableModule[]> => {
            const { data } = await http.get<ApiResponse<{ modules: GrantableModule[] }>>(
                '/tenant/roles/grantable',
                { params: scope ? { scope } : undefined },
            );

            return data.data.modules;
        },
        /*
         * Deliberately not the long stale time a field schema gets. This list
         * changes when a release renames a capability, and a tab left open
         * across that release would go on offering keys the server has already
         * stopped accepting — which is exactly how somebody ends up reading
         * "your organization cannot grant customers.manage" about a
         * permission nobody took away from them.
         */
        staleTime: 30 * 1000,
    });
}

/** Somewhere to start, when writing a role. */
export interface RoleTemplate {
    key: string;
    name: string;
    /** What this person does all day, in the words somebody would use. */
    summary: string;
    icon: string;
    scope: 'organization' | 'branch';
    capabilities: string[];
}

/**
 * The starting points, from the server.
 *
 * This list used to live in the bundle. It is the SAME library the seeder
 * uses to give a new organization its roles on day one, so serving it keeps
 * one list instead of two that agree only while somebody remembers to edit
 * both — and it arrives already narrowed to what is grantable where this
 * person works, so a template can never offer something ungrantable.
 */
export function useRoleTemplates() {
    return useQuery({
        queryKey: resourceKey(rolesApi.endpoint, 'templates'),
        queryFn: async (): Promise<RoleTemplate[]> => {
            const { data } = await http.get<ApiResponse<RoleTemplate[]>>('/tenant/roles/templates');

            return data.data ?? [];
        },
        // Same reasoning as the pool above: it moves when a release does.
        staleTime: 30 * 1000,
    });
}

/**
 * Who holds one role.
 *
 * Its own request rather than part of `show`, which the editor calls on every
 * selection — the names are only wanted when somebody opens the Members tab.
 */
export function useRoleMembers(roleId: number | undefined, enabled = true) {
    return useQuery({
        queryKey: resourceKey(rolesApi.endpoint, 'members', roleId),
        queryFn: async (): Promise<RoleMember[]> => {
            const { data } = await http.get<ApiResponse<RoleMember[]>>(
                `/tenant/roles/${roleId}/members`,
            );

            return data.data;
        },
        enabled: enabled && roleId !== undefined && roleId > 0,
    });
}

/**
 * Where somebody works — the whole set, replaced in one write.
 *
 * "At most one primary" and "one membership per branch" are rules about the
 * set, so it is written as a set. The server checks both, plus that every
 * branch is one the assigner can act on and every role within their own reach.
 */
export function useSaveUserBranches(userId: number | string | undefined) {
    return useMutation({
        mutationFn: async (
            branches: { location_id: number; role_id: number | null; is_primary: boolean }[],
        ) => {
            const { data } = await http.put<ApiResponse<unknown>>(
                `/tenant/users/${userId}/branches`,
                { branches },
            );

            return data.data;
        },
        onSuccess: () => notify.success('Branches updated'),
    });
}

/*
|------------------------------------------------------------------------------
| Level two — which modules a branch runs
|------------------------------------------------------------------------------
|
| Keyed under the locations namespace rather than roles: it belongs to a
| branch, is edited on the branch's own page, and has to go stale when that
| branch does.
*/

export function useBranchModules(locationId: number | undefined) {
    return useQuery({
        queryKey: resourceKey('tenant/locations', 'modules', locationId),
        queryFn: async (): Promise<BranchModule[]> => {
            const { data } = await http.get<ApiResponse<{ modules: BranchModule[] }>>(
                `/tenant/locations/${locationId}/modules`,
            );

            return data.data.modules;
        },
        enabled: locationId !== undefined && !Number.isNaN(locationId),
    });
}

export function useSaveBranchModules(locationId: number | undefined) {
    return useMutation({
        // The body names what IS on here; the server stores the opposite,
        // because absence is what "inherited" means in that table.
        mutationFn: async (modules: string[]) => {
            const { data } = await http.put<ApiResponse<{ modules: BranchModule[] }>>(
                `/tenant/locations/${locationId}/modules`,
                { modules },
            );

            return data.data.modules;
        },
        onSuccess: () => notify.success('Branch modules saved'),
    });
}

/**
 * What one person can actually do at one branch, and which level decided it.
 *
 * Six levels now answer before a button appears, and they all look identical
 * from outside — so this is read only when somebody is actually asking "why
 * can't they…", never to build a screen from.
 */
export function useEffectivePermissions(userId: number | undefined, enabled: boolean) {
    return useQuery({
        queryKey: resourceKey('tenant/users', userId, 'effective-permissions'),
        queryFn: async (): Promise<EffectivePermissions> => {
            const { data } = await http.get<ApiResponse<EffectivePermissions>>(
                `/tenant/users/${userId}/effective-permissions`,
            );

            return data.data;
        },
        enabled: enabled && Boolean(userId),
    });
}

/*
|------------------------------------------------------------------------------
| Which modules a branch may NOT switch off
|------------------------------------------------------------------------------
|
| The organization's answer, one per module, so it is keyed on its own rather
| than under any branch: locking billing is not a fact about Delhi.
*/

export function useModuleLocks() {
    return useQuery({
        queryKey: resourceKey('tenant/module-locks'),
        queryFn: async (): Promise<BranchModule[]> => {
            const { data } = await http.get<ApiResponse<{ modules: BranchModule[] }>>(
                '/tenant/module-locks',
            );

            return data.data.modules;
        },
    });
}

export function useSaveModuleLocks() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (modules: string[]) => {
            const { data } = await http.put<ApiResponse<{ modules: BranchModule[] }>>(
                '/tenant/module-locks',
                { modules },
            );

            return data.data.modules;
        },
        onSuccess: (modules) => {
            client.setQueryData(resourceKey('tenant/module-locks'), modules);

            /*
             * Locking a module switches it back on wherever a branch had it
             * off, so every branch's own module screen is now out of date.
             */
            client.invalidateQueries({ queryKey: resourceKey('tenant/locations') });

            notify.success('Saved');
        },
    });
}

/*
|------------------------------------------------------------------------------
| How one branch customises one of the organization's roles
|------------------------------------------------------------------------------
|
| The level between the role and the person. A branch may only SUBTRACT, so
| these read and write what the branch has taken AWAY — the screen shows the
| inherited set with those switched off, and sends back what remains.
*/

export function useBranchRolePermissions(
    locationId: number | null | undefined,
    roleId: number | undefined,
) {
    return useQuery({
        queryKey: resourceKey('tenant/locations', locationId, 'roles', roleId, 'permissions'),
        queryFn: async (): Promise<BranchRolePermissions> => {
            const { data } = await http.get<ApiResponse<BranchRolePermissions>>(
                `/tenant/locations/${locationId}/roles/${roleId}/permissions`,
            );

            return data.data;
        },
        enabled: Boolean(locationId) && Boolean(roleId),
    });
}

export function useSaveBranchRolePermissions(
    locationId: number | null | undefined,
    roleId: number | undefined,
) {
    const client = useQueryClient();

    return useMutation({
        /** The body names what is ALLOWED here; the server stores the rest. */
        mutationFn: async (capabilities: string[]) => {
            const { data } = await http.put<ApiResponse<BranchRolePermissions>>(
                `/tenant/locations/${locationId}/roles/${roleId}/permissions`,
                { capabilities },
            );

            return data.data;
        },
        onSuccess: (data) => {
            client.setQueryData(
                resourceKey('tenant/locations', locationId, 'roles', roleId, 'permissions'),
                data,
            );

            /*
             * This can change what the person saving it may do — a branch
             * manager may have just taken a capability off their own role — so
             * the session has to be re-read rather than trusted.
             */
            client.invalidateQueries({ queryKey: ['tenant', 'session'] });

            notify.success('Saved for this branch');
        },
    });
}
