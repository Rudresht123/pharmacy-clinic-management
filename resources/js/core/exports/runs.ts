import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { ExportFormat, ExportParams, ExportRun } from './types';

/*
 * Exports made on the queue: start one, watch it, take the file.
 *
 * A whole register is written by a job on the server while the person keeps
 * working — moving between screens included, because the tray that watches it
 * sits in the app's shell, not on the Export Center page.
 */

const KEY = ['tenant/exports', 'runs'];

/** How often a file being made is checked on. */
const WATCH_EVERY = 1500;

export const isActive = (run: ExportRun) => run.status === 'queued' || run.status === 'running';

/** Drops the filters left empty, so the run keeps only what narrows. */
function compact(params: ExportParams): ExportParams {
    return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== ''));
}

/**
 * Runs started from this browser tab, to download by themselves when ready.
 * Kept for the tab's life (a refresh included), so a file finished after a
 * reload still arrives — and one already taken is never downloaded twice.
 */
const AUTO = 'hms.exports.auto-download';

function autoIds(): number[] {
    try {
        return JSON.parse(sessionStorage.getItem(AUTO) ?? '[]') as number[];
    } catch {
        return [];
    }
}

function saveAuto(ids: number[]) {
    try {
        sessionStorage.setItem(AUTO, JSON.stringify(ids));
    } catch {
        // Private mode: the file still waits in the tray.
    }
}

export const autoDownload = {
    has: (id: number) => autoIds().includes(id),
    add: (id: number) => saveAuto([...new Set([...autoIds(), id])]),
    remove: (id: number) => saveAuto(autoIds().filter((other) => other !== id)),
};

/** This person's runs from the last day — checked often while one is being made, not at all otherwise. */
export function useExportRuns(enabled: boolean) {
    return useQuery({
        queryKey: KEY,
        queryFn: async (): Promise<ExportRun[]> => {
            const { data } = await http.get<ApiResponse<ExportRun[]>>('/tenant/exports/runs');

            return data.data;
        },
        enabled,
        refetchInterval: (query) => ((query.state.data ?? []).some(isActive) ? WATCH_EVERY : false),
        // Keeps watching from a background tab, so the file still arrives when ready.
        refetchIntervalInBackground: true,
    });
}

export function useStartExport() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async ({ dataset, params, format }: { dataset: string; params: ExportParams; format: ExportFormat }) => {
            const { data } = await http.post<ApiResponse<ExportRun>>(`/tenant/exports/${dataset}/runs`, {
                ...compact(params),
                format,
            });

            return data.data;
        },
        onSuccess: (run) => {
            autoDownload.add(run.id);
            // On the tray at once, before the next check.
            client.setQueryData<ExportRun[]>(KEY, (was) => [run, ...(was ?? []).filter((other) => other.id !== run.id)]);
        },
    });
}

export function useCancelRun() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            await http.post(`/tenant/exports/runs/${id}/cancel`);
            autoDownload.remove(id);
        },
        onSuccess: () => void client.invalidateQueries({ queryKey: KEY }),
    });
}

export function useDismissRun() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            await http.delete(`/tenant/exports/runs/${id}`);
            autoDownload.remove(id);
        },
        onMutate: (id) => {
            client.setQueryData<ExportRun[]>(KEY, (was) => (was ?? []).filter((run) => run.id !== id));
        },
        onSettled: () => void client.invalidateQueries({ queryKey: KEY }),
    });
}

/** The finished file, through the session like every export, named as the server named it. */
export async function downloadRun(run: ExportRun): Promise<void> {
    const response = await http.get<Blob>(`/tenant/exports/runs/${run.id}/file`, { responseType: 'blob' });

    const disposition = String(response.headers['content-disposition'] ?? '');
    const named = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1];
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');

    link.href = url;
    link.download = named ? decodeURIComponent(named) : (run.file_name ?? `export.${run.format}`);
    link.click();

    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
