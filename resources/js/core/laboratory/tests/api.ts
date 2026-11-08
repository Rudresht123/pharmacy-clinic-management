import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import type { LabTest, ReferenceRange } from './types';

/*
 * Every successful write invalidates every read (shared/api/queryClient), so
 * these hooks only describe what they fetch.
 */

export interface LabTestFilters {
    department_id?: number;
    active?: 0 | 1;
    outsourced?: 0 | 1;
}

export function useLabTests(params: TableQueryParams & LabTestFilters) {
    return useQuery({
        queryKey: ['tenant/lab', 'tests', params],
        queryFn: async (): Promise<Page<LabTest>> => {
            const { data } = await http.get<Page<LabTest>>('/tenant/lab/tests', { params });

            return data;
        },
        placeholderData: (previous) => previous,
    });
}

export function useLabTest(id: number | undefined) {
    return useQuery({
        queryKey: ['tenant/lab', 'tests', id],
        queryFn: async (): Promise<LabTest> => {
            const { data } = await http.get<ApiResponse<LabTest>>(`/tenant/lab/tests/${id}`);

            return data.data;
        },
        enabled: id !== undefined,
    });
}

export function useSaveLabTest() {
    return useMutation({
        mutationFn: async ({ id, payload }: { id?: number; payload: Record<string, unknown> }) => {
            const { data } = id
                ? await http.put<ApiResponse<LabTest>>(`/tenant/lab/tests/${id}`, payload, { silent: true })
                : await http.post<ApiResponse<LabTest>>('/tenant/lab/tests', payload, { silent: true });

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Test saved'),
    });
}

export function useRemoveLabTest() {
    return useMutation({
        mutationFn: async ({ id, reason }: { id: number; reason: string }) => {
            const { data } = await http.delete<ApiResponse<null>>(`/tenant/lab/tests/${id}`, {
                data: { reason },
                silent: true,
            });

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Test removed'),
    });
}

export function useSaveRanges() {
    return useMutation({
        mutationFn: async ({ parameterId, ranges }: { parameterId: number; ranges: Record<string, unknown>[] }) => {
            const { data } = await http.put<ApiResponse<ReferenceRange[]>>(
                `/tenant/lab/parameters/${parameterId}/ranges`,
                { ranges },
                { silent: true },
            );

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Reference ranges saved'),
    });
}

export function useSaveBranchPrices() {
    return useMutation({
        mutationFn: async ({ testId, prices }: { testId: number; prices: Record<string, unknown>[] }) => {
            const { data } = await http.put<ApiResponse<LabTest>>(
                `/tenant/lab/tests/${testId}/prices`,
                { prices },
                { silent: true },
            );

            return data;
        },
        onSuccess: (response) => notify.success(response.message ?? 'Branch prices saved'),
    });
}
