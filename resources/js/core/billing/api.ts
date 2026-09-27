import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';

/** One line of an invoice — a consultation, a service, a pharmacy line, custom. */
export interface InvoiceItem {
    id: number;
    source_type: 'consultation' | 'pharmacy_sale_item' | 'lab_test' | 'procedure' | 'service' | 'custom';
    source_id: number | null;
    billable_service_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    discount_percent: number;
    discount_amount: number;
    tax_percent: number;
    tax_amount: number;
    line_total: number;
}

/** A row against an invoice — payment or refund. */
export interface InvoicePayment {
    id: number;
    method: string;
    amount: number;
    reference: string | null;
    notes: string | null;
    paid_at: string | null;
    is_refund: boolean;
    refunds_payment_id: number | null;
}

export interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;

    location_id: number;

    customer_id: number | null;
    customer_name: string | null;
    walk_in_phone: string | null;
    is_walk_in: boolean;

    appointment_id: number | null;
    consultation_id: number | null;
    doctor_id: number | null;

    status: 'draft' | 'pending' | 'partially_paid' | 'paid' | 'cancelled' | 'refunded';
    payment_status: 'unpaid' | 'partial' | 'paid';
    trigger: string;

    /**
     * What kind of bill this is.
     *
     * `visit` is the consolidated one — consultation, lab, procedures and
     * medicines for a single attendance, on one document. `registration` is
     * taken at the desk before there is a visit; `manual` is the exception
     * hatch for charges the workflow did not produce.
     */
    kind: 'visit' | 'registration' | 'manual';

    /** Still collecting charges — cannot be paid until finalized. */
    is_draft: boolean;
    finalized_at: string | null;

    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    round_off: number;
    total_amount: number;
    paid_amount: number;
    outstanding: number;

    notes: string | null;
    terms: string | null;

    items?: InvoiceItem[];
    payments?: InvoicePayment[];

    created_by_name: string | null;
    cancelled_at: string | null;
    cancellation_reason?: string | null;
    created_at: string | null;
}

export const INVOICE_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    pending: 'Pending',
    partially_paid: 'Part paid',
    paid: 'Paid',
    cancelled: 'Cancelled',
    refunded: 'Refunded',
};

export const PAYMENT_STATUS_LABELS: Record<string, string> = {
    paid: 'Paid',
    partial: 'Part paid',
    unpaid: 'Unpaid',
};

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    upi: 'UPI',
    bank_transfer: 'Bank transfer',
    online: 'Online',
    cheque: 'Cheque',
    other: 'Other',
};

type Params = object;

export function useInvoices(params: Params) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'invoices', params),
        queryFn: async (): Promise<Page<Invoice>> => {
            const { data } = await http.get<Page<Invoice>>('/tenant/invoices', { params });

            return data;
        },
    });
}

export function useInvoice(id: number | undefined) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'invoice', id),
        queryFn: async (): Promise<Invoice> => {
            const { data } = await http.get<ApiResponse<Invoice>>(`/tenant/invoices/${id}`);

            return data.data;
        },
        enabled: id !== undefined,
    });
}

export interface InvoiceLineInput {
    source_type: InvoiceItem['source_type'];
    source_id?: number | null;
    billable_service_id?: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    discount_percent?: number;
    tax_percent?: number;
}

export interface InvoiceInput {
    location_id: number;
    customer_id?: number | null;
    walk_in_name?: string | null;
    walk_in_phone?: string | null;
    appointment_id?: number | null;
    consultation_id?: number | null;
    doctor_id?: number | null;
    notes?: string | null;
    items: InvoiceLineInput[];
}

/** Draw a manual invoice — an automatic one is drawn by the trigger. */
export function useCreateInvoice() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: InvoiceInput): Promise<Invoice> => {
            const { data } = await http.post<ApiResponse<Invoice>>('/tenant/invoices', payload, {
                silent: true,
            });

            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

export function useUpdateInvoice() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, payload }: { id: number; payload: InvoiceInput }): Promise<Invoice> => {
            const { data } = await http.put<ApiResponse<Invoice>>(`/tenant/invoices/${id}`, payload, {
                silent: true,
            });

            return data.data;
        },
        onSuccess: (invoice) => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoice', invoice.id) });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

export function useCancelInvoice() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, reason }: { id: number; reason: string }): Promise<Invoice> => {
            const { data } = await http.post<ApiResponse<Invoice>>(
                `/tenant/invoices/${id}/cancel`,
                { reason },
                { silent: true },
            );

            return data.data;
        },
        onSuccess: (invoice) => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoice', invoice.id) });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

/**
 * Gather a visit's charges and close the bill in one step.
 *
 * For a `manual`-trigger clinic, and for the visit whose automatic trigger
 * never fired. The server collects consultation, lab and pharmacy lines
 * itself — the client never assembles them.
 */
export function useBillVisit() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (appointmentId: number): Promise<Invoice> => {
            const { data } = await http.post<ApiResponse<Invoice>>(
                '/tenant/invoices/bill-visit',
                { appointment_id: appointmentId },
                { silent: true },
            );

            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

/**
 * Declare a draft complete — it may now be paid.
 *
 * Sweeps in anything that landed since the draft was last touched (a
 * dispensing while the patient walked to the counter) before fixing the
 * total.
 */
export function useFinalizeInvoice() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number): Promise<Invoice> => {
            const { data } = await http.post<ApiResponse<Invoice>>(
                `/tenant/invoices/${id}/finalize`,
                {},
                { silent: true },
            );

            return data.data;
        },
        onSuccess: (invoice) => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoice', invoice.id) });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

