import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { resourceKey } from '@/shared/hooks/useResource';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';
import type { Medicine } from '@/core/medicines/types';
import type { Availability, Prescription, VisitPrescription } from './types';

const ENDPOINT = 'tenant/prescriptions';

/**
 * The visit's live prescription, the store it would be dispensed from, and
 * what that store holds of each line.
 */
export function useVisitPrescription(appointmentId: number, enabled = true) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'visit', appointmentId),
        queryFn: async (): Promise<VisitPrescription> => {
            const { data } = await http.get<ApiResponse<VisitPrescription>>(
                `/tenant/appointments/${appointmentId}/prescription`,
            );

            return data.data;
        },
        enabled,
    });
}

/** Active catalogue medicines matching what the doctor has typed. */
export function useMedicineSearch(term: string, enabled: boolean) {
    return useQuery({
        queryKey: resourceKey('tenant/medicines', 'prescribing', term),
        queryFn: async (): Promise<Medicine[]> => {
            const { data } = await http.get<Page<Medicine>>('/tenant/medicines', {
                params: { search: term, status: 'active', per_page: 12 },
            });

            return data.data;
        },
        enabled: enabled && term.length >= 2,
        staleTime: 60_000,
    });
}

/**
 * What one store holds of these medicines.
 *
 * Asked under `medicines.view`, the doctor's capability — not the stock
 * screens' `pharmacy.view`.
 */
export function useStoreAvailability(storeId: number | null, medicineIds: number[]) {
    const ids = [...new Set(medicineIds)].sort((a, b) => a - b);

    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'availability', storeId, ids),
        queryFn: async (): Promise<Availability[]> => {
            const { data } = await http.get<ApiResponse<Availability[]>>(
                `/tenant/pharmacy-stores/${storeId}/availability`,
                { params: { medicine_ids: ids } },
            );

            return data.data;
        },
        enabled: storeId !== null && ids.length > 0,
        staleTime: 30_000,
    });
}

/*
 * Writing. Silent: a refusal belongs beside the line that caused it — "That
 * medicine is inactive" under the medicine — not in a toast in the corner.
 * Invalidation is the query client's job, for every mutation.
 */

export function useCreatePrescription() {
    return useMutation({
        mutationFn: async (payload: object) => {
            const { data } = await http.post<ApiResponse<Prescription>>('/tenant/prescriptions', payload, {
                silent: true,
            });

            return data.data;
        },
    });
}

export function useUpdatePrescription() {
    return useMutation({
        mutationFn: async ({ id, payload }: { id: number; payload: object }) => {
            const { data } = await http.put<ApiResponse<Prescription>>(`/tenant/prescriptions/${id}`, payload, {
                silent: true,
            });

            return data.data;
        },
    });
}

export function useIssuePrescription() {
    return useMutation({
        mutationFn: async (id: number) => {
            const { data } = await http.post<ApiResponse<Prescription>>(
                `/tenant/prescriptions/${id}/issue`,
                {},
                { silent: true },
            );

            return data.data;
        },
        onSuccess: (prescription) => notify.success(`${prescription.prescription_number} issued`),
    });
}
