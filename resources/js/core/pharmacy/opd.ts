import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import { realtimeClient } from '@/shared/realtime/echo';
import type { RealtimeConfig } from '@/core/tenant-auth/api';
import type { ApiResponse } from '@/shared/types/api';

/** One patient the doctor has finished with — the same shape the list and the live event carry. */
export interface OpdPatient {
    appointment_id: number;
    token_no: number | null;
    patient: { id: number | null; name: string | null };
    doctor: { id: number | null; name: string | null };
    completed_at: string | null;
    has_prescription: boolean;
    pharmacy_pending: boolean;
    status: string;
}

export interface OpdHandoff {
    location_id: number;
    /** The channel to listen on — named by the server, never built here. */
    channel: string;
    patients: OpdPatient[];
}

const key = (storeId: number | undefined) => resourceKey('tenant/pharmacy-stores', storeId, 'opd-patients');

/**
 * Today's patients from OPD for one store's counter.
 *
 * Re-fetched every 30 seconds ONLY when there is no live connection — with
 * Reverb running, the list is kept current by the events below and polling
 * would just be load.
 */
export function useOpdPatients(storeId: number | undefined, enabled: boolean, live: boolean) {
    return useQuery({
        queryKey: key(storeId),
        queryFn: async (): Promise<OpdHandoff> => {
            const { data } = await http.get<ApiResponse<OpdHandoff>>(
                `/tenant/pharmacy-stores/${storeId}/opd-patients`,
            );

            return data.data;
        },
        enabled: enabled && storeId !== undefined,
        refetchInterval: live ? false : 30_000,
    });
}

/** Waiting-on-the-pharmacy first, then most recently finished — the server's order, kept. */
function place(rows: OpdPatient[], row: OpdPatient): OpdPatient[] {
    const others = rows.filter((existing) => existing.appointment_id !== row.appointment_id);

    return row.pharmacy_pending
        ? [row, ...others]
        : [...others.filter((existing) => existing.pharmacy_pending), row, ...others.filter((existing) => !existing.pharmacy_pending)];
}

/**
 * Listen for patients finishing with the doctor at this store's branch.
 *
 * A pushed patient is placed straight into the cached list — the event carries
 * exactly the row the endpoint returns — and the counter is told by name. A
 * reopened-and-completed visit replaces its own row rather than appearing
 * twice.
 */
export function useOpdPatientsLive(
    storeId: number | undefined,
    channel: string | undefined,
    realtime: RealtimeConfig,
): void {
    const client = useQueryClient();

    useEffect(() => {
        const echo = realtimeClient(realtime);

        if (!echo || !channel) {
            return;
        }

        // Echo adds `private-` itself; the server names the full channel.
        const name = channel.replace(/^private-/, '');

        echo.private(name).listen('.consultation.completed', (row: OpdPatient) => {
            client.setQueryData<OpdHandoff>(key(storeId), (current) =>
                current ? { ...current, patients: place(current.patients, row) } : current,
            );

            notify.info(`${row.patient.name ?? 'A patient'} — just consulted`, {
                description: row.doctor.name
                    ? `${row.doctor.name}${row.pharmacy_pending ? ' · prescription waiting' : ''}`
                    : undefined,
            });
        });

        return () => echo.leave(name);
    }, [client, storeId, channel, realtime]);
}