export interface PaymentInput {
    method: string;
    amount: number;
    reference?: string | null;
    notes?: string | null;
}

export function useRecordPayment() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ invoiceId, payload }: { invoiceId: number; payload: PaymentInput }): Promise<Invoice> => {
            const { data } = await http.post<ApiResponse<Invoice>>(
                `/tenant/invoices/${invoiceId}/payments`,
                payload,
                { silent: true },
            );

            return data.data;
        },
        onSuccess: (invoice) => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoice', invoice.id) });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

export function useRefundPayment() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            invoiceId,
            paymentId,
            amount,
            reason,
        }: {
            invoiceId: number;
            paymentId: number;
            amount: number;
            reason?: string | null;
        }): Promise<Invoice> => {
            const { data } = await http.post<ApiResponse<Invoice>>(
                `/tenant/invoices/${invoiceId}/payments/${paymentId}/refund`,
                { amount, reason },
                { silent: true },
            );

            return data.data;
        },
        onSuccess: (invoice) => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoice', invoice.id) });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'invoices') });
        },
    });
}

/* ---- Overview ------------------------------------------------------------ */

export interface BillingOverview {
    totals: {
        invoiced: number;
        invoiced_count: number;
        paid: number;
        paid_count: number;
        outstanding: number;
        outstanding_count: number;
        today_count: number;
        today_amount: number;
    };
    /** The same window, one window earlier — for the deltas on each card. */
    previous: { invoiced: number; paid: number; count: number };
    /** `unit` is day, week or month — the server buckets to fit the window. */
    trend: {
        date: string;
        unit: 'day' | 'week' | 'month';
        invoiced: number;
        paid: number;
        outstanding: number;
    }[];
    statuses: {
        paid: number;
        partially_paid: number;
        pending: number;
        cancelled: number;
    };
    /** Where the money came from — grouped by the LINE's source, not the bill's. */
    categories: { key: string; label: string; amount: number; invoices: number }[];
    methods: { method: string; amount: number; share: number }[];
    /** Open debt, bucketed by how long it has been open. */
    ageing: { key: string; label: string; amount: number; invoices: number }[];
    /** Visits still collecting charges — not money, but what the desk watches. */
    open_visits: number;
    recent: Invoice[];
    from: string;
    to: string;
}

export function useBillingOverview(params?: {
    from?: string;
    to?: string;
    location_id?: number;
}) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'overview', params),
        queryFn: async (): Promise<BillingOverview> => {
            const { data } = await http.get<ApiResponse<BillingOverview>>(
                '/tenant/billing/overview',
                { params },
            );

            return data.data;
        },
    });
}

/* ---- Payments register --------------------------------------------------- */

export interface PaymentRow {
    id: number;
    invoice_id: number;
    invoice_number: string | null;
    patient_name: string | null;
    amount: number;
    method: string;
    reference: string | null;
    notes: string | null;
    paid_at: string | null;
    is_refund: boolean;
}

export function usePayments(params: Params) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'payments', params),
        queryFn: async (): Promise<Page<PaymentRow>> => {
            const { data } = await http.get<Page<PaymentRow>>('/tenant/billing/payments', {
                params,
            });

            return data;
        },
    });
}

/* ---- Settings ------------------------------------------------------------ */

export interface BillingSettings {
    default_trigger: string;
    payment_methods: string[];
    payment_behaviour: 'full' | 'partial' | 'credit';
    prices_include_tax: boolean;
    default_tax_percent: number;
    allow_edit_before_payment: boolean;
    invoice_prefix: string;
    currency_code: string;
    currency_symbol: string;
    terms: string | null;
    footer: string | null;
    triggers: string[];
    payment_behaviours: string[];
}

export function useBillingSettings() {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'settings'),
        queryFn: async (): Promise<BillingSettings> => {
            const { data } = await http.get<ApiResponse<BillingSettings>>('/tenant/billing/settings');

            return data.data;
        },
    });
}

export function useSaveBillingSettings() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: Omit<BillingSettings, 'triggers' | 'payment_behaviours'>): Promise<void> => {
            await http.put('/tenant/billing/settings', payload, { silent: true });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'settings') });
        },
    });
}

/* ---- Billable services --------------------------------------------------- */

export interface BillableService {
    id: number;
    location_id: number | null;
    name: string;
    code: string | null;
    /**
     * When the charge lands.
     *
     * `registration` is billed once at the desk on its own invoice;
     * `consultation` and `procedure` join every visit's bill automatically;
     * `service` and `custom` are picked by hand on a manual bill.
     */
    kind: 'registration' | 'consultation' | 'procedure' | 'service' | 'custom';
    default_price: number;
    tax_percent: number;
    active: boolean;
    position: number;
}

export function useBillableServices(params?: { location_id?: number; active_only?: boolean }) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'services', params),
        queryFn: async (): Promise<BillableService[]> => {
            const { data } = await http.get<ApiResponse<BillableService[]>>('/tenant/billable-services', {
                params,
            });

            return data.data;
        },
    });
}

export type BillableServiceInput = Omit<BillableService, 'id'>;

export function useSaveBillableService() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            id,
            payload,
        }: {
            id?: number;
            payload: BillableServiceInput;
        }): Promise<void> => {
            if (id) {
                await http.put(`/tenant/billable-services/${id}`, payload, { silent: true });
            } else {
                await http.post('/tenant/billable-services', payload, { silent: true });
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'services') });
        },
    });
}

export function useDeleteBillableService() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await http.delete(`/tenant/billable-services/${id}`, { silent: true });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'services') });
        },
    });
}
