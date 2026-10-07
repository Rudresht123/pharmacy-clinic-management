import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';

/** Which level the wording on a phone actually came from. */
export type TemplateSource = 'branch' | 'organization' | 'global' | 'default';

export interface TemplateLevel {
    title: string;
    body: string;
    is_active: boolean;
}

export interface NotificationTypeRow {
    key: string;
    label: string;
    audience: string;
    /** The only blanks this notification may use — anything else is refused on save. */
    variables: { name: string; label: string }[];
    effective: { title: string; body: string; source: TemplateSource };
    global: TemplateLevel | null;
    organization: TemplateLevel | null;
    branch: TemplateLevel | null;
    default: { title: string; body: string };
}

export interface NotificationTemplates {
    branch_id: number | null;
    types: NotificationTypeRow[];
}

const ENDPOINT = 'tenant/notification-templates';

const key = (branchId: number | null) => resourceKey(ENDPOINT, branchId ?? 'organization');

/** Every notification type, each level's wording, and what is really sent — at a branch, or organisation-wide. */
export function useNotificationTemplates(branchId: number | null) {
    return useQuery({
        queryKey: key(branchId),
        queryFn: async (): Promise<NotificationTemplates> => {
            const { data } = await http.get<ApiResponse<NotificationTemplates>>(`/${ENDPOINT}`, {
                params: branchId === null ? {} : { branch_id: branchId },
            });

            return data.data;
        },
    });
}

interface Save {
    type: string;
    /** Null for the organisation's own version. */
    branchId: number | null;
    title: string;
    body: string;
    is_active?: boolean;
}

function path(type: string, branchId: number | null): string {
    return branchId === null ? `/${ENDPOINT}/${type}` : `/${ENDPOINT}/${type}/branches/${branchId}`;
}

/**
 * Save one level's wording. Every cached view goes stale — an organisation
 * change shows through at every branch that has not reworded it.
 */
export function useSaveNotificationTemplate() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async ({ type, branchId, ...body }: Save) => {
            const { data } = await http.put<ApiResponse<NotificationTemplates>>(path(type, branchId), body);

            return data.data;
        },
        onSuccess: () => {
            client.invalidateQueries({ queryKey: resourceKey(ENDPOINT) });
            notify.success('Notification saved');
        },
    });
}

/** Remove one level's wording, so the level below speaks again. */
export function useResetNotificationTemplate() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async ({ type, branchId }: { type: string; branchId: number | null }) => {
            const { data } = await http.delete<ApiResponse<NotificationTemplates>>(path(type, branchId));

            return data.data;
        },
        onSuccess: () => {
            client.invalidateQueries({ queryKey: resourceKey(ENDPOINT) });
            notify.success('Back to the inherited wording');
        },
    });
}
