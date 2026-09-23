import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';
import { newIdempotencyKey } from './inventory';

/** One line of a bill, as the bill said it. */
export interface SaleItem {
    id: number;
    medicine_id: number;
    medicine_batch_id: number;
    prescription_item_id: number | null;

    item_name: string;
    hsn_code: string | null;
    batch_number: string;
    expiry_date: string;

    quantity: number;

    mrp: number;
    unit_price: number;

    discount_percent: number;
    discount_amount: number;

    tax_rate: number;
    taxable_amount: number;
    tax_amount: number;

    line_total: number;
}

/** Money taken against a bill — one row per tender. */
export interface SalePayment {
    id: number;
    method: string;
    amount: number;
    reference: string | null;
    paid_at: string | null;
}

/**
 * A bill.
 *
 * The same record whether it was rung up at a counter or dispensed against a
 * prescription — a dispensing carries `prescription_id`.
 */
export interface Sale {
    id: number;
    sale_number: string;

    pharmacy_store_id: number;
    store_name?: string | null;

    customer_id: number | null;
    customer_name: string;
    walk_in_phone: string | null;
    is_walk_in: boolean;

    prescription_id: number | null;
    doctor_id: number | null;

    sale_date: string | null;
    status: 'completed' | 'cancelled';

    price_basis: 'mrp' | 'selling';
    prices_include_tax: boolean;

    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    round_off: number;
    total_amount: number;
    paid_amount: number;
    amount_due: number;
    payment_status: 'paid' | 'partial' | 'unpaid';

    notes: string | null;

    items_count?: number;
    items?: SaleItem[];
    payments?: SalePayment[];

    created_by_name: string | null;

    cancelled_at: string | null;
    cancellation_reason?: string | null;

    created_at: string | null;
}

/** One line as the counter sends it. Leave the batch out to sell first-expiry-first. */
export interface SaleLineInput {
    medicine_id: number;
    medicine_batch_id?: number | null;
    quantity: number;
    unit_price?: number | null;
    discount_percent?: number | null;
}

export interface SaleInput {
    customer_id?: number | null;
    walk_in_name?: string | null;
    walk_in_phone?: string | null;
    prescription_id?: number | null;
    notes?: string | null;
    items: SaleLineInput[];
    payments: { method: string; amount: number; reference?: string | null }[];
}

export const SALE_STATUS_LABELS: Record<string, string> = {
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const PAYMENT_STATUS_LABELS: Record<string, string> = {
    paid: 'Paid',
    partial: 'Part paid',
    unpaid: 'Unpaid',
};

type Params = object;

export function useSales(storeId: number | undefined, params: Params, enabled = true) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'sales', storeId, params),
        queryFn: async (): Promise<Page<Sale>> => {
            const { data } = await http.get<Page<Sale>>(`/tenant/pharmacy-stores/${storeId}/sales`, {
                params,
            });

            return data;
        },
        enabled: enabled && storeId !== undefined,
    });
}

export function useSale(id: number | undefined) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'sale', id),
        queryFn: async (): Promise<Sale> => {
            const { data } = await http.get<ApiResponse<Sale>>(`/tenant/sales/${id}`);

            return data.data;
        },
        enabled: id !== undefined,
    });
}

/**
 * Ring up a sale.
 *
 * Silent: a refusal — not enough stock, an expired batch, a bill left unpaid
 * where credit is off — is shown at the counter beside what was typed, not
 * as a toast in the corner. The key is the caller's, kept for as long as the
 * cart is open, so a double-tap bills once.
 */
export function useCreateSale(storeId: number | undefined) {
    return useMutation({
        mutationFn: async ({ payload, key }: { payload: SaleInput; key: string }): Promise<Sale> => {
            const { data } = await http.post<ApiResponse<Sale>>(
                `/tenant/pharmacy-stores/${storeId}/sales`,
                payload,
                { headers: { 'Idempotency-Key': key }, silent: true },
            );

            return data.data;
        },
    });
}

export function useCancelSale() {
    return useMutation({
        mutationFn: async ({ id, reason }: { id: number; reason: string }): Promise<Sale> => {
            const { data } = await http.post<ApiResponse<Sale>>(
                `/tenant/sales/${id}/cancel`,
                { reason },
                { silent: true },
            );

            return data.data;
        },
    });
}

export { newIdempotencyKey };
