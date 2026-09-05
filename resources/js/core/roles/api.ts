import { useQuery } from '@tanstack/react-query';
import { useMutation } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { BranchModule, GrantableModule, Role, RoleMember, RolePayload } from './types';

export const rolesApi = createResourceApi<Role, RolePayload>('tenant/roles');

export const rolesHooks = createResourceHooks(rolesApi, {
    singular: 'Role',
    plural: 'Roles',
});

/**
 * The pool this organization may hand out, grouped by module.
 *
 * Keyed inside the roles namespace so saving a role refreshes it too —
 * `keys.all` prefix-matches this, which an array of its own would not.
 */
export function useGrantable() {
    return useQuery({
        queryKey: resourceKey(rolesApi.endpoint, 'grantable'),
        queryFn: async (): Promise<GrantableModule[]> => {
            const { data } = await http.get<ApiResponse<{ modules: GrantableModule[] }>>(
                '/tenant/roles/grantable',
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
