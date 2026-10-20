import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { ColumnRules, ExportCatalogue, ExportFormat, ExportParams, ExportPreview } from './types';

/*
 * The Export Center's data layer: what this person may export, a preview of
 * one export with the filters on screen, the file itself, and the column
 * rules behind all of it.
 */

const KEY = 'tenant/exports';

/** Drops the filters left empty, so the URL carries only what narrows. */
function compact(params: ExportParams): ExportParams {
    return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== ''));
}

export function useExportCatalogue() {
    return useQuery({
        queryKey: [KEY, 'catalogue'],
        queryFn: async (): Promise<ExportCatalogue> => {
            const { data } = await http.get<ApiResponse<ExportCatalogue>>('/tenant/exports');

            return data.data;
        },
    });
}

/** How many rows match, and the first ten — refreshed as the filters change. */
export function useExportPreview(dataset: string | null, params: ExportParams) {
    return useQuery({
        queryKey: [KEY, 'preview', dataset, compact(params)],
        queryFn: async (): Promise<ExportPreview> => {
            const { data } = await http.get<ApiResponse<ExportPreview>>(`/tenant/exports/${dataset}/preview`, {
                params: compact(params),
            });

            return data.data;
        },
        enabled: dataset !== null,
        // The table stays on screen while the next filters load.
        placeholderData: keepPreviousData,
    });
}

/**
 * The file. Fetched as a blob through the session — the export is behind the
 * same authorised endpoint as the preview — and named by the server.
 */
export async function downloadExport(dataset: string, params: ExportParams, format: ExportFormat): Promise<void> {
    const response = await http.get<Blob>(`/tenant/exports/${dataset}/download`, {
        params: { ...compact(params), format },
        responseType: 'blob',
    });

    const disposition = String(response.headers['content-disposition'] ?? '');
    const named = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1];
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');

    link.href = url;
    link.download = named ? decodeURIComponent(named) : `${dataset.replace('.', '-')}.${format}`;
    link.click();

    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

export function useColumnRules() {
    return useQuery({
        queryKey: [KEY, 'column-rules'],
        queryFn: async (): Promise<ColumnRules> => {
            const { data } = await http.get<ApiResponse<ColumnRules>>('/tenant/exports/column-rules');

            return data.data;
        },
    });
}

/** One role's rules, whole. Afterwards the catalogue is read again — it is built from them. */
export function useSaveColumnRules() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async ({ role, hidden }: { role: number; hidden: Record<string, string[]> }) => {
            await http.put(`/tenant/exports/column-rules/${role}`, { hidden });
        },
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: [KEY] });
        },
    });
}
