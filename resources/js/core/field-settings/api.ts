import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type {
    ConfigurableEntity,
    EntityLabel,
    FieldSettingInput,
    FieldSettingsPayload,
} from './types';

export type { ConfigurableEntity } from './types';

const settingsKey = (entity: ConfigurableEntity) => ['tenant', 'field-settings', entity];

/**
 * The effective schema for one screen, and the types a new field may take.
 *
 * Readable by anyone signed in — the forms are built from it. Only the owner
 * may save.
 */
export function useFieldSettings(entity: ConfigurableEntity) {
    return useQuery({
        queryKey: settingsKey(entity),
        queryFn: async (): Promise<FieldSettingsPayload> => {
            const { data } = await http.get<ApiResponse<FieldSettingsPayload>>(
                `/tenant/settings/fields/${entity}`,
            );

            return data.data;
        },
    });
}

export function useSaveFieldSettings(entity: ConfigurableEntity) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: {
            fields: FieldSettingInput[];
            label?: { singular: string; plural: string };
        }) => {
            const { data } = await http.put<ApiResponse<FieldSettingsPayload>>(
                `/tenant/settings/fields/${entity}`,
                payload,
            );

            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: settingsKey(entity) });

            // The form and list that render from this schema are now stale.
            queryClient.invalidateQueries({ queryKey: ['tenant', entity] });
            queryClient.invalidateQueries({ queryKey: ['tenant', 'locations', 'fields'] });
            queryClient.invalidateQueries({ queryKey: ['tenant', 'users', 'fields'] });

            notify.success('Field settings saved');
        },
    });
}

/**
 * Which screens can be configured, straight from the server's FieldRegistry.
 *
 * Fetched rather than hardcoded so a new configurable entity appears as a
 * tab without a frontend change.
 */
export function useConfigurableEntities() {
    return useQuery({
        queryKey: ['tenant', 'field-settings', 'entities'],
        queryFn: async (): Promise<EntityLabel[]> => {
            const { data } = await http.get<ApiResponse<EntityLabel[]>>('/tenant/settings/fields');

            return data.data;
        },
        staleTime: 30 * 60 * 1000,
    });
}

/**
 * What this organization calls each record.
 *
 * One query for the whole app, so the sidebar, a page title and a button
 * cannot end up saying different words.
 */
export function useEntityLabel(entity: ConfigurableEntity) {
    const { data } = useConfigurableEntities();
    const match = data?.find((item) => item.entity === entity);

    return {
        singular: match?.singular ?? '',
        plural: match?.label ?? '',
    };
}
