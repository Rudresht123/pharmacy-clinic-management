import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import { NEXT_ACTION_STEP } from './workflow';
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
                params: {
                    // Omitted rather than sent empty: the doctor is a filter,
                    // and no filter is the branch's whole day.
                    ...(doctorId === '' ? {} : { doctor_id: doctorId }),
                    location_id: locationId,
                    date,
                },
            });

            return data.data;
        },
        enabled: Boolean(locationId && date),
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
 * THE DESK'S moves. Every one is its own verb.
 *
 * Not a `status` field somebody can set to anything: the state machine is
 * the point, and the server refuses a move that does not follow.
 *
 * `start` and `complete` are deliberately NOT in this list any more. They
 * were, behind the same capability as `check-in`, which is how the
 * receptionist's queue came to carry a button that closed a doctor's
 * consultation. They live in useMoveConsultation below, on the doctor's own
 * endpoints.
 */
export function useMoveAppointment() {
    return useMutation({
        mutationFn: async ({
            id,
            action,
            reason,
        }: {
            id: number;
            action: 'check-in' | 'call' | 'cancel' | 'no-show';
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

/**
 * THE DOCTOR'S moves, on the consultation's own endpoints.
 *
 * A separate hook rather than three more actions on the one above, because
 * the separation is the feature: these need `appointments.consult_start` or
 * `appointments.consult_complete`, and the server also checks the visit
 * belongs to whoever is signed in. A receptionist calling any of them is
 * refused twice over.
 *
 * The response carries the RECOMPUTED visit — `status` and `next_action` as
 * the server has just worked them out — so the screen learns where to send
 * the patient from the same request that finished the consultation, rather
 * than from a second read that could disagree with it.
 */
export function useMoveConsultation() {
    return useMutation({
        mutationFn: async ({
            id,
            action,
        }: {
            id: number;
            action: 'start' | 'complete' | 'reopen';
        }) => {
            const { data } = await http.post<ApiResponse<Appointment>>(
                `/tenant/appointments/${id}/consultation/${action}`,
            );

            return data;
        },

        /*
         * The next step, said out loud, the moment the consultation ends.
         *
         * "Consultation completed" on its own leaves the doctor to guess
         * whether the patient can go home — which is the question the
         * workflow exists to answer, and the server has just answered it in
         * the same response. Anything other than `none` is worth a sentence:
         * it is what the doctor tells the person in front of them.
         */
        onSuccess: (response) => {
            const next = response.data?.next_action;
            const step = next && next !== 'none' ? NEXT_ACTION_STEP[next].label : null;

            notify.success(
                step
                    ? `${response.message ?? 'Updated'} · ${step}`
                    : (response.message ?? 'Updated'),
            );
        },
    });
}
