import { useMutation, useQuery } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type {
    ModuleBindingInput,
    Organization,
    OrganizationModulesPayload,
    ProvisioningProgress,
} from './types';

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
 * How far setting an organization up has got.
 *
 * Polled every second and a half while the queue is working on it, and not
 * at all once it is active or has failed — there is nothing left to watch.
 */
export function useOrganizationProvisioning(uuid: string | undefined, watching: boolean) {
    return useQuery({
        queryKey: resourceKey(organizationsApi.endpoint, 'provisioning', uuid),
        queryFn: async (): Promise<ProvisioningProgress> => {
            const { data } = await http.get<ApiResponse<ProvisioningProgress>>(
                `/admin/organizations/${uuid}/provisioning`,
            );

            return data.data;
        },
        enabled: Boolean(uuid) && watching,
        refetchInterval: (query) => {
            const status = query.state.data?.status;

            return status === 'active' || status === 'failed' ? false : 1500;
        },
    });
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
