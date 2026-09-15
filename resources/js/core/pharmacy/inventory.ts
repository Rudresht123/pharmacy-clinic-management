import { useMutation, useQuery } from '@tanstack/react-query';
import { createResourceApi, type Page } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse, Timestamps } from '@/shared/types/api';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

export interface Supplier extends Timestamps {
    id: number;
    name: string;
    code: string | null;
    gstin: string | null;
    drug_license_no: string | null;
    drug_license_expiry_date: string | null;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    is_active: boolean;
    deleted_at: string | null;
}

/** One lot of one medicine in one store. Quantities in base units, prices per unit. */
export interface MedicineBatch {
    id: number;
    pharmacy_store_id: number;
    store_name?: string | null;
    medicine_id: number;
    medicine?: { display_name: string; base_unit: string; pack_size: number };
    supplier_id: number | null;
    supplier_name?: string | null;
    batch_number: string;
    expiry_date: string;
    manufacture_date: string | null;
    /** Negative once it has passed, on the clinic's calendar. */
    days_to_expiry: number | null;
    is_past_expiry: boolean;
    /** Stock may leave it: active and not past expiry. */
    is_usable: boolean;
    purchase_price: string;
    selling_price: string;
    mrp: string;
    quantity_received: number;
    quantity_available: number;
    damaged_quantity: number;
    returned_quantity: number;
    status: BatchStatus;
    blocked_reason: string | null;
    blocked_at: string | null;
    received_date: string | null;
}

export type BatchStatus = 'active' | 'blocked' | 'recalled' | 'exhausted' | 'expired';

/** Stock per medicine at one store. */
export interface StockRow {
    medicine_id: number;
    medicine_name: string | null;
    base_unit: string | null;
    on_hand: number;
    /** What can actually be dispensed: active batches not past expiry. */
    usable: number;
    next_expiry: string | null;
    reorder_level: number | null;
    is_low: boolean;
}

export interface StockMovement {
    id: number;
    movement_type: string;
    quantity: number;
    quantity_before: number;
    quantity_after: number;
    unit_cost: string | null;
    medicine_id: number;
    medicine_name?: string;
    medicine_batch_id: number;
    batch_number?: string | null;
    reference_type: string | null;
    reference_id: number | null;
    reverses_movement_id: number | null;
    reason: string | null;
    notes: string | null;
    performed_by_name: string | null;
    movement_date: string | null;
}

export interface StockInwardItem {
    id: number;
    medicine_id: number;
    medicine_name: string | null;
    medicine_batch_id: number;
    batch_number: string;
    expiry_date: string;
    manufacture_date: string | null;
    pack_size: number;
    quantity: number;
    free_quantity: number;
    purchase_price: string;
    selling_price: string;
    mrp: string;
    line_total: string;
}

export interface StockInward {
    id: number;
    inward_number: string;
    pharmacy_store_id: number;
    store_name?: string | null;
    supplier_id: number | null;
    supplier_name?: string | null;
    inward_type: 'purchase' | 'opening_balance' | 'return_from_patient';
    supplier_invoice_no: string | null;
    supplier_invoice_date: string | null;
    received_date: string;
    total_amount: string;
    notes: string | null;
    status: 'posted' | 'cancelled';
    created_by_name: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    items_count?: number;
    items?: StockInwardItem[];
    created_at: string | null;
}

/** One line as the goods received endpoint takes it. */
export interface InwardLineInput {
    medicine_id: number;
    batch_number: string;
    expiry_date: string;
    manufacture_date?: string | null;
    in_packs: boolean;
    quantity: number;
    free_quantity: number;
    purchase_price: number;
    mrp: number;
    selling_price?: number | null;
}

export const MOVEMENT_LABELS: Record<string, string> = {
    opening_balance: 'Opening balance',
    purchase: 'Purchase',
    stock_inward: 'Stock in',
    transfer_in: 'Transfer in',
    transfer_out: 'Transfer out',
    dispensing: 'Dispensed',
    dispensing_reversal: 'Dispensing reversed',
    return_from_patient: 'Returned by patient',
    supplier_return: 'Returned to supplier',
    damage: 'Damaged',
    expiry_writeoff: 'Expired, written off',
    adjustment_increase: 'Adjusted up',
    adjustment_decrease: 'Adjusted down',
    correction: 'Correction',
};

export const INWARD_TYPE_LABELS: Record<string, string> = {
    purchase: 'Purchase',
    opening_balance: 'Opening balance',
    return_from_patient: 'Returned by patient',
};

export const REASON_LABELS: Record<string, string> = {
    damage: 'Damaged',
    expiry_writeoff: 'Expired — write off',
    count_correction: 'Count correction',
    loss: 'Lost or missing',
    other: 'Other',
};

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

