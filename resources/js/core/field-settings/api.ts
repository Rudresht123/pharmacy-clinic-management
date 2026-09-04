import { useMutation, useQuery } from '@tanstack/react-query';
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

const SETTINGS_ROOT = ['tenant', 'field-settings'] as const;
const settingsKey = (entity: ConfigurableEntity) => [...SETTINGS_ROOT, entity];

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
        // Invalidation is the query client's job, for every mutation.
        onSuccess: () => notify.success('Field settings saved'),
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
        queryKey: [...SETTINGS_ROOT, 'entities'],
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
