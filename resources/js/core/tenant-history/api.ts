import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { Page } from '@/shared/api/resource';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import type { HistoryEntry } from '@/shared/components/ui/HistoryTimeline';

const ENDPOINT = 'tenant/history';

export interface HistoryFilterOptions {
    entity_types: string[];
    actions: string[];
}

/**
 * This organization's own log, from its own database.
 *
 * Never invalidated by a write elsewhere: the log is append-only and almost
 * any action in the app can add to it, so the screen refetches when it is
 * opened rather than pretending to know when it changed.
 */
export function useTenantHistory(params: TableQueryParams & Record<string, unknown>) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, params),
        queryFn: async (): Promise<Page<HistoryEntry>> => {
            const { data } = await http.get<Page<HistoryEntry>>('/tenant/history', { params });

            return data;
        },
        placeholderData: keepPreviousData,
    });
}

export function useTenantHistoryFilters() {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'filters'),
        queryFn: async (): Promise<HistoryFilterOptions> => {
            const { data } =
                await http.get<ApiResponse<HistoryFilterOptions>>('/tenant/history-filters');

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}

/**
 * One record's own story.
 *
 * `entity` is the model's class name as the log stores it — "Customer",
 * "Location". Disabled until asked for, so opening a list does not fetch a
 * history nobody has looked at.
 */
export function useRecordHistory(entity: string, id: number | null) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'record', entity, id),
        queryFn: async (): Promise<Page<HistoryEntry>> => {
            const { data } = await http.get<Page<HistoryEntry>>(`/tenant/history/${entity}/${id}`, {
                params: { per_page: 50 },
            });

            return data;
        },
        enabled: id !== null,
    });
}
