import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';

export type RescheduleStatus = 'pending' | 'approved' | 'rejected' | 'withdrawn';

/** A patient's request to move a booking, as the desk sees it. */
export interface RescheduleRequest {
    id: number;
    status: RescheduleStatus;
    appointment_id: number;
    patient: { id: number; name: string | null; code: string | null; phone: string | null };
    doctor: { id: number; name: string | null };
    location: { id: number; name: string | null };
    from: { date: string | null; slot_at: string | null };
    requested: {
        date: string | null;
        slot_at: string | null;
        location_id: number | null;
        location_name: string | null;
    };
    approved: { date: string; slot_at: string | null } | null;
    reason: string | null;
    decision_note: string | null;
    decided_by: string | null;
    decided_at: string | null;
    created_at: string | null;
}

/** Who is told / who may: the desk, the doctor, or either. */
export type Who = 'desk' | 'doctor' | 'both';

export interface AppointmentSettings {
    reschedule_notify: Who;
    check_in_by: Who;
    call_by: Who;
    consult_start_by: Who;
}

const ROOT = '/tenant/reschedule-requests';

export function useRescheduleRequests(status: RescheduleStatus | 'all') {
    return useQuery({
        queryKey: ['reschedule-requests', status],
        queryFn: async () => {
            const { data } = await http.get<ApiResponse<RescheduleRequest[]>>(ROOT, { params: { status } });

            return data.data;
        },
    });
}

/**
 * How many are waiting — the queue screen's banner. Polled, so a request a
 * patient sent while the desk had the queue open shows up without a reload.
 */
export function usePendingRescheduleCount(enabled: boolean) {
    return useQuery({
        queryKey: ['reschedule-requests', 'count'],
        enabled,
        refetchInterval: 60_000,
        queryFn: async () => {
            const { data } = await http.get<ApiResponse<{ pending: number }>>(`${ROOT}/count`);

            return data.data.pending;
        },
    });
}

export interface ApproveBody {
    appointment_date?: string;
    slot_at?: string;
    location_id?: number;
    note?: string;
}

export function useAnswerReschedule() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            id,
            answer,
            body,
        }: {
            id: number;
            answer: 'approve' | 'reject';
            body: ApproveBody;
        }) => {
            const { data } = await http.post<ApiResponse<RescheduleRequest>>(`${ROOT}/${id}/${answer}`, body);

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Done');
            void queryClient.invalidateQueries({ queryKey: ['reschedule-requests'] });
            // The day the visit moved off and the day it moved to both changed.
            void queryClient.invalidateQueries({ queryKey: ['tenant/appointments'] });
        },
    });
}

export function useAppointmentSettings() {
    return useQuery({
        queryKey: ['appointment-settings'],
        queryFn: async () => {
            const { data } = await http.get<ApiResponse<AppointmentSettings>>('/tenant/appointment-settings');

            return data.data;
        },
    });
}

export function useSaveAppointmentSettings() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: Partial<AppointmentSettings>) => {
            const { data } = await http.put<ApiResponse<AppointmentSettings>>('/tenant/appointment-settings', body);

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Saved');
            queryClient.setQueryData(['appointment-settings'], data.data);
        },
    });
}
