import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
    /** GGN/RCP/26-27/00001 for a payment, GGN/RFD/… for a refund. */
    receipt_number: string;
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
    /** The registered patient's phone — present when the list loaded the patient. */
    customer_phone?: string | null;
    walk_in_phone: string | null;
    is_walk_in: boolean;

    appointment_id: number | null;
    consultation_id: number | null;
    doctor_id: number | null;
    doctor_name: string | null;

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
    items_count?: number;
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

/** A card at the top of the Invoices screen. `change` is this month against last, in %. */
export interface InvoiceCard {
    value: number;
    this_month?: number;
    invoices?: number;
    change: number | null;
}

export interface InvoiceSummary {
    /** One per tab, following the filters on screen. */
    counts: { outstanding: number; open: number; all: number; paid: number; cancelled: number };
    /** The register as a whole — not narrowed by the filters. */
    cards: { invoices: InvoiceCard; billed: InvoiceCard; outstanding: InvoiceCard; collected: InvoiceCard };
}

export function useInvoiceSummary(params: Params) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'invoices', 'summary', params),
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<InvoiceSummary> => {
            const { data } = await http.get<ApiResponse<InvoiceSummary>>('/tenant/invoices/summary', { params });

            return data.data;
        },
    });
}

/** Saves the list on screen — or only the ticked rows — as a CSV file. */
export async function exportInvoices(params: Params, ids?: number[]): Promise<void> {
    const { data } = await http.get<Blob>('/tenant/invoices/export', {
        params: ids && ids.length > 0 ? { ...params, ids } : params,
        responseType: 'blob',
    });

    const url = URL.createObjectURL(data);
    const link = document.createElement('a');

    link.href = url;
    link.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();

    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
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

export interface CategorySplit {
    consultation: number;
    pharmacy: number;
    laboratory: number;
    procedure: number;
    other: number;
}

export interface OutstandingPatient {
    patient: string;
    customer_id: number | null;
    phone: string | null;
    last_visit: string;
    total: number;
    paid: number;
    due: number;
    days: number;
}

export interface BillingOverview {
    totals: {
        invoiced: number;
        invoiced_count: number;
        paid: number;
        paid_count: number;
        outstanding: number;
        outstanding_count: number;
        /** Distinct patients with an open balance right now. */
        outstanding_patients: number;
        /** Distinct patients billed in this window. */
        billed_patients: number;
        today_count: number;
        today_amount: number;
    };
    /** The same window, one window earlier — for the deltas on each card. */
    previous: { invoiced: number; paid: number; count: number; billed_patients: number };
    /** `unit` is day, week or month — the server buckets to fit the window. */
    trend: {
        date: string;
        unit: 'day' | 'week' | 'month';
        invoiced: number;
        paid: number;
        outstanding: number;
        /** How many bills that bucket held — the count tile's own sparkline. */
        invoices: number;
        /** The same bucket, split by where it came from — the stacked bars. */
        by_category: CategorySplit;
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
    /** Who owes money, most recently seen first. */
    outstanding_patients_list: OutstandingPatient[];
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
        // Switching the range keeps the dashboard up while the new figures load.
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<BillingOverview> => {
            const { data } = await http.get<ApiResponse<BillingOverview>>(
                '/tenant/billing/overview',
                { params },
            );

            return data.data;
        },
    });
}

/**
 * The overview's invoice table — the window's bills, a page at a time.
 *
 * Its own request, so turning a page does not recompute every card and chart.
 * Keyed under `overview`, so whatever refreshes the overview refreshes this.
 * The previous page stays on screen while the next loads, rather than the
 * table collapsing to a spinner between pages.
 */
export function useOverviewInvoices(params: { from: string; to: string; page: number; per_page: number }) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'overview', 'invoices', params),
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<Page<Invoice>> => {
            const { data } = await http.get<Page<Invoice>>('/tenant/billing/overview/invoices', { params });

            return data;
        },
    });
}

/** Everybody who owes money, grouped by patient — not windowed. */
export function useOverviewOutstanding(params: { page: number; per_page: number }) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'overview', 'outstanding', params),
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<Page<OutstandingPatient>> => {
            const { data } = await http.get<Page<OutstandingPatient>>('/tenant/billing/overview/outstanding', {
                params,
            });

            return data;
        },
    });
}

export interface OutstandingSummary {
    total_outstanding: number;
    total_invoices: number;
    unpaid: { count: number; amount: number };
    partial: { count: number; amount: number };
}

/** The Outstanding page's own 4 cards — all-time unless `from`/`to` narrow it. */
export function useOutstandingSummary(params?: { from?: string; to?: string; location_id?: number }) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'overview', 'outstanding', 'summary', params),
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<OutstandingSummary> => {
            const { data } = await http.get<ApiResponse<OutstandingSummary>>(
                '/tenant/billing/overview/outstanding/summary',
                { params },
            );

            return data.data;
        },
    });
}

/* ---- Payments register --------------------------------------------------- */

export interface PaymentRow {
    id: number;
    receipt_number: string;
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

export interface PaymentsSummary {
    total_collected: { value: number; change: number | null };
    transactions: { value: number; change: number | null };
    total_refunds: { value: number; change: number | null };
    methods: { method: string; count: number; amount: number; share: number }[];
}

export function usePaymentsSummary(params?: { from?: string; to?: string; location_id?: number }) {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'payments', 'summary', params),
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<PaymentsSummary> => {
            const { data } = await http.get<ApiResponse<PaymentsSummary>>('/tenant/billing/payments/summary', {
                params,
            });

            return data.data;
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

/* ---- Numbering ----------------------------------------------------------- */

export type NumberedDocument = 'invoice' | 'receipt' | 'refund';

export interface NumberSeries {
    prefix: string;
    /** What the next document in this series will be called, this year. */
    next: string;
    /** Just its running number — 00043 — so a prefix being typed can be previewed. */
    next_sequence: string;
}

export interface BranchNumbering {
    location_id: number;
    name: string;
    code: string;
    series: Record<NumberedDocument, NumberSeries>;
}

export interface BillingNumbering {
    /** 26-27 — every series restarts on 1 April. */
    financial_year: string;
    branches: BranchNumbering[];
}

export interface NumberSeriesInput {
    location_id: number;
    document_type: NumberedDocument;
    prefix: string;
}

export function useBillingNumbering() {
    return useQuery({
        queryKey: resourceKey('tenant/billing', 'numbering'),
        queryFn: async (): Promise<BillingNumbering> => {
            const { data } = await http.get<ApiResponse<BillingNumbering>>('/tenant/billing/numbering');

            return data.data;
        },
    });
}

export function useSaveBillingNumbering() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (series: NumberSeriesInput[]): Promise<void> => {
            await http.put('/tenant/billing/numbering', { series }, { silent: true });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/billing', 'numbering') });
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
