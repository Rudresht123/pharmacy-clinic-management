import {
    keepPreviousData,
    useMutation,
    useQuery,
    type UseQueryOptions,
} from '@tanstack/react-query';
import { notify } from '@/shared/utils/notify';
import type { Page, ResourceApi, WriteOptions } from '@/shared/api/resource';
import type { TableQueryParams } from './useServerTable';
import type { Id } from '@/shared/types/api';

/**
 * A cache key inside one resource's namespace.
 *
 * Anything hand-written that belongs to a resource — its field schema, its
 * totals — must be keyed with this rather than an array of its own, or
 * `keys.all` will not prefix-match it and writing a record will silently
 * leave it stale. That exact mismatch (`['tenant/locations']` never matching
 * `['tenant','locations','fields']`) is why adding a branch used to need a
 * hard refresh before the new one appeared anywhere else.
 */
export function resourceKey(endpoint: string, ...parts: readonly unknown[]) {
    return [endpoint, ...parts] as const;
}

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

    /*
     * None of these invalidate anything themselves. The query client does it
     * for every successful mutation in the application — see
     * shared/api/queryClient.ts for why naming affected keys turned out to be
     * a promise this codebase could not keep.
     */

    function useCreate(writeOptions?: WriteOptions) {
        return useMutation({
            mutationFn: (payload: TPayload | FormData) => api.create(payload, writeOptions),
            onSuccess: () => notify.success(`${labels.singular} created`),
        });
    }

    function useUpdate(writeOptions?: WriteOptions) {
        return useMutation({
            mutationFn: ({ id, payload }: { id: Id; payload: TPayload | FormData }) =>
                api.update(id, payload, writeOptions),
            onSuccess: () => notify.success(`${labels.singular} updated`),
        });
    }

    function useRemove() {
        return useMutation({
            mutationFn: (id: Id) => api.remove(id),
            onSuccess: () => notify.success(`${labels.singular} deleted`),
        });
    }

    return { keys, useList, useTable, useDetail, useCreate, useUpdate, useRemove };
}
