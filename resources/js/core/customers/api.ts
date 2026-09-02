import { useQuery } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { ConfigurableField } from '@/core/field-settings/types';
import type { Customer, CustomerStats } from './types';

export const customersApi = createResourceApi<Customer>('tenant/customers');

export const customersHooks = createResourceHooks(customersApi, {
    singular: 'Customer',
    plural: 'Customers',
});

/**
 * The field definitions the form and table render from — the code registry
 * with this organization's own preferences and extra fields applied.
 */
export function useCustomerFields() {
    return useQuery({
        queryKey: ['tenant', 'customers', 'fields'],
        queryFn: async (): Promise<ConfigurableField[]> => {
            const { data } = await http.get<ApiResponse<ConfigurableField[]>>(
                '/tenant/customers/fields',
            );

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}

/** Totals for the list screen's header, across every page of results. */
export function useCustomerStats() {
    return useQuery({
        queryKey: ['tenant', 'customers', 'stats'],
        queryFn: async (): Promise<CustomerStats> => {
            const { data } = await http.get<ApiResponse<CustomerStats>>('/tenant/customers/stats');

            return data.data;
        },
    });
}
