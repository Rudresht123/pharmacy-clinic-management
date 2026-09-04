import { useQuery } from '@tanstack/react-query';
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
