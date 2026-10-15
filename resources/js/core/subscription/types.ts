/**
 * The organization's subscription, as the server decides it.
 *
 * Every field comes from the records — the status is computed from the
 * subscription's own dates on every request, so what this says is what the
 * API is enforcing. Amounts are paise (integers).
 */
export type SubscriptionState =
    | 'trial'
    | 'active'
    | 'past_due'
    | 'grace'
    | 'expired'
    | 'suspended'
    | 'cancelled';

export interface SubscriptionStatus {
    status: SubscriptionState;
    plan: { name: string | null; slug: string | null };
    billing_cycle: 'monthly' | 'annual' | 'complimentary';
    is_complimentary: boolean;
    price?: number;
    currency?: string;
    started_at?: string | null;
    trial_ends_at?: string | null;
    current_period_start?: string | null;
    expires_at?: string | null;
    grace_ends_at?: string | null;
    next_renewal_at?: string | null;
    cancelled_at?: string | null;
    /** Whole calendar days left, in the organization's timezone. 0 = ends today. */
    days_remaining: number | null;
    grace_days_remaining?: number | null;
    locked: boolean;
    locked_at?: string | null;
    lock_reason?: 'expired' | 'billing_suspended' | null;
    /** Grace set to read-only by the platform: changes are refused. */
    read_only: boolean;
    grace_access?: 'full' | 'read_only';
    /** Expiring within the warning window, or in grace. */
    warning: boolean;
    payment_status?: string;
    pending_plan?: string | null;
    timezone: string;
    /** Whether this person may open the subscription screens and renew. */
    can_manage?: boolean;
}

export interface ResourceUsage {
    key: string;
    label: string;
    used: number;
    limit: number | null;
    unlimited: boolean;
    remaining: number | null;
    near_limit: boolean;
    over_limit: boolean;
}

export interface QuotaUsage {
    key: string;
    label: string;
    unit: string;
    unlimited: boolean;
    included: number | null;
    purchased: number;
    granted: number;
    allowance: number | null;
    used: number;
    remaining: number | null;
    overage: number;
    overage_charge: number;
    period_start: string;
    period_end: string;
}

export interface InvoiceLine {
    type: string;
    ref: string;
    description: string;
    quantity: number;
    unit_price: number;
    amount: number;
}

export interface SubscriptionInvoice {
    uuid: string;
    number: string | null;
    kind: string;
    status: 'draft' | 'issued' | 'paid' | 'void';
    currency: string;
    subtotal: number;
    tax_mode: string;
    tax_rate_bp: number;
    tax: number;
    total: number;
    lines: InvoiceLine[];
    period_start: string | null;
    period_end: string | null;
    issued_at: string | null;
    due_at: string | null;
    paid_at: string | null;
    payments?: Array<{
        method: string;
        status: string;
        amount: number;
        reference: string | null;
        verified_at: string | null;
    }> | null;
}

export interface SubscriptionOverview {
    status: SubscriptionStatus;
    resources: Record<string, ResourceUsage>;
    usage: Record<string, QuotaUsage>;
    modules: Array<{ key: string; name: string; enabled: boolean; source: string }>;
    features: Array<{ key: string; name: string; enabled: boolean }>;
    addons: Array<{
        id: number;
        type: string;
        name: string;
        quantity: number;
        grants: number | null;
        target: string | null;
        billing: string;
        unit_price: number;
        is_live: boolean;
        starts_at: string | null;
        ends_at: string | null;
    }>;
    open_invoices: SubscriptionInvoice[];
    payment: { online: boolean; key_id: string | null };
}

export interface CatalogueEntry {
    plans: Array<{
        id: number;
        slug: string;
        name: string;
        description: string | null;
        monthly_price: number;
        annual_price: number;
        limits: Record<string, number | null>;
        modules: string[];
        features: string[];
        highlights: string[];
        is_current: boolean;
    }>;
    addons: Array<{
        key: string;
        name: string;
        description: string | null;
        kind: string;
        target: string;
        unit_quantity: number;
        billing: string;
        monthly_price: number;
        annual_price: number;
    }>;
    modules: Array<{ key: string; name: string; monthly_price: number; annual_price: number }>;
}

export interface Checkout {
    gateway: 'razorpay';
    key_id: string;
    order_id: string;
    amount: number;
    currency: string;
    invoice: string;
    description: string;
    prefill: { name?: string | null; email?: string | null; contact?: string | null };
}

export interface CheckoutResponse {
    invoice: SubscriptionInvoice;
    checkout: Checkout | null;
    /** How to pay when online payment is not set up. */
    offline: string | null;
}
