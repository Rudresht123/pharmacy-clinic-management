import { useMutation, useQuery } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { ModuleBindingInput, Organization, OrganizationModulesPayload } from './types';

/**
 * The whole data layer for this module — the factory supplies list/get/
 * create/update/remove and the hooks supply caching and toasts.
 */
export const organizationsApi = createResourceApi<Organization>('admin/organizations');

export const organizationsHooks = createResourceHooks(organizationsApi, {
    singular: 'Organization',
    plural: 'Organizations',
});

/** Resumes a provisioning attempt that stopped short of active. */
export async function retryOrganizationProvisioning(uuid: string): Promise<Organization> {
    const { data } = await http.post<ApiResponse<Organization>>(
        `/admin/organizations/${uuid}/retry-provisioning`,
    );

    return data.data;
}

/**
 * The module catalogue with one organization's entitlements on it.
 *
 * Keyed inside the organizations namespace so saving an organization — or
 * its modules — refreshes it, rather than leaving a screen showing an
 * arrangement that has since changed.
 */
export function useOrganizationModules(uuid: string | undefined) {
    return useQuery({
        queryKey: resourceKey(organizationsApi.endpoint, 'modules', uuid),
        queryFn: async (): Promise<OrganizationModulesPayload> => {
            const { data } = await http.get<ApiResponse<OrganizationModulesPayload>>(
                `/admin/organizations/${uuid}/modules`,
            );

            return data.data;
        },
        enabled: Boolean(uuid),
    });
}

export function useSaveOrganizationModules(uuid: string | undefined) {
    return useMutation({
        mutationFn: async (modules: ModuleBindingInput[]) => {
            const { data } = await http.put<ApiResponse<OrganizationModulesPayload>>(
                `/admin/organizations/${uuid}/modules`,
                { modules },
            );

            return data.data;
        },
        // Invalidation is the query client's job, for every mutation.
        onSuccess: () => notify.success('Modules updated'),
    });
}
