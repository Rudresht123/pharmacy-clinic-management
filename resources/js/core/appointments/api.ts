import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { Appointment, BookingInput, OpenSession, QueuePayload } from './types';

const ENDPOINT = 'tenant/appointments';

/**
 * One doctor's day at one branch, in arrival order.
 *
 * Refetched on an interval as well as on every write: a queue is a shared
 * screen, and the desk next to you checking somebody in has to show up here
 * without anybody reloading.
 */
export function useQueue(doctorId: number | '', locationId: number | '', date: string) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'queue', doctorId, locationId, date),
        queryFn: async (): Promise<QueuePayload> => {
            const { data } = await http.get<ApiResponse<QueuePayload>>('/tenant/appointments', {
                params: { doctor_id: doctorId, location_id: locationId, date },
            });

            return data.data;
        },
        enabled: Boolean(doctorId && locationId && date),
        refetchInterval: 30_000,
    });
}

/** What is still free — availability minus what has been booked. */
export function useOpenSlots(doctorId: number | '', locationId: number | '', date: string) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'slots', doctorId, locationId, date),
        queryFn: async (): Promise<OpenSession[]> => {
            const { data } = await http.get<ApiResponse<{ sessions: OpenSession[] }>>(
                `/tenant/appointments/slots/${doctorId}`,
                { params: { date, location_id: locationId } },
            );

            return data.data.sessions;
        },
        enabled: Boolean(doctorId && locationId && date),
    });
}

export function useBookAppointment() {
    return useMutation({
        mutationFn: async (payload: BookingInput) => {
            const { data } = await http.post<ApiResponse<Appointment>>(
                '/tenant/appointments',
                payload,
            );

            return data;
        },
        // The server names the token it issued, so its message is the one
        // worth showing rather than a generic "saved".
        onSuccess: (response) => notify.success(response.message ?? 'Booked'),
    });
}

/**
 * Every status change is its own verb.
 *
 * Not a `status` field somebody can set to anything: the state machine is
 * the point, and the server refuses a move that does not follow.
 */
export function useMoveAppointment() {
    return useMutation({
        mutationFn: async ({
            id,
            action,
            reason,
        }: {
            id: number;
            action: 'check-in' | 'start' | 'complete' | 'cancel' | 'no-show';
            reason?: string;
        }) => {
            const { data } = await http.post<ApiResponse<Appointment>>(
                `/tenant/appointments/${id}/${action}`,
                reason ? { reason } : {},
            );

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Updated'),
    });
}
