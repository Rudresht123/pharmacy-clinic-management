/**
 * Note the name: `Location` is also a DOM global, so with `lib: ["DOM"]` a
 * missing import resolves silently to `window.Location` instead of failing.
 * Every file that uses this type must import it explicitly.
 */
export type LocationType =
    | 'RETAIL_STORE'
    | 'WHOLESALE_STORE'
    | 'WAREHOUSE'
    | 'CLINIC'
    | 'DOCTOR_VISITING_LOCATION';

export interface Location {
    id: number;
    name: string;
    code: string;
    type: LocationType;
    is_active: boolean;

    address: string | null;
    city: string | null;
    state: string | null;
    pincode: string | null;
    phone: string | null;
    email: string | null;

    gstin: string | null;
    drug_license_no: string | null;
    drug_license_expiry_date: string | null;
    /** Derived server-side so nothing has to re-check the date. */
    has_expired_licence: boolean;

    custom_fields: Record<string, unknown>;

    created_at: string | null;
    updated_at: string | null;
}

/**
 * One field as the server describes it.
 *
 * The form and table render from this rather than hardcoding their fields,
 * which is what lets per-organization configuration arrive later without
 * either screen changing.
 */
export type FieldDataType =
    | 'text'
    | 'email'
    | 'date'
    | 'select'
    | 'textarea'
    | 'boolean'
    | 'number';

export interface LocationField {
    key: string;
    label: string;
    /** Example value shown in the empty input. */
    placeholder?: string | null;
    type: FieldDataType;
    group: 'identity' | 'address' | 'compliance' | 'custom';
    required: boolean;
    /** Fields the application depends on; they can never be hidden. */
    locked: boolean;
    /** Whether it appears as a column on the list screen. */
    in_table: boolean;
    /** Whether it appears on the form at all. */
    show_in_form: boolean;
    /** True for fields the organization added itself. */
    is_custom: boolean;
    sort_order: number;
    options?: { value: string; label: string }[];
}

export interface FieldSettingsPayload {
    entity: string;
    fields: LocationField[];
    custom_types: FieldDataType[];
}

/** One row as the settings screen submits it. */
export interface FieldSettingInput {
    field_key: string;
    label: string | null;
    placeholder: string | null;
    is_custom: boolean;
    data_type: FieldDataType | null;
    options: { value: string; label: string }[] | null;
    is_required: boolean;
    show_in_form: boolean;
    show_in_table: boolean;
    sort_order: number;
}
