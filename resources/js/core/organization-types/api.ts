import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { OrganizationType } from '@/core/organizations/types';

export const organizationTypesApi = createResourceApi<OrganizationType>('admin/organization-types');

export const organizationTypesHooks = createResourceHooks(organizationTypesApi, {
    singular: 'Organization type',
    plural: 'Organization types',
});

export async function toggleOrganizationTypeStatus(id: number): Promise<OrganizationType> {
    const { data } = await http.patch<ApiResponse<OrganizationType>>(
        `/admin/organization-types/${id}/toggle-status`,
    );

    return data.data;
}
