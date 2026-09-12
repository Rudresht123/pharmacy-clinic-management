import { useMutation, useQuery } from '@tanstack/react-query';
import { createResourceApi, type Page } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { ConfigurableField } from '@/core/field-settings/types';
import type { Medicine } from './types';

export const medicinesApi = createResourceApi<Medicine>('tenant/medicines');

export const medicinesHooks = createResourceHooks(medicinesApi, {
    singular: 'Medicine',
    plural: 'Medicines',
});

/**
 * The field definitions the form and table render from — the code registry
 * with this organization's own preferences and extra fields applied.
 */
export function useMedicineFields() {
    return useQuery({
        queryKey: resourceKey(medicinesApi.endpoint, 'fields'),
        queryFn: async (): Promise<ConfigurableField[]> => {
            const { data } =
                await http.get<ApiResponse<ConfigurableField[]>>('/tenant/medicines/fields');

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}

/** Removed medicines, for whoever may bring them back. */
export function useRemovedMedicines(params: TableQueryParams, enabled: boolean) {
    return useQuery({
        queryKey: resourceKey(medicinesApi.endpoint, 'removed', params),
        queryFn: async (): Promise<Page<Medicine>> => {
            const { data } = await http.get<Page<Medicine>>('/tenant/medicines/removed', {
                params,
            });

            return data;
        },
        enabled,
    });
}

/**
 * Removing takes a reason, so it cannot use the resource's plain DELETE.
 * The reason is kept on the medicine and in its history.
 */
export function useRemoveMedicine() {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/medicines/${id}`, { data: { reason } }),
        // Invalidation is the query client's job, for every mutation.
        onSuccess: () => notify.success('Medicine removed'),
    });
}

export function useRestoreMedicine() {
    return useMutation({
        // Silent: a refusal (a live duplicate has taken its place) is shown
        // in the dialog that asked, not as a second, detached toast.
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.post(`/tenant/medicines/${id}/restore`, { reason }, { silent: true }),
        onSuccess: () => notify.success('Medicine restored'),
    });
}
