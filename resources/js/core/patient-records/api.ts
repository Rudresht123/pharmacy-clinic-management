import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';
import type { Prescription } from '@/core/prescriptions/types';
import type { Sale } from '@/core/pharmacy/sales';
import type {
    LabOrder,
    PatientRecordSummary,
    PatientRow,
    PatientVisit,
    PrescriptionRow,
    RecordStats,
    TimelineEvent,
} from './types';

/*
 * The data layer for Patient Records.
 *
 * Only the first group is this screen's own endpoint. Everything a module
 * already lists per patient is read from that module's endpoint with the
 * patient's id — invoices and payments through Billing's own hooks, documents
 * through the documents module's panel — so a record here is always the same
 * row the module itself shows, never a copy.
 */

const KEY = 'tenant/patient-records';

export type RegisterFormat = 'xlsx' | 'csv' | 'pdf';

/**
 * The register as a file — the same search and filters as the list on screen.
 *
 * Fetched as a blob through the session rather than opened as a link, because
 * the export is behind the same authorised endpoint as the list; the name
 * comes from the server's Content-Disposition.
 */
export async function downloadRegister(params: Params, format: RegisterFormat): Promise<void> {
    const response = await http.get<Blob>('/tenant/patient-records/export', {
        params: { ...params, format },
        responseType: 'blob',
    });

    const disposition = String(response.headers['content-disposition'] ?? '');
    const named = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1];
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');

    link.href = url;
    link.download = named ? decodeURIComponent(named) : `patients.${format}`;
    link.click();

    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/**
 * Take, replace or remove a patient's photograph.
 *
 * Its own endpoint, as a doctor's is. Afterwards both the register and the
 * open record are fetched again, so the new face shows in both at once.
 */
export function usePatientPhoto(customerId: number) {
    const client = useQueryClient();
    const refresh = () => {
        void client.invalidateQueries({ queryKey: [KEY] });
        void client.invalidateQueries({ queryKey: ['tenant/customers'] });
    };

    const upload = useMutation({
        mutationFn: async (file: File) => {
            const body = new FormData();
            body.append('photo', file);

            await http.post(`/tenant/customers/${customerId}/photo`, body);
        },
        onSuccess: refresh,
    });

    const remove = useMutation({
        mutationFn: async () => {
            await http.delete(`/tenant/customers/${customerId}/photo`);
        },
        onSuccess: refresh,
    });

    return { upload, remove };
}

type Params = Record<string, string | number | undefined>;

export function usePatientList(params: Params) {
    return useQuery({
        queryKey: [KEY, 'list', params],
        queryFn: async (): Promise<Page<PatientRow>> => {
            const { data } = await http.get<Page<PatientRow>>('/tenant/patient-records', { params });

            return data;
        },
        // Paging and searching keep the rows on screen until the next arrive.
        placeholderData: keepPreviousData,
    });
}

export function useRecordStats() {
    return useQuery({
        queryKey: [KEY, 'stats'],
        queryFn: async (): Promise<RecordStats> => {
            const { data } = await http.get<ApiResponse<RecordStats>>('/tenant/patient-records/stats');

            return data.data;
        },
    });
}

export function usePatientRecord(id: number | null) {
    return useQuery({
        queryKey: [KEY, id],
        queryFn: async (): Promise<PatientRecordSummary> => {
            const { data } = await http.get<ApiResponse<PatientRecordSummary>>(`/tenant/patient-records/${id}`);

            return data.data;
        },
        enabled: id !== null,
    });
}

export function usePatientTimeline(id: number, enabled: boolean) {
    return useQuery({
        queryKey: [KEY, id, 'timeline'],
        queryFn: async (): Promise<TimelineEvent[]> => {
            const { data } = await http.get<ApiResponse<TimelineEvent[]>>(
                `/tenant/patient-records/${id}/timeline`,
                { params: { limit: 200 } },
            );

            return data.data;
        },
        enabled,
    });
}

export function usePatientAppointments(id: number, params: Params) {
    return useQuery({
        queryKey: [KEY, id, 'appointments', params],
        queryFn: async (): Promise<Page<PatientVisit>> => {
            const { data } = await http.get<Page<PatientVisit>>(`/tenant/patient-records/${id}/appointments`, {
                params,
            });

            return data;
        },
        placeholderData: keepPreviousData,
    });
}

export function usePatientPharmacy(id: number, params: Params) {
    return useQuery({
        queryKey: [KEY, id, 'pharmacy', params],
        queryFn: async (): Promise<Page<Sale>> => {
            const { data } = await http.get<Page<Sale>>(`/tenant/patient-records/${id}/pharmacy`, { params });

            return data;
        },
        placeholderData: keepPreviousData,
    });
}

/*
|--------------------------------------------------------------------------
| Other modules' own endpoints, asked about one patient
|--------------------------------------------------------------------------
*/

/** GET /tenant/prescriptions?customer_id= — the prescriptions module's own list. */
export function usePatientPrescriptions(customerId: number, params: Params) {
    return useQuery({
        queryKey: ['tenant/prescriptions', 'list', { customer_id: customerId, ...params }],
        queryFn: async (): Promise<Page<PrescriptionRow>> => {
            const { data } = await http.get<Page<PrescriptionRow>>('/tenant/prescriptions', {
                params: { customer_id: customerId, sort: 'prescription_date', direction: 'desc', ...params },
            });

            return data;
        },
        placeholderData: keepPreviousData,
    });
}

/** GET /tenant/prescriptions/{id} — the lines, fetched when a row is opened. */
export function usePrescription(id: number | null) {
    return useQuery({
        queryKey: ['tenant/prescriptions', id],
        queryFn: async (): Promise<Prescription> => {
            const { data } = await http.get<ApiResponse<Prescription>>(`/tenant/prescriptions/${id}`);

            return data.data;
        },
        enabled: id !== null,
    });
}

/** GET /tenant/lab-orders?customer_id= — the laboratory's own worklist, for one patient. */
export function usePatientLabOrders(customerId: number, params: Params) {
    return useQuery({
        queryKey: ['tenant/laboratory', 'orders', { customer_id: customerId, ...params }],
        queryFn: async (): Promise<Page<LabOrder>> => {
            const { data } = await http.get<Page<LabOrder>>('/tenant/lab-orders', {
                params: { customer_id: customerId, sort: 'order_date', direction: 'desc', ...params },
            });

            return data;
        },
        placeholderData: keepPreviousData,
    });
}
