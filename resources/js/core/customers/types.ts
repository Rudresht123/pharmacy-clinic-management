export interface CustomerStats {
    total: number;
    active: number;
    inactive: number;
    recent: number;
    by_location: { location_id: number | null; label: string; total: number }[];
}

export interface Customer {
    id: number;
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
