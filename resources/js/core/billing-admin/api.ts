import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiMeta, ApiResponse } from '@/shared/types/api';
import type { QuotaUsage, ResourceUsage, SubscriptionInvoice, SubscriptionStatus } from '@/core/subscription/types';

const ROOT = '/admin/billing';

/* --- types ---------------------------------------------------------------- */

export type CatalogStatus = 'draft' | 'published' | 'unpublished' | 'archived';

export interface Plan {
    id: number;
    uuid: string;
    slug: string;
    name: string;
    description: string | null;
    status: CatalogStatus;
    is_public: boolean;
    is_system: boolean;
    sort_order: number;
    currency: string;
    monthly_price: number;
    annual_price: number;
    trial_days: number;
    grace_days: number;
    features: string[];
    highlights: string[];
    limits: Record<string, number | null>;
    modules: string[];
    subscribers?: number;
}

export interface PlanInput {
    name: string;
    slug?: string;
    description?: string | null;
    is_public: boolean;
    sort_order: number;
    monthly_price: number;
    annual_price: number;
    trial_days: number;
    grace_days: number;
    features: string[];
    highlights: string[];
    limits: Record<string, number | null>;
    modules: string[];
}

export interface ModulePriceRow {
    module: { id: number; key: string; name: string; description: string | null; is_active: boolean };
    current: ModulePrice | null;
    scheduled: ModulePrice[];
    history: ModulePrice[];
    included_in: Array<{ id: number; name: string }>;
}

export interface ModulePrice {
    id: number;
    monthly_price: number;
    annual_price: number;
    provider_cost: number | null;
    is_addon_eligible: boolean;
    trial_available: boolean;
    is_public: boolean;
    effective_from: string;
    effective_to: string | null;
}

export interface Addon {
    id: number;
    key: string;
    name: string;
    description: string | null;
    kind: 'resource' | 'credits' | 'feature';
    target: string;
    target_label: string;
    unit_quantity: number;
    billing: 'recurring' | 'one_time';
    currency: string;
    monthly_price: number;
    annual_price: number;
    provider_cost: number | null;
    status: CatalogStatus;
    is_public: boolean;
    sort_order: number;
}

export interface UsageRate {
    id: number;
    channel: 'whatsapp' | 'sms' | 'email' | 'video';
    provider: string;
    category: string | null;
    destination: string;
    billing_unit: string;
    currency: string;
    provider_cost_e4: number;
    price_e4: number;
    margin_e4: number;
    overage_policy: 'block' | 'allow';
    charge_failed: boolean;
    tiers: Array<{ from: number; price_e4: number }>;
    status: 'draft' | 'published' | 'archived';
    notes: string | null;
    effective_from: string;
    effective_to: string | null;
}

export interface AdminSubscription {
    uuid: string;
    organization?: { uuid: string; name: string; code: string; status: string; email: string };
    plan: { id: number; name: string | null; slug: string | null };
    status: string;
    effective_status: string;
    locked: boolean;
    lock_reason: string | null;
    billing_cycle: string;
    price: number;
    currency: string;
    payment_status: string;
    started_at: string | null;
    trial_ends_at: string | null;
    expires_at: string | null;
    grace_ends_at: string | null;
    next_renewal_at: string | null;
    cancelled_at: string | null;
    suspended_at: string | null;
    suspension_reason: string | null;
    days_remaining: number | null;
    pending_plan: string | null;
}

export interface SubscriptionEvent {
    id: number;
    organization?: string | null;
    type: string;
    from_status: string | null;
    to_status: string | null;
    actor_type: string;
    actor_name: string | null;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    occurred_at: string;
}

export interface Kpi {
    value: number;
    change: number | null;
    count?: number;
    amount?: number;
}

export interface Slice {
    label: string;
    value: number;
}

export interface SeriesPoint {
    key: string;
    label: string;
    title: string;
    values: number[];
}

export interface InvoiceRow {
    uuid: string;
    number: string | null;
    organization: { uuid: string; name: string } | null;
    bill_to: string | null;
    total: number;
    currency: string;
    issued_at: string | null;
    due_at: string | null;
    status: string;
}

