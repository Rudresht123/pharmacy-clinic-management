import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type {
    CatalogueEntry,
    CheckoutResponse,
    QuotaUsage,
    SubscriptionInvoice,
    SubscriptionOverview,
    SubscriptionStatus,
} from './types';

const ROOT = '/tenant/subscription';

/** Everything the subscription page shows. Reachable while locked. */
export function useSubscription() {
    return useQuery({
        queryKey: ['subscription'],
        staleTime: 0,
        queryFn: async () => (await http.get<ApiResponse<SubscriptionOverview>>(ROOT)).data.data,
    });
}

/** What can be bought, at list price. */
export function useSubscriptionCatalogue(enabled = true) {
    return useQuery({
        queryKey: ['subscription', 'catalogue'],
        enabled,
        queryFn: async () => (await http.get<ApiResponse<CatalogueEntry>>(`${ROOT}/catalogue`)).data.data,
    });
}

export function useSubscriptionInvoices() {
    return useQuery({
        queryKey: ['subscription', 'invoices'],
        queryFn: async () => (await http.get<ApiResponse<SubscriptionInvoice[]>>(`${ROOT}/invoices`)).data.data,
    });
}

export interface UsageRecordRow {
    id: number;
    channel: string;
    category: string | null;
    quantity: number;
    unit: string;
    from_quota: number;
    overage: number;
    charge: number;
    status: string;
    occurred_at: string;
}

export function useSubscriptionUsage(page: number, channel?: string) {
    return useQuery({
        queryKey: ['subscription', 'usage', page, channel ?? 'all'],
        queryFn: async () => {
            const { data } = await http.get<
                ApiResponse<{ balances: Record<string, QuotaUsage>; records: UsageRecordRow[] }>
            >(`${ROOT}/usage`, { params: { page, channel } });

            return { ...data.data, meta: data.meta };
        },
    });
}

function useCheckoutMutation<TBody>(path: string) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: TBody) =>
            (await http.post<ApiResponse<CheckoutResponse>>(`${ROOT}/${path}`, body)).data.data,
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['subscription'] });
        },
    });
}

export function useRenew() {
    return useCheckoutMutation<{ plan_id?: number; billing_cycle?: string }>('renew');
}

export function useUpgrade() {
    return useCheckoutMutation<{ plan_id: number; billing_cycle: string }>('upgrade');
}

export function useBuyAddons() {
    return useCheckoutMutation<{ addons: Record<string, number>; modules: string[] }>('addons');
}

export function usePayInvoice() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (uuid: string) =>
            (await http.post<ApiResponse<CheckoutResponse>>(`${ROOT}/invoices/${uuid}/pay`)).data.data,
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['subscription'] }),
    });
}

export async function quoteAddons(body: { addons: Record<string, number>; modules: string[] }) {
    return (await http.post<ApiResponse<{ subtotal: number; tax: number; total: number; lines: CheckoutResponse['invoice']['lines'] }>>(
        `${ROOT}/addons/quote`,
        body,
        { silent: true },
    )).data.data;
}

export async function verifyPayment(body: {
    razorpay_order_id: string;
    razorpay_payment_id: string;
    razorpay_signature: string;
}) {
    return (await http.post<ApiResponse<{ invoice: SubscriptionInvoice; status: SubscriptionStatus }>>(
        `${ROOT}/payments/verify`,
        body,
    )).data;
}
