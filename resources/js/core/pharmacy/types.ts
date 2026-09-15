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
