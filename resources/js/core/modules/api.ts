import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { Page } from '@/shared/api/resource';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import type { ModuleCataloguePayload, ModuleOrganization } from './types';

/*
 * Exported because saving an organization's modules lives in the
 * organizations feature but changes every count on this one. A written-out
 * string in two places is how those two silently stop matching.
 */
export const MODULES_ENDPOINT = 'admin/modules';

const ENDPOINT = MODULES_ENDPOINT;

/**
 * The catalogue with adoption counts.
 *
 * Keyed under its own endpoint so binding a module on an organization —
 * which invalidates that organization's key, not this one — does not leave
 * the counts stale. Refetched on mount instead; the screen is a reference,
 * not a live dashboard.
 */
export function useModuleCatalogue() {
    return useQuery({
        queryKey: resourceKey(ENDPOINT),
        queryFn: async (): Promise<ModuleCataloguePayload> => {
            const { data } = await http.get<ApiResponse<ModuleCataloguePayload>>('/admin/modules');

            return data.data;
        },
    });
}

/**
 * Organizations, summarised by what they have been sold.
 *
 * The list an administrator assigning a module starts from, so it is the
 * first thing the Modules screen shows.
 */
export function useModuleOrganizations(params: TableQueryParams) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'organizations', params),
        queryFn: async (): Promise<Page<ModuleOrganization>> => {
            const { data } = await http.get<Page<ModuleOrganization>>(
                '/admin/modules/organizations',
                { params },
            );

            return data;
        },
        placeholderData: keepPreviousData,
    });
}