export interface Overview {
    range: { from: string; to: string };
    kpis: {
        total_revenue: Kpi;
        active_subscriptions: Kpi;
        total_invoices: Kpi;
        pending_payments: Kpi;
        overdue_invoices: Kpi;
    };
    revenue_trend: SeriesPoint[];
    revenue_by_plan: Slice[];
    subscription_status: Record<string, number>;
    recent_invoices: InvoiceRow[];
    attention: { locked: number; without_subscription: number; failed_reminders: number };
    recent_events: SubscriptionEvent[];
    currency: string;
}

export interface SubscriptionInsights {
    tiles: {
        active: number;
        active_change: number | null;
        expiring_soon: number;
        suspended: number;
        cancelled: number;
        trial: number;
        grace: number;
        expired: number;
    };
    growth: Array<{ key: string; label: string; title: string; value: number }>;
    plan_distribution: Slice[];
}

export interface PlansSummary {
    total_plans: number;
    active_subscriptions: number;
    monthly_revenue: number;
    average_plan_value: number;
    subscribers: Record<string, number>;
    most_popular_plan_id: number | null;
}

export interface PricingInsights {
    trend: SeriesPoint[];
    revenue_by_module: Slice[];
}

export interface InvoiceInsights {
    tiles: { total_invoices: number; paid_amount: number; pending_amount: number; overdue_amount: number };
    status: Slice[];
    trend: SeriesPoint[];
    payment_methods: Slice[];
}

export interface OrganizationSubscription {
    organization: { uuid: string; name: string; status: string; suspension_reason: string | null; timezone: string };
    subscription: AdminSubscription | null;
    status: SubscriptionStatus;
    items: Array<{
        id: number;
        type: string;
        ref: string;
        target: string | null;
        target_label: string | null;
        quantity: number;
        grants: number | null;
        unit_price: number;
        billing: string;
        status: string;
        is_live: boolean;
        starts_at: string | null;
        ends_at: string | null;
        note: string | null;
    }>;
    resources: Record<string, ResourceUsage> | null;
    usage: Record<string, QuotaUsage>;
    modules: Array<{ key: string; name: string; source: string; is_live: boolean; is_core: boolean }>;
    events: SubscriptionEvent[];
    history: AdminSubscription[];
    invoices: Array<Pick<SubscriptionInvoice, 'uuid' | 'number' | 'kind' | 'status' | 'total' | 'currency' | 'issued_at' | 'due_at' | 'paid_at'>>;
    reminders: Array<{ kind: string; channel: string; status: string; error: string | null; sent_at: string | null; created_at: string }>;
}

export interface AdminInvoice extends SubscriptionInvoice {
    display_status: 'paid' | 'pending' | 'overdue' | 'void' | 'draft';
    payment_mode: string | null;
    organization: { uuid: string; name: string } | null;
    bill_to: Record<string, string | null> | null;
    voided_at: string | null;
    void_reason: string | null;
    fulfilled_at: string | null;
    payments: Array<{
        uuid: string;
        method: string;
        status: string;
        amount: number;
        gateway_order_id: string | null;
        gateway_payment_id: string | null;
        reference: string | null;
        verified_at: string | null;
        verified_via: string | null;
        failure_reason: string | null;
    }>;
}

export interface BillingSettings {
    settings: {
        tax_mode: 'exclusive' | 'inclusive' | 'none';
        tax_rate_bp: number;
        tax_label: string;
        grace_access: 'full' | 'read_only';
        reminder_days: number[];
        grace_reminder_days: number[];
        reminder_channels: string[];
        renewal_invoice_days: number;
        warning_days: number;
        gateways: string[];
        default_trial_plan_id: number | null;
        invoice_prefix: string;
        fiscal_year_start: number;
        timezone: string;
        seller: { name: string | null; gstin: string | null; address: string | null; email: string | null; phone: string | null };
    };
    secrets: Record<string, boolean>;
    webhook_url: string;
    next_invoice_number: string;
    online_ready: boolean;
}

/* --- queries -------------------------------------------------------------- */

function get<T>(path: string, params?: Record<string, unknown>) {
    return async () => (await http.get<ApiResponse<T>>(`${ROOT}${path}`, { params })).data;
}

