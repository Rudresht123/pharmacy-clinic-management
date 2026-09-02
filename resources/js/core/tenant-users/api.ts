import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { ConfigurableField } from '@/core/field-settings/types';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
import type { TenantUser } from '@/core/tenant-auth/api';

/**
 * The organization's own people. Owner-only on the server, so every call
 * here assumes the signed-in user is one.
 */
export const tenantUsersApi = createResourceApi<TenantUser>('tenant/users');

export const tenantUsersHooks = createResourceHooks(tenantUsersApi, {
    singular: 'User',
    plural: 'Users',
});

/**
 * The field definitions the People form renders from — the code registry
 * with this organization's own preferences and extra fields applied.
 */
export function useTenantUserFields() {
    return useQuery({
        queryKey: ['tenant', 'users', 'fields'],
        queryFn: async (): Promise<ConfigurableField[]> => {
            const { data } = await http.get<ApiResponse<ConfigurableField[]>>(
                '/tenant/users/fields',
            );

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}
