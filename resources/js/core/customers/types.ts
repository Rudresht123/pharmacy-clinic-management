/** A named slice of the whole, in the order the server chose to return it. */
export interface StatSlice {
    key: string;
    label: string;
    total: number;
    /** An absence rather than a category — drawn grey, never given a hue. */
    muted: boolean;
}

export interface CustomerStats {
    total: number;
    active: number;
    inactive: number;
    recent: number;
    by_location: { location_id: number | null; label: string; total: number }[];
    by_month: { month: string; label: string; total: number; running: number }[];
    /** One tiny series per branch, on the same twelve-month spine. */
    by_branch_month: {
        location_id: number | null;
        label: string;
        total: number;
        points: number[];
    }[];
    /** Seven buckets, Monday first. */
    by_weekday: { label: string; total: number }[];
    by_gender: StatSlice[];
    /** Ordinal: render in this order, never sorted by size. */
    by_age_band: StatSlice[];
    by_city: { label: string; total: number }[];
}

export interface Customer {
    id: number;
    /** The number a patient quotes and the desk searches on: P-00001. */
    code: string | null;
    name: string;

    /** Where they signed up — provenance, never a restriction on access. */
    registered_location_id: number | null;
    registered_location?: { id: number; name: string } | null;
    phone: string | null;
    email: string | null;

    date_of_birth: string | null;
    /** Derived server-side from the date of birth. */
    age: number | null;
    gender: 'male' | 'female' | 'other' | null;

    address: string | null;
    city: string | null;
    state: string | null;
    pincode: string | null;

    notes: string | null;
    is_active: boolean;

    custom_fields: Record<string, unknown>;

    created_at: string | null;
    updated_at: string | null;
}
