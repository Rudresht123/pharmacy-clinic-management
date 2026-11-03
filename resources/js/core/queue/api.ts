import { useEffect, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http, resolveErrorMessage } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import { realtimeClient, useRealtimeConnected } from '@/shared/realtime/echo';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import type { ApiResponse } from '@/shared/types/api';
import {
    QUEUE_EVENTS,
    type DisplayState,
    type DoctorQueuePayload,
    type DoctorQueueSetting,
    type QueueBoardPayload,
    type QueueDisplay,
    type QueueDisplayInput,
    type QueueDisplayOptions,
    type QueueHistoryRow,
    type QueueMessage,
    type QueueSettingsPayload,
} from './types';

const ENDPOINT = 'tenant/queue';
const DISPLAYS = 'tenant/queue-displays';

/*
 * Slow while the socket is really there, because the channels keep the screen
 * right and this is only a safety net. Quick while it is not, so a Reverb that
 * is down costs seconds of staleness rather than a screen that quietly stopped.
 */
const refreshEvery = (live: boolean) => (live ? 60_000 : 10_000);

export function useQueueBoard(locationId: number | '', departmentId: number | '', live: boolean) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'board', locationId, departmentId),
        queryFn: async (): Promise<QueueBoardPayload> => {
            const { data } = await http.get<ApiResponse<QueueBoardPayload>>('/tenant/queue/board', {
                params: {
                    location_id: locationId,
                    ...(departmentId === '' ? {} : { department_id: departmentId }),
                },
            });

            return data.data;
        },
        enabled: Boolean(locationId),
        refetchInterval: refreshEvery(live),
    });
}

export function useDoctorQueue(doctorId: number | null, locationId: number | null, live: boolean) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'doctor', doctorId, locationId),
        queryFn: async (): Promise<DoctorQueuePayload> => {
            const { data } = await http.get<ApiResponse<DoctorQueuePayload>>(`/tenant/queue/doctors/${doctorId}`, {
                params: { location_id: locationId },
            });

            return data.data;
        },
        enabled: Boolean(doctorId && locationId),
        refetchInterval: refreshEvery(live),
    });
}

export function useQueueHistory(doctorId: number | null, locationId: number | null, enabled: boolean) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'history', doctorId, locationId),
        queryFn: async (): Promise<QueueHistoryRow[]> => {
            const { data } = await http.get<ApiResponse<QueueHistoryRow[]>>(
                `/tenant/queue/doctors/${doctorId}/history`,
                { params: { location_id: locationId } },
            );

            return data.data;
        },
        enabled: enabled && Boolean(doctorId && locationId),
    });
}

/**
 * Listen to these doctors' channels.
 *
 * The caller gets every message as it arrives, for the instant update, and any
 * other query on screen is refetched a moment later for the parts a public card
 * does not carry. Debounced, so two doctors calling at once is one refetch.
 * Named by the server and never built here, like every other channel.
 *
 * @returns whether the socket is really connected
 */
export function useQueueLive(channels: string[] | undefined, onMessage?: (message: QueueMessage) => void): boolean {
    const { realtime } = useTenantAuth();
    const client = useQueryClient();
    const connected = useRealtimeConnected(realtime);

    const handler = useRef(onMessage);
    handler.current = onMessage;

    const key = (channels ?? []).join('|');

    useEffect(() => {
        const echo = realtimeClient(realtime);

        if (!echo || key === '') {
            return;
        }

        const names = key.split('|').map((channel) => channel.replace(/^private-/, ''));
        let pending: number | undefined;

        for (const name of names) {
            const channel = echo.private(name);

            for (const event of QUEUE_EVENTS) {
                channel.listen(`.${event}`, (message: QueueMessage) => {
                    handler.current?.(message);

                    window.clearTimeout(pending);
                    pending = window.setTimeout(() => {
                        client.invalidateQueries({ queryKey: resourceKey(ENDPOINT) });
                    }, 400);
                });
            }
        }

        return () => {
            window.clearTimeout(pending);
            names.forEach((name) => echo.leave(name));
        };
    }, [client, key, realtime]);

    return connected;
}

/**
 * The verbs on a doctor's queue. A refusal from the state — nobody waiting,
 * somebody already at the door — comes back as a sentence worth showing.
 */
function useQueueMutation<TInput>(request: (input: TInput) => Promise<{ data: { message?: string } }>) {
    return useMutation({
        mutationFn: request,
        onSuccess: ({ data }) => notify.success(data.message ?? 'Updated'),
        onError: (error) => notify.error(resolveErrorMessage(error)),
    });
}

export function useCallNext() {
    return useQueueMutation(({ doctorId, locationId }: { doctorId: number; locationId: number }) =>
        http.post<ApiResponse<unknown>>(`/tenant/queue/doctors/${doctorId}/call-next`, { location_id: locationId }),
    );
}

export function usePauseQueue() {
    return useQueueMutation(
        ({ doctorId, locationId, reason, minutes }: { doctorId: number; locationId: number; reason?: string; minutes?: number }) =>
            http.post<ApiResponse<unknown>>(`/tenant/queue/doctors/${doctorId}/pause`, {
                location_id: locationId,
                reason: reason || null,
                minutes: minutes ?? null,
            }),
    );
}

export function useResumeQueue() {
    return useQueueMutation(({ doctorId, locationId }: { doctorId: number; locationId: number }) =>
        http.post<ApiResponse<unknown>>(`/tenant/queue/doctors/${doctorId}/resume`, { location_id: locationId }),
    );
}

