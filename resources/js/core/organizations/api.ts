import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { Organization } from './types';

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
