import { useMutation, useQuery } from '@tanstack/react-query';
import { createResourceApi, type Page } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import type {
    PharmacyStore,
    StoreFormOptions,
    StoreMedicine,
    StoreMedicineInput,
} from './types';

export const storesApi = createResourceApi<PharmacyStore>('tenant/pharmacy-stores');

export const storesHooks = createResourceHooks(storesApi, {
    singular: 'Store',
    plural: 'Stores',
});

/**
 * Branches and people the store form may choose from.
 *
 * Behind `pharmacy.stores`, so only asked for by those who hold it.
 */
export function useStoreFormOptions(enabled = true) {
    return useQuery({
        queryKey: resourceKey(storesApi.endpoint, 'form-options'),
        queryFn: async (): Promise<StoreFormOptions> => {
            const { data } = await http.get<ApiResponse<StoreFormOptions>>(
                '/tenant/pharmacy-stores/form-options',
            );

            return data.data;
        },
        enabled,
    });
}

/** Removed stores, for whoever may bring them back. */
export function useRemovedStores(params: TableQueryParams, enabled: boolean) {
    return useQuery({
        queryKey: resourceKey(storesApi.endpoint, 'removed', params),
        queryFn: async (): Promise<Page<PharmacyStore>> => {
            const { data } = await http.get<Page<PharmacyStore>>(
                '/tenant/pharmacy-stores/removed',
                { params },
            );

            return data;
        },
        enabled,
    });
}

// Removing and restoring take a reason, so they cannot use the resource's
// plain DELETE. Invalidation is the query client's job, for every mutation.

export function useRemoveStore() {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/pharmacy-stores/${id}`, { data: { reason } }),
        onSuccess: () => notify.success('Store removed'),
    });
}

export function useRestoreStore() {
    return useMutation({
        // Silent: a refusal is shown in the dialog that asked.
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.post(`/tenant/pharmacy-stores/${id}/restore`, { reason }, { silent: true }),
        onSuccess: () => notify.success('Store restored'),
    });
}

/** Which medicines one store stocks. */
export function useStoreMedicines(storeId: number, params: TableQueryParams) {
    return useQuery({
        queryKey: resourceKey(storesApi.endpoint, storeId, 'medicines', params),
        queryFn: async (): Promise<Page<StoreMedicine>> => {
            const { data } = await http.get<Page<StoreMedicine>>(
                `/tenant/pharmacy-stores/${storeId}/medicines`,
                { params },
            );

            return data;
        },
        enabled: !Number.isNaN(storeId),
    });
}

/** Sets the levels for the rows sent; every other row is left alone. */
export function useSaveStoreMedicines(storeId: number) {
    return useMutation({
        mutationFn: async (medicines: StoreMedicineInput[]) => {
            const { data } = await http.put<ApiResponse<StoreMedicine[]>>(
                `/tenant/pharmacy-stores/${storeId}/medicines`,
                { medicines },
            );

            return data.data;
        },
        onSuccess: () => notify.success('Levels saved'),
    });
}

export function useRemoveStoreMedicine(storeId: number) {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/pharmacy-stores/${storeId}/medicines/${id}`, {
                data: { reason },
            }),
        onSuccess: () => notify.success('Removed from this store'),
    });
}
