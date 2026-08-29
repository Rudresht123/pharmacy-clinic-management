import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
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
