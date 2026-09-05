import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { OpdBranch, OpdToday } from './types';

const ENDPOINT = 'tenant/opd';

/**
 * Where this person may run an OPD day.
 *
 * Rarely changes, so it is cached for the session rather than refetched with
 * the rest of the screen — the branch picker must not flicker every time the
 * queue polls.
 */
export function useOpdBranches() {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'branches'),
        queryFn: async (): Promise<OpdBranch[]> => {
            const { data } = await http.get<ApiResponse<OpdBranch[]>>('/tenant/opd/branches');

            return data.data;
        },
        staleTime: 5 * 60_000,
    });
}

/**
 * The department's day: counts, who has waited longest, what each doctor is on.
 *
 * Polled on the same interval as the queue, because both are shared screens
 * that somebody else is changing while you look at them.
 */
export function useOpdToday(locationId: number | '', date: string) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'today', locationId, date),
        queryFn: async (): Promise<OpdToday> => {
            const { data } = await http.get<ApiResponse<OpdToday>>('/tenant/opd/today', {
                params: { location_id: locationId, date },
            });

            return data.data;
        },
        enabled: Boolean(locationId && date),
        refetchInterval: 30_000,
    });
}