/**
 * A key for one attempt at a stock-changing action.
 *
 * Kept for as long as the form is open, so a double-tap or a retry sends the
 * same key and the server answers with the first result instead of moving
 * stock twice. `crypto.randomUUID` exists only on secure origins — a phone
 * on the clinic's LAN over plain http has no such thing — so there is a
 * fallback that is unique enough for one organization's requests.
 */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return [Date.now().toString(36), Math.random().toString(36).slice(2), Math.random().toString(36).slice(2)].join('-');
}

const keyed = (key: string) => ({ headers: { 'Idempotency-Key': key }, silent: true });

/*
|--------------------------------------------------------------------------
| Reads
|--------------------------------------------------------------------------
*/

// `object`, not Record<string, unknown>: the table's own params type has no
// index signature, and these are only ever handed to axios as a query.
type Params = object;

function usePage<T>(parts: unknown[], url: string, params: Params, enabled: boolean) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', ...parts, params),
        queryFn: async (): Promise<Page<T>> => {
            const { data } = await http.get<Page<T>>(url, { params });

            return data;
        },
        enabled,
    });
}

export function useStock(storeId: number | undefined, params: Params, enabled = true) {
    return usePage<StockRow>(
        ['stock', storeId],
        `/tenant/pharmacy-stores/${storeId}/stock`,
        params,
        enabled && storeId !== undefined,
    );
}

export function useBatches(storeId: number | undefined, params: Params, enabled = true) {
    return usePage<MedicineBatch>(
        ['batches', storeId],
        `/tenant/pharmacy-stores/${storeId}/batches`,
        params,
        enabled && storeId !== undefined,
    );
}

export function useMovements(storeId: number | undefined, params: Params, enabled = true) {
    return usePage<StockMovement>(
        ['movements', storeId],
        `/tenant/pharmacy-stores/${storeId}/movements`,
        params,
        enabled && storeId !== undefined,
    );
}

export function useInwards(storeId: number | undefined, params: Params, enabled = true) {
    return usePage<StockInward>(
        ['inwards', storeId],
        `/tenant/pharmacy-stores/${storeId}/inwards`,
        params,
        enabled && storeId !== undefined,
    );
}

export function useInward(id: number | undefined) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'inward', id),
        queryFn: async (): Promise<StockInward> => {
            const { data } = await http.get<ApiResponse<StockInward>>(`/tenant/inwards/${id}`);

            return data.data;
        },
        enabled: id !== undefined,
    });
}

export const suppliersApi = createResourceApi<Supplier>('tenant/suppliers');

export const suppliersHooks = createResourceHooks(suppliersApi, {
    singular: 'Supplier',
    plural: 'Suppliers',
});

/*
|--------------------------------------------------------------------------
| Stock-changing actions
|--------------------------------------------------------------------------
|
| Silent: each is asked from a form or a dialog that shows the server's
| answer in place — "Batch B2231 has 4 left" belongs beside the quantity
| that was typed, not in a toast in the corner. Invalidation is the query
| client's job, for every mutation.
*/

export function useReceiveGoods(storeId: number | undefined) {
    return useMutation({
        mutationFn: async ({ payload, key }: { payload: Params; key: string }) => {
            const { data } = await http.post<ApiResponse<StockInward>>(
                `/tenant/pharmacy-stores/${storeId}/inwards`,
                payload,
                keyed(key),
            );

            return data.data;
        },
        onSuccess: (inward) => notify.success(`${inward.inward_number} received`),
    });
}

export function useCancelInward() {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.post(`/tenant/inwards/${id}/cancel`, { reason }, { silent: true }),
        onSuccess: () => notify.success('Receipt cancelled'),
    });
}

export function useAdjustStock(storeId: number | undefined) {
    return useMutation({
        mutationFn: ({ payload, key }: { payload: Params; key: string }) =>
            http.post(`/tenant/pharmacy-stores/${storeId}/adjustments`, payload, keyed(key)),
        onSuccess: () => notify.success('Stock adjusted'),
    });
}

export function useTransferStock() {
    return useMutation({
        mutationFn: ({ payload, key }: { payload: Params; key: string }) =>
            http.post('/tenant/stock-transfers', payload, keyed(key)),
        onSuccess: () => notify.success('Stock transferred'),
    });
}

export function useBatchStatus() {
    return useMutation({
        mutationFn: ({ id, status, reason }: { id: number; status: string; reason: string }) =>
            http.patch(`/tenant/batches/${id}/status`, { status, reason }, { silent: true }),
        onSuccess: () => notify.success('Batch updated'),
    });
}

export function useRemoveBatch() {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/batches/${id}`, { data: { reason }, silent: true }),
        onSuccess: () => notify.success('Batch removed'),
    });
}

export function useRemoveSupplier() {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/suppliers/${id}`, { data: { reason }, silent: true }),
        onSuccess: () => notify.success('Supplier removed'),
    });
}