export const useOverview = (range: { from: string; to: string }) =>
    useQuery({
        queryKey: ['billing', 'overview', range],
        placeholderData: (previous) => previous,
        queryFn: async () => (await get<Overview>('/overview', range)()).data,
    });
export const useSubscriptionInsights = () =>
    useQuery({ queryKey: ['billing', 'subscriptions', 'insights'], queryFn: async () => (await get<SubscriptionInsights>('/subscriptions/insights')()).data });
export const usePlansSummary = () => useQuery({ queryKey: ['billing', 'plans', 'summary'], queryFn: async () => (await get<PlansSummary>('/plans/summary')()).data });
export const usePricingInsights = () => useQuery({ queryKey: ['billing', 'pricing', 'insights'], queryFn: async () => (await get<PricingInsights>('/pricing/insights')()).data });
export const useInvoiceInsights = () => useQuery({ queryKey: ['billing', 'invoices', 'insights'], queryFn: async () => (await get<InvoiceInsights>('/invoices/insights')()).data });

/** Organizations to pick from, by name — for "Add subscription". */
export function useOrganizationOptions(search: string) {
    return useQuery({
        queryKey: ['billing', 'organization-options', search],
        queryFn: async () => {
            const { data } = await http.get<ApiResponse<Array<{ uuid: string; organization_name: string; organization_code: string }>>>(
                '/admin/organizations',
                { params: { search: search || undefined, per_page: 20 } },
            );

            return data.data;
        },
    });
}
export const usePlans = () => useQuery({ queryKey: ['billing', 'plans'], queryFn: async () => (await get<Plan[]>('/plans')()).data });
export const useModulePrices = () => useQuery({ queryKey: ['billing', 'module-prices'], queryFn: async () => (await get<ModulePriceRow[]>('/module-prices')()).data });
export const useAddons = () => useQuery({ queryKey: ['billing', 'addons'], queryFn: async () => (await get<Addon[]>('/addons')()).data });
export const useUsageRates = () =>
    useQuery({
        queryKey: ['billing', 'usage-rates'],
        queryFn: async () => (await get<{ rates: UsageRate[]; channels: Record<string, { metered: boolean; note: string | null }> }>('/usage-rates')()).data,
    });
export const useBillingSettings = () => useQuery({ queryKey: ['billing', 'settings'], queryFn: async () => (await get<BillingSettings>('/settings')()).data });

export function useSubscriptions(filters: Record<string, unknown>) {
    return useQuery({
        queryKey: ['billing', 'subscriptions', filters],
        placeholderData: (previous) => previous,
        queryFn: async () => {
            const body = await get<AdminSubscription[]>('/subscriptions', filters)();

            return { rows: body.data, meta: body.meta as ApiMeta & { total: number } };
        },
    });
}

export function useInvoices(filters: Record<string, unknown>) {
    return useQuery({
        queryKey: ['billing', 'invoices', filters],
        placeholderData: (previous) => previous,
        queryFn: async () => {
            const body = await get<AdminInvoice[]>('/invoices', filters)();

            return { rows: body.data, meta: body.meta };
        },
    });
}

export function useOrganizationSubscription(uuid: string) {
    return useQuery({
        queryKey: ['billing', 'organization', uuid],
        queryFn: async () => (await get<OrganizationSubscription>(`/organizations/${uuid}/subscription`)()).data,
    });
}

/* --- mutations ------------------------------------------------------------ */

/**
 * Any billing write. Toasts the server's own message and refreshes every
 * billing query — a price change shows up on the list, a lock on the overview.
 */
export function useBillingMutation<TBody = unknown>(method: 'post' | 'put' | 'delete', path: string | ((body: TBody) => string)) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: TBody) => {
            const url = `${ROOT}${typeof path === 'function' ? path(body) : path}`;

            // A path carried on the body (`__path`) is for the URL, not the server.
            const payload = body && typeof body === 'object' && '__path' in body
                ? Object.fromEntries(Object.entries(body as Record<string, unknown>).filter(([key]) => key !== '__path'))
                : body;

            const { data } = method === 'delete'
                ? await http.delete<ApiResponse<unknown>>(url, { data: payload })
                : await http[method]<ApiResponse<unknown>>(url, payload);

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Saved');
            void queryClient.invalidateQueries({ queryKey: ['billing'] });
        },
    });
}
