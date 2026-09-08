import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { Consultation, MyDay, OpdBranch, OpdToday } from './types';

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

/**
 * A doctor's own day.
 *
 * One request behind every screen in their section — the dashboard, the queue
 * and the schedule are three readings of it rather than three endpoints. The
 * doctor comes from the session, so there is nothing to pass but the branch.
 */
export function useMyDay(locationId: number | null) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'my-day', locationId),
        queryFn: async (): Promise<MyDay> => {
            const { data } = await http.get<ApiResponse<MyDay>>('/tenant/opd/my-day', {
                params: locationId ? { location_id: locationId } : {},
            });

            return data.data;
        },
        // A queue read between patients is stale the moment it lands.
        refetchInterval: 30_000,
    });
}

/**
 * Write up a visit, or write over it.
 *
 * One consultation per appointment, so this is always the same row — a doctor
 * adding a diagnosis after the prescription is editing what they wrote, not
 * starting a second record. Addressed by the appointment because that is what
 * the doctor has in front of them.
 */
export function useSaveConsultation(appointmentId: number | null) {
    return useMutation({
        mutationFn: async (payload: Partial<Consultation>) => {
            const { data } = await http.put<ApiResponse<Consultation>>(
                `/tenant/appointments/${appointmentId}/consultation`,
                payload,
            );

            return data.data;
        },
        onSuccess: () => notify.success('Written up'),
    });
}
