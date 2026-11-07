import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { LabSettings, MasterDefinition, MasterKey, MasterRow } from './types';

/*
 * Every successful write invalidates every read (shared/api/queryClient), so
 * these hooks only describe what they fetch.
 */

export function useLabSettings() {
    return useQuery({
        queryKey: ['tenant/lab', 'settings'],
        queryFn: async (): Promise<LabSettings> => {
            const { data } = await http.get<ApiResponse<LabSettings>>('/tenant/lab/settings');

            return data.data;
        },
    });
}

export function useSaveLabSettings() {
    return useMutation({
        mutationFn: async (payload: Partial<LabSettings>): Promise<LabSettings> => {
            const { data } = await http.put<ApiResponse<LabSettings>>('/tenant/lab/settings', payload);

            return data.data;
        },
        onSuccess: () => notify.success('Laboratory settings saved'),
    });
}

/** What each list is — its words and fields. Changes only with a release. */
export function useLabMasterCatalogue() {
    return useQuery({
        queryKey: ['tenant/lab', 'masters'],
        queryFn: async (): Promise<MasterDefinition[]> => {
            const { data } = await http.get<ApiResponse<MasterDefinition[]>>('/tenant/lab/masters');

            return data.data;
        },
        staleTime: Infinity,
    });
}

/** One list. `activeOnly` for a picker; the settings table shows everything. */
export function useLabMaster(master: MasterKey | undefined, activeOnly = false) {
    return useQuery({
        queryKey: ['tenant/lab', 'masters', master, { activeOnly }],
        queryFn: async (): Promise<MasterRow[]> => {
            const { data } = await http.get<ApiResponse<MasterRow[]>>(`/tenant/lab/masters/${master}`, {
                params: activeOnly ? { active_only: 1 } : undefined,
            });

            return data.data;
        },
        enabled: master !== undefined,
    });
}

export function useSaveLabMasterRow(master: MasterKey) {
    return useMutation({
        mutationFn: async ({ id, payload }: { id?: number; payload: Record<string, unknown> }) => {
            const { data } = id
                ? await http.put<ApiResponse<MasterRow>>(`/tenant/lab/masters/${master}/${id}`, payload)
                : await http.post<ApiResponse<MasterRow>>(`/tenant/lab/masters/${master}`, payload);

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Saved'),
    });
}

export function useRemoveLabMasterRow(master: MasterKey) {
    return useMutation({
        mutationFn: async ({ id, reason }: { id: number; reason: string }) => {
            const { data } = await http.delete<ApiResponse<null>>(`/tenant/lab/masters/${master}/${id}`, {
                data: { reason },
                silent: true,
            });

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Removed'),
    });
}

export function useLoadLabStarter(master: MasterKey) {
    return useMutation({
        mutationFn: async () => {
            const { data } = await http.post<ApiResponse<MasterRow[]>>(`/tenant/lab/masters/${master}/starter`);

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Starter list loaded'),
    });
}
