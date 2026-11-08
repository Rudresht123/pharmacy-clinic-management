import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';
import type { TableQueryParams } from '@/shared/hooks/useServerTable';
import type { LabOrder, OrderCounts, OrderStatus } from './types';

export interface OrderFilters {
    status?: OrderStatus;
    department_id?: number;
    date_from?: string;
    date_to?: string;
    search?: string;
}

export function useLabOrders(params: TableQueryParams & OrderFilters) {
    return useQuery({
        queryKey: ['tenant/lab-orders', 'list', params],
        queryFn: async (): Promise<Page<LabOrder>> => {
            const { data } = await http.get<Page<LabOrder>>('/tenant/lab-orders', { params });

            return data;
        },
        placeholderData: (previous) => previous,
    });
}

/** How many orders sit under each status, with the list's other filters. */
export function useLabOrderCounts(params: Omit<OrderFilters, 'status'>) {
    return useQuery({
        queryKey: ['tenant/lab-orders', 'counts', params],
        queryFn: async (): Promise<OrderCounts> => {
            const { data } = await http.get<ApiResponse<OrderCounts>>('/tenant/lab-orders/counts', { params });

            return data.data;
        },
        placeholderData: (previous) => previous,
    });
}

export function useLabOrder(id: number | undefined) {
    return useQuery({
        queryKey: ['tenant/lab-orders', 'one', id],
        queryFn: async (): Promise<LabOrder> => {
            const { data } = await http.get<ApiResponse<LabOrder>>(`/tenant/lab-orders/${id}`);

            return data.data;
        },
        enabled: id !== undefined && !Number.isNaN(id),
    });
}
