import type { Timestamps } from '@/shared/types/api';

/** A place stock is kept and dispensed from, at one branch. */
export interface PharmacyStore extends Timestamps {
    id: number;

    location_id: number;
    location_name?: string | null;

    name: string;
    code: string;
    store_type: string;
    /** The store whose availability a doctor sees. One per branch. */
    is_default: boolean;
    is_active: boolean;

    pharmacist_user_id: number | null;
    pharmacist_name?: string | null;

    address: string | null;
    phone: string | null;
    drug_license_no: string | null;
    drug_license_expiry_date: string | null;

    /** What it trades under: its own licence, or its branch's. */
    licence?: { number: string | null; expires_on: string | null; own: boolean };

    medicines_count?: number;

    /** Set only on a removed store, which the restore screen lists. */
    deleted_at: string | null;
    deletion_reason?: string | null;
    deleted_by_name?: string | null;
}

/** "This store stocks this medicine", with the levels that make it low. */
export interface StoreMedicine {
    id: number;
    pharmacy_store_id: number;
    medicine_id: number;
    medicine?: {
        display_name: string;
        generic_name: string;
        base_unit: string;
        is_active: boolean;
        /** The configuration can outlive a medicine that was removed. */
        is_removed: boolean;
    };
    reorder_level: number;
    minimum_stock_level: number;
    maximum_stock_level: number | null;
    is_active: boolean;
    updated_at: string | null;
}

/** One row as the levels endpoint takes it. */
export interface StoreMedicineInput {
    medicine_id: number;
    reorder_level: number;
    minimum_stock_level: number;
    maximum_stock_level: number | null;
    is_active: boolean;
}

/** What the store form may choose from — already limited to the caller's reach. */
export interface StoreFormOptions {
    branches: { id: number; name: string; drug_license_no: string | null }[];
    pharmacists: { id: number; name: string }[];
    store_types: string[];
}

export const STORE_TYPE_LABELS: Record<string, string> = {
    hospital_pharmacy: 'Hospital pharmacy',
    opd_counter: 'OPD counter',
    ipd_pharmacy: 'IPD pharmacy',
    emergency: 'Emergency',
    retail: 'Retail',
    central: 'Central store',
};

/**
 * How this organisation bills, prices and warns. One record, read by every
 * counter and changed under `pharmacy.stores`.
 */
export interface PharmacySettings {
    invoice_prefix: string;
    round_off_enabled: boolean;

    /** What the counter charges: the printed MRP, or the store's own price. */
    price_basis: 'mrp' | 'selling';
    /** Indian MRP includes GST, so a bill splits the tax out of the price. */
    prices_include_tax: boolean;

    expiry_warning_days: number;

    allow_walk_in: boolean;
    credit_sales_enabled: boolean;
    require_prescription: boolean;

    default_payment_method: string;

    updated_at: string | null;
    updated_by_name?: string | null;
}

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    upi: 'UPI',
    bank_transfer: 'Bank transfer',
    credit: 'Credit',
    other: 'Other',
    /* Not a tender: a bill settled by more than one, which no single method
       column could ever say. */
    split: 'Split',
};

/** One day on the sales-against-purchases chart. */
export interface PharmacyDashboardPoint {
    date: string;
    /** Axis tick — the weekday. */
    label: string;
    /** Fuller wording for the tooltip, e.g. "17 Sep". */
    title: string;
    sales: number;
    purchases: number;
}

export interface PharmacyDashboardBill {
    id: number;
    sale_number: string;
    customer_name: string;
    items_count: number;
    total_amount: number;
    amount_due: number;
    /** A tender, `split` for more than one, or `credit` for none at all. */
    payment: string;
    sale_date: string | null;
}

export interface PharmacyDashboardLowStock {
    medicine_id: number;
    name: string;
    unit: string | null;
    /** What can actually be dispensed — active batches, not past expiry. */
    on_hand: number;
    reorder_level: number;
}

export interface PharmacyDashboardExpiry {
    id: number;
    name: string;
    batch_number: string;
    expiry_date: string | null;
    days_left: number;
    quantity: number;
}

/**
 * One store's day.
 *
 * Never a sum across stores: stock that is low at one counter is not helped by
 * a full shelf at another, and takings added across two answer a question
 * nobody standing at either is asking.
 */
export interface PharmacyDashboard {
    store: { id: number; name: string; branch: string | null };
    date: string;

    today: {
        sales: number;
        bills: number;
        purchases: number;
        /** Against the cost copied onto each bill line when it was sold. */
        gross_profit: number;
    };

    yesterday: { sales: number; bills: number };

    /** Against yesterday's takings. Null when yesterday took nothing. */
    change: number | null;

    stock: {
        value_at_cost: number;
        /** At or below the reorder level — out of stock is the worst of these. */
        low: number;
        out: number;
        expiring: number;
        stocked: number;
        expiry_warning_days: number;
    };

    /** Still owed on completed bills this store issued. */
    outstanding: number;

    series: PharmacyDashboardPoint[];

    /**
     * The last seven days.
     *
     * A counter's takings swing about too much for one morning to say
     * anything: what sells, what it is paid with and what a customer spends
     * only become answerable over a few days.
     */
    week: {
        sold: number;
        bills: number;
        /** What one customer spends in a visit — the figure a shop grows. */
        average_bill: number;
        /** Units handed over the counter. */
        items: number;

        top_items: { medicine_id: number; name: string; units: number; revenue: number }[];
        categories: { label: string; value: number }[];
        /** Tenders, plus "On account" for what was left owed. */
        tenders: { label: string; value: number; muted?: boolean }[];
    };

    recent_bills: PharmacyDashboardBill[];
    low_stock: PharmacyDashboardLowStock[];
    expiring: PharmacyDashboardExpiry[];
}
