import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { Location, LocationField } from './types';

export const locationsApi = createResourceApi<Location>('tenant/locations');

const FIELDS_KEY = resourceKey(locationsApi.endpoint, 'fields');

export const locationsHooks = createResourceHooks(locationsApi, {
    singular: 'Location',
    plural: 'Locations',
});

/**
 * The field definitions the form and table render from.
 *
 * Fetched rather than hardcoded so that per-organization configuration can
 * be layered in server-side without either screen changing. The stale time
 * is long because this changes when an admin changes it, not while someone
 * is filling in a form — and the writes that *do* change it invalidate this
 * key, so a long stale time never means stale data on screen.
 */
export function useLocationFields() {
    return useQuery({
        queryKey: FIELDS_KEY,
        queryFn: async (): Promise<LocationField[]> => {
            const { data } = await http.get<ApiResponse<LocationField[]>>(
                '/tenant/locations/fields',
            );

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}

/** One branch, with its letterhead mark loaded. */
export function useLocation(id: number | null | undefined) {
    return useQuery({
        queryKey: resourceKey(locationsApi.endpoint, 'one', id),
        enabled: Boolean(id),
        queryFn: async (): Promise<Location> => {
            const { data } = await http.get<ApiResponse<Location>>(
                `/tenant/locations/${id}`,
            );

            return data.data;
        },
    });
}

/**
 * The ORGANISATION's letterhead mark — what every branch prints unless it has
 * one of its own.
 *
 * Owner-only on the route. It lives on the master organisations row rather
 * than in the tenant database, which is why it is not just another branch.
 */
export function useOrganizationLogo() {
    const upload = useMutation({
        mutationFn: async (file: File): Promise<void> => {
            const body = new FormData();
            body.append('logo', file);

            await http.post('/tenant/setup/organization/logo', body);
        },
    });

    const remove = useMutation({
        mutationFn: async (): Promise<void> => {
            await http.delete('/tenant/setup/organization/logo');
        },
    });

    return { upload, remove };
}

/**
 * The branch's letterhead mark, changed from wherever it is on screen.
 *
 * It writes to the BRANCH, not to the template being edited. A logo kept per
 * template would be a second copy to keep in step, and the first bill printed
 * from the other template would show the old one.
 */
export function useBranchLogo(id: number | null | undefined) {
    const client = useQueryClient();

    const settled = () => {
        void client.invalidateQueries({ queryKey: resourceKey(locationsApi.endpoint, 'one', id) });
        void client.invalidateQueries({ queryKey: resourceKey(locationsApi.endpoint) });
    };

    const upload = useMutation({
        mutationFn: async (file: File): Promise<Location> => {
            const body = new FormData();
            body.append('logo', file);

            const { data } = await http.post<ApiResponse<Location>>(
                `/tenant/locations/${id}/logo`,
                body,
            );

            return data.data;
        },
        onSuccess: settled,
    });

    const remove = useMutation({
        mutationFn: async (): Promise<Location> => {
            const { data } = await http.delete<ApiResponse<Location>>(
                `/tenant/locations/${id}/logo`,
            );

            return data.data;
        },
        onSuccess: settled,
    });

    return { upload, remove };
}
