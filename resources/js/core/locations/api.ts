import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type {
    FieldSettingInput,
    FieldSettingsPayload,
    Location,
    LocationField,
} from './types';

const FIELDS_KEY = ['tenant', 'locations', 'fields'];
const FIELD_SETTINGS_KEY = ['tenant', 'locations', 'field-settings'];

export const locationsApi = createResourceApi<Location>('tenant/locations');

export const locationsHooks = createResourceHooks(locationsApi, {
    singular: 'Location',
    plural: 'Locations',
});

/**
 * The field definitions the form and table render from.
 *
 * Fetched rather than hardcoded so that per-organization configuration can
 * be layered in server-side without either screen changing. Static today,
 * hence the long stale time — it changes when an admin changes it, not
 * while someone is filling in a form.
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

/** The settings screen's own view: the schema plus the types on offer. */
export function useLocationFieldSettings() {
    return useQuery({
        queryKey: FIELD_SETTINGS_KEY,
        queryFn: async (): Promise<FieldSettingsPayload> => {
            const { data } = await http.get<ApiResponse<FieldSettingsPayload>>(
                '/tenant/settings/fields/location',
            );

            return data.data;
        },
    });
}

export function useSaveLocationFieldSettings() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (fields: FieldSettingInput[]) => {
            const { data } = await http.put<ApiResponse<FieldSettingsPayload>>(
                '/tenant/settings/fields/location',
                { fields },
            );

            return data.data;
        },
        onSuccess: () => {
            // The form and the list both render from this, so both are stale.
            queryClient.invalidateQueries({ queryKey: FIELD_SETTINGS_KEY });
            queryClient.invalidateQueries({ queryKey: FIELDS_KEY });
            notify.success('Field settings saved');
        },
    });
}
