import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
    /** How many came back — a return offers only what is left. */
    returned_quantity?: number;

    mrp: number;
    unit_price: number;

    discount_percent: number;
    discount_amount: number;

    tax_rate: number;
    taxable_amount: number;
    tax_amount: number;

    line_total: number;
}

/** Money taken against a bill — one row per tender — or handed back on it. */
export interface SalePayment {
    id: number;
    method: string;
    amount: number;
    /** Money going back: on a cancelled bill, or for goods returned. */
    is_refund?: boolean;
    reference: string | null;
    notes?: string | null;
    paid_at: string | null;
}

/** Goods brought back against a bill — a credit note. */
export interface SaleReturn {
    id: number;
    /** GGN/PHR/26-27/00001 — the branch's own return series. */
    return_number: string;
    return_date: string | null;
    reason: string;
    total_amount: number;
    /** Handed back — only what had been paid over the bill's new total. */
    refund_amount: number;
    created_by_name: string | null;
    items: { pharmacy_sale_item_id: number; quantity: number; line_total: number }[];
}

export interface SaleCustomer {
    id: number;
    code: string | null;
    name: string;
    phone: string | null;
    gender: string | null;
    age: number | null;
    city: string | null;
    state: string | null;
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
    /** The patient behind a registered sale — on a single bill only. */
    customer?: SaleCustomer | null;

    prescription_id: number | null;
    doctor_id: number | null;
    doctor_name?: string | null;

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
    /** The value of goods brought back — what the bill is no longer for. */
    returned_amount?: number;
    amount_due: number;
    payment_status: 'paid' | 'partial' | 'unpaid' | 'billed_via_invoice';

    notes: string | null;

    items_count?: number;
    items?: SaleItem[];
    payments?: SalePayment[];
    returns?: SaleReturn[];

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
    // An older visit bill took this sale's money over.
    billed_via_invoice: 'On visit bill',
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

/** Whatever shows this bill — its own view and every list it sits in — reads it again. */
function refreshSale(client: ReturnType<typeof useQueryClient>, sale: Sale) {
    client.invalidateQueries({ queryKey: resourceKey('tenant/pharmacy', 'sale', sale.id) });
    client.invalidateQueries({ queryKey: resourceKey('tenant/pharmacy', 'sales') });
}

/**
 * Take the rest of a bill left owing — at this counter, never at Billing.
 *
 * The key is the dialog's, made when it opens: a double-tap takes the money
 * once.
 */
export function useCollectSalePayment() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async ({
            id,
            tenders,
            key,
        }: {
            id: number;
            tenders: { method: string; amount: number; reference?: string | null }[];
            key: string;
        }): Promise<Sale> => {
            const { data } = await http.post<ApiResponse<Sale>>(
                `/tenant/sales/${id}/payments`,
                { tenders },
                { headers: { 'Idempotency-Key': key }, silent: true },
            );

            return data.data;
        },
        onSuccess: (sale) => refreshSale(client, sale),
    });
}

/**
 * Take goods back against a bill. The server values them as the bill did,
 * puts them back on their batch, and hands back only what was overpaid.
 */
export function useReturnSaleItems() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async ({
            id,
            items,
            reason,
            refundMethod,
            key,
        }: {
            id: number;
            items: { pharmacy_sale_item_id: number; quantity: number }[];
            reason: string;
            refundMethod?: string | null;
            key: string;
        }): Promise<Sale> => {
            const { data } = await http.post<ApiResponse<Sale>>(
                `/tenant/sales/${id}/returns`,
                { items, reason, refund_method: refundMethod ?? null },
                { headers: { 'Idempotency-Key': key }, silent: true },
            );

            return data.data;
        },
        onSuccess: (sale) => refreshSale(client, sale),
    });
}

export { newIdempotencyKey };
