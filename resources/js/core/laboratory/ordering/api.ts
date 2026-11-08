import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { LabOrder } from '../orders/types';

/** A test the doctor can order, as the catalogue offers it. */
export interface Orderable {
    type: 'test';
    id: number;
    code: string;
    name: string;
    short_name: string | null;
    department_name: string | null;
    sample_type_name: string | null;
    container_name: string | null;
    tat_hours: number | null;
    is_outsourced: boolean;
    parameters_count: number;
    price: number;
    tax_percent: number;
    branch_price: boolean;
}

/** What one line sends to the lab. */
export interface OrderLine {
    id?: number;
    test_name: string;
    lab_test_id?: number | null;
    instructions?: string | null;
    /** The line the doctor had only typed in the consultation that this test now is. */
    replaces?: string | null;
}

/** The lab orders already raised at this visit. */
export function useVisitLabOrders(appointmentId: number, enabled: boolean) {
    return useQuery({
        queryKey: ['tenant/lab-orders', 'visit', appointmentId],
        queryFn: async (): Promise<LabOrder[]> => {
            const { data } = await http.get<ApiResponse<{ appointment_id: number; orders: LabOrder[] }>>(
                `/tenant/appointments/${appointmentId}/lab-orders`,
            );

            return data.data.orders;
        },
        enabled,
    });
}

/** The catalogue, searched as the doctor types. */
export function useOrderables(search: string, enabled: boolean) {
    return useQuery({
        queryKey: ['tenant/lab', 'orderables', search],
        queryFn: async (): Promise<Orderable[]> => {
            const { data } = await http.get<ApiResponse<Orderable[]>>('/tenant/lab/orderables', { params: { search } });

            return data.data;
        },
        enabled,
        staleTime: 60 * 1000,
    });
}

/**
 * Send tests to the lab: a new order for the visit, or more tests on the
 * visit's order the lab has not taken up yet.
 */
export function useSendToLab(appointmentId: number) {
    return useMutation({
        mutationFn: async ({ orderId, items }: { orderId?: number; items: OrderLine[] }) => {
            const { data } = orderId
                ? await http.put<ApiResponse<LabOrder>>(`/tenant/lab-orders/${orderId}`, { items }, { silent: true })
                : await http.post<ApiResponse<LabOrder>>(
                      '/tenant/lab-orders',
                      { appointment_id: appointmentId, items },
                      { silent: true },
                  );

            return data;
        },
    });
}

/*
 * What finishing a consultation does to tests not yet sent, per open editor —
 * the same contract the prescription editor has. "Complete consultation" asks
 * the editor to send them first, so a visit never ends with tests the doctor
 * added but the lab never received.
 */
const flushers = new Map<number, () => Promise<boolean>>();

export function registerLabFlush(appointmentId: number, flush: () => Promise<boolean>): () => void {
    flushers.set(appointmentId, flush);

    return () => {
        if (flushers.get(appointmentId) === flush) flushers.delete(appointmentId);
    };
}

/** True when it is safe to finish the visit. */
export async function flushLabOrders(appointmentId: number): Promise<boolean> {
    const flush = flushers.get(appointmentId);

    return flush ? flush() : true;
}