export type VisitAction = 'skip' | 'recall' | 'requeue' | 'absent';

export function useVisitAction() {
    return useQueueMutation(({ id, action }: { id: number; action: VisitAction }) =>
        http.post<ApiResponse<unknown>>(`/tenant/queue/visits/${id}/${action}`),
    );
}

export function useSetPriority() {
    return useQueueMutation(({ id, priority, reason }: { id: number; priority: string; reason: string }) =>
        http.post<ApiResponse<unknown>>(`/tenant/queue/visits/${id}/priority`, { priority, reason }),
    );
}

export function useReassign() {
    return useQueueMutation(({ id, doctorId, reason }: { id: number; doctorId: number; reason: string }) =>
        http.post<ApiResponse<unknown>>(`/tenant/queue/visits/${id}/reassign`, { doctor_id: doctorId, reason }),
    );
}

/* ------------------------------------------------------------- settings */

export function useQueueSettings(locationId: number | '') {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'settings', locationId),
        queryFn: async (): Promise<QueueSettingsPayload> => {
            const { data } = await http.get<ApiResponse<QueueSettingsPayload>>('/tenant/queue/settings', {
                params: { location_id: locationId },
            });

            return data.data;
        },
        enabled: Boolean(locationId),
    });
}

export function useSaveDoctorQueueSetting() {
    return useMutation({
        mutationFn: async ({
            doctorId,
            locationId,
            values,
        }: {
            doctorId: number;
            locationId: number;
            values: Partial<DoctorQueueSetting>;
        }) => {
            const { data } = await http.put<ApiResponse<DoctorQueueSetting>>(
                `/tenant/queue/settings/doctors/${doctorId}`,
                { location_id: locationId, ...values },
            );

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Saved'),
    });
}

export function useSaveSharedRun() {
    return useQueueMutation(
        ({ departmentId, locationId, prefix }: { departmentId: number; locationId: number; prefix: string }) =>
            http.put<ApiResponse<unknown>>(`/tenant/queue/settings/departments/${departmentId}`, {
                location_id: locationId,
                token_prefix: prefix,
            }),
    );
}

export function useRemoveSharedRun() {
    return useQueueMutation(({ departmentId, locationId }: { departmentId: number; locationId: number }) =>
        http.delete<ApiResponse<unknown>>(`/tenant/queue/settings/departments/${departmentId}`, {
            data: { location_id: locationId },
        }),
    );
}

/* ------------------------------------------------------------- displays */

export function useQueueDisplays() {
    return useQuery({
        queryKey: resourceKey(DISPLAYS),
        queryFn: async (): Promise<QueueDisplay[]> => {
            const { data } = await http.get<ApiResponse<QueueDisplay[]>>('/tenant/queue-displays');

            return data.data;
        },
        // How the screen knows a television has gone dark.
        refetchInterval: 30_000,
    });
}

export function useQueueDisplay(id: number | null) {
    return useQuery({
        queryKey: resourceKey(DISPLAYS, id),
        queryFn: async (): Promise<QueueDisplay> => {
            const { data } = await http.get<ApiResponse<QueueDisplay>>(`/tenant/queue-displays/${id}`);

            return data.data;
        },
        enabled: id !== null,
    });
}

export function useQueueDisplayOptions() {
    return useQuery({
        queryKey: resourceKey(DISPLAYS, 'options'),
        queryFn: async (): Promise<QueueDisplayOptions> => {
            const { data } = await http.get<ApiResponse<QueueDisplayOptions>>('/tenant/queue-displays/options');

            return data.data;
        },
    });
}

export function useSaveQueueDisplay(id: number | null) {
    return useMutation({
        mutationFn: async (values: QueueDisplayInput) => {
            const { data } = id
                ? await http.put<ApiResponse<QueueDisplay>>(`/tenant/queue-displays/${id}`, values)
                : await http.post<ApiResponse<QueueDisplay>>('/tenant/queue-displays', values);

            return data;
        },
    });
}

export type DisplayAction = 'rotate' | 'revoke';

export function useDisplayAction() {
    return useMutation({
        mutationFn: async ({ id, action }: { id: number; action: DisplayAction }) => {
            const { data } = await http.post<ApiResponse<QueueDisplay>>(`/tenant/queue-displays/${id}/${action}`);

            return data;
        },
        onError: (error) => notify.error(resolveErrorMessage(error)),
    });
}

export function useDeleteDisplay() {
    return useQueueMutation((id: number) => http.delete<ApiResponse<unknown>>(`/tenant/queue-displays/${id}`));
}

export function useToggleDisplay() {
    return useQueueMutation(({ display, active }: { display: QueueDisplay; active: boolean }) =>
        http.put<ApiResponse<unknown>>(`/tenant/queue-displays/${display.id}`, {
            display_name: display.display_name,
            display_type: display.display_type,
            location_id: display.location_id,
            department_id: display.department_id,
            doctor_ids: display.doctor_ids,
            is_active: active,
        }),
    );
}

export function useDisplayPreview(id: number | null) {
    return useQuery({
        queryKey: resourceKey(DISPLAYS, id, 'preview'),
        queryFn: async (): Promise<DisplayState> => {
            const { data } = await http.get<ApiResponse<DisplayState>>(`/tenant/queue-displays/${id}/preview`);

            return data.data;
        },
        enabled: id !== null,
        refetchInterval: 5_000,
    });
}
