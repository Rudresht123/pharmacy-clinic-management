import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type {
    AvailabilityDay,
    AvailabilityWeek,
    ScheduleException,
    ScheduleExceptionInput,
} from './types';

const ENDPOINT = 'tenant/availability';

/**
 * A branch's day.
 *
 * Derived server-side from the weekly sittings, the exceptions on that date
 * and each sitting's effective window — there is no slot table to read, and
 * the browser must not try to work it out for itself.
 */
export function useAvailabilityDay(date: string, locationId: number | '') {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'day', date, locationId),
        queryFn: async (): Promise<AvailabilityDay> => {
            const { data } = await http.get<ApiResponse<AvailabilityDay>>(
                '/tenant/availability/day',
                { params: { date, location_id: locationId } },
            );

            return data.data;
        },
        enabled: Boolean(date && locationId),
    });
}

/**
 * A branch's week — every doctor posted there, against seven dates.
 *
 * One request rather than seven days fetched separately: the grid is read
 * across, and seven responses arriving at seven moments could disagree about
 * a sitting somebody cancelled while it loaded.
 */
export function useAvailabilityWeek(from: string, locationId: number | '') {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'week', from, locationId),
        queryFn: async (): Promise<AvailabilityWeek> => {
            const { data } = await http.get<ApiResponse<AvailabilityWeek>>(
                '/tenant/availability/week',
                { params: { from, location_id: locationId } },
            );

            return data.data;
        },
        enabled: Boolean(from && locationId),
    });
}

/** Leave, holidays, moved hours and extra clinics — from today onwards. */
export function useScheduleExceptions(params: Record<string, unknown> = {}) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'exceptions', params),
        queryFn: async (): Promise<ScheduleException[]> => {
            const { data } = await http.get<ApiResponse<ScheduleException[]>>(
                '/tenant/availability/exceptions',
                { params },
            );

            return data.data;
        },
    });
}

export function useSaveScheduleException() {
    return useMutation({
        mutationFn: async (payload: ScheduleExceptionInput) => {
            const { data } = await http.post<ApiResponse<{ id: number }>>(
                '/tenant/availability/exceptions',
                payload,
            );

            return data.data;
        },
        // Invalidation is the query client's job, for every mutation.
        onSuccess: () => notify.success('Saved'),
    });
}

export function useRemoveScheduleException() {
    return useMutation({
        mutationFn: async (id: number) => {
            await http.delete(`/tenant/availability/exceptions/${id}`);
        },
        onSuccess: () => notify.success('Removed'),
    });
}
