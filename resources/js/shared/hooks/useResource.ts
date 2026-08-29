import {
    keepPreviousData,
    useMutation,
    useQuery,
    useQueryClient,
    type UseQueryOptions,
} from '@tanstack/react-query';
import { notify } from '@/shared/utils/notify';
import type { Page, ResourceApi, WriteOptions } from '@/shared/api/resource';
import type { TableQueryParams } from './useServerTable';
import type { Id } from '@/shared/types/api';

/**
 * React Query bindings for a ResourceApi.
 *
 * Cache keys, invalidation and success toasts are handled once here, so a
 * feature only describes *what* it is fetching, never the plumbing.
 */
export function createResourceHooks<TModel, TPayload = Record<string, unknown>>(
    api: ResourceApi<TModel, TPayload>,
    labels: { singular: string; plural: string },
) {
    const keys = {
        all: [api.endpoint] as const,
        list: (params?: Record<string, unknown>) => [api.endpoint, 'list', params ?? {}] as const,
        table: (params: TableQueryParams) => [api.endpoint, 'table', params] as const,
        detail: (id: Id) => [api.endpoint, 'detail', id] as const,
    };

    function useList(
        params?: Record<string, unknown>,
        options?: Partial<UseQueryOptions<TModel[]>>,
    ) {
        return useQuery({
            queryKey: keys.list(params),
            queryFn: () => api.list(params),
            ...options,
        });
    }

    /**
     * One page of rows, driven by useServerTable.
     *
     * The previous page stays on screen while the next one loads, so paging
     * does not blank the table on every click.
     */
    function useTable(params: TableQueryParams, options?: Partial<UseQueryOptions<Page<TModel>>>) {
        return useQuery({
            queryKey: keys.table(params),
            queryFn: () => api.paginate(params as unknown as Record<string, unknown>),
            placeholderData: keepPreviousData,
            ...options,
        });
    }

    function useDetail(id: Id | undefined, options?: Partial<UseQueryOptions<TModel>>) {
        return useQuery({
            queryKey: keys.detail(id as Id),
            queryFn: () => api.get(id as Id),
            enabled: id !== undefined && !Number.isNaN(id),
            ...options,
        });
    }

    /**
     * @param options  Pass `onUploadProgress` on a form that uploads a file,
     *                 to drive a progress bar. Omit it and nothing changes.
     */
    function useCreate(options?: WriteOptions) {
        const client = useQueryClient();

        return useMutation({
            mutationFn: (payload: TPayload | FormData) => api.create(payload, options),
            onSuccess: () => {
                client.invalidateQueries({ queryKey: keys.all });
                notify.success(`${labels.singular} created`);
            },
        });
    }

    function useUpdate(options?: WriteOptions) {
        const client = useQueryClient();

        return useMutation({
            mutationFn: ({ id, payload }: { id: Id; payload: TPayload | FormData }) =>
                api.update(id, payload, options),
            onSuccess: (_data, variables) => {
                client.invalidateQueries({ queryKey: keys.all });
                client.invalidateQueries({ queryKey: keys.detail(variables.id) });
                notify.success(`${labels.singular} updated`);
            },
        });
    }

    function useRemove() {
        const client = useQueryClient();

        return useMutation({
            mutationFn: (id: Id) => api.remove(id),
            onSuccess: () => {
                client.invalidateQueries({ queryKey: keys.all });
                notify.success(`${labels.singular} deleted`);
            },
        });
    }

    return { keys, useList, useTable, useDetail, useCreate, useUpdate, useRemove };
}
