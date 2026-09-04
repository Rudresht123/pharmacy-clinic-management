import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { Page } from '@/shared/api/resource';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import type { HistoryEntry } from '@/shared/components/ui/HistoryTimeline';
import type { AuditFilterOptions } from './types';

const ENDPOINT = 'admin/audit';

/**
 * The whole platform log.
 *
 * Never invalidated by anything: the log is append-only and a write anywhere
 * else in the app can add to it, so the query simply refetches when the
 * screen is opened rather than pretending to know when it changed.
 */
export function useAuditLog(params: TableQueryParams & Record<string, unknown>) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, params),
        queryFn: async (): Promise<Page<HistoryEntry>> => {
            const { data } = await http.get<Page<HistoryEntry>>('/admin/audit', { params });

            return data;
        },
        placeholderData: keepPreviousData,
    });
}

/** The filter values that actually occur in the log, not a hardcoded list. */
export function useAuditFilters() {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'filters'),
        queryFn: async (): Promise<AuditFilterOptions> => {
            const { data } =
                await http.get<ApiResponse<AuditFilterOptions>>('/admin/audit/filters');

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}

/** Everything that has happened to one organization, including its modules. */
export function useOrganizationHistory(uuid: string, params: TableQueryParams) {
    return useQuery({
        queryKey: resourceKey('admin/organizations', 'history', uuid, params),
        queryFn: async (): Promise<Page<HistoryEntry>> => {
            const { data } = await http.get<Page<HistoryEntry>>(
                `/admin/organizations/${uuid}/history`,
                { params },
            );

            return data;
        },
        placeholderData: keepPreviousData,
    });
}
