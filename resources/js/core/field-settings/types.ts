export type FieldDataType =
    | 'text'
    | 'email'
    | 'date'
    | 'select'
    /** Several of the same list at once; the value is an array. */
    | 'multiselect'
    | 'textarea'
    | 'boolean'
    | 'number';

/**
 * One field as the server describes it, after the organization's own
 * preferences have been laid over the code registry.
 *
 * `group` is open rather than a union: each entity names its own groups
 * (locations use identity/address/compliance, people use person/access) and
 * the settings screen only needs to bucket by whatever comes back.
 */
export interface ConfigurableField {
    key: string;
    label: string;
    placeholder?: string | null;
    type: FieldDataType;
    group: string;
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
    /** What this organization calls these records, plural. */
    label: string;
    singular: string;
    fields: ConfigurableField[];
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

/** Entity keys are singular and match the server's EntityFieldSetting constants. */
export type ConfigurableEntity = 'location' | 'user' | 'customer' | 'doctor';

/** What one organization calls a record — pharmacies say customer, clinics patient. */
export interface EntityLabel {
    entity: ConfigurableEntity;
    label: string;
    singular: string;
}
