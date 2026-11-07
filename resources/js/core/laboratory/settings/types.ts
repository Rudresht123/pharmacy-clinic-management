/** How the organisation's laboratory works — one record, organisation-wide. */
export interface LabSettings {
    approval_levels: 1 | 2;
    allow_self_review: boolean;
    collect_requires_payment: boolean;
    release_partial_reports: boolean;
    critical_call_required: boolean;
    urgent_tat_hours: number | null;
    stat_tat_minutes: number | null;
    label_size: LabelSize;
    updated_at: string | null;
    updated_by_name?: string | null;
}

export type LabelSize = '50x25' | '38x25' | 'a4_sheet';

export const LABEL_SIZE_LABELS: Record<LabelSize, string> = {
    '50x25': '50 × 25 mm roll',
    '38x25': '38 × 25 mm roll',
    a4_sheet: 'A4 sheet of labels',
};

export type MasterKey =
    | 'departments'
    | 'test-categories'
    | 'methods'
    | 'units'
    | 'containers'
    | 'sample-types'
    | 'rejection-reasons'
    | 'equipment'
    | 'outsource-labs';

export type MasterFieldType =
    | 'text'
    | 'textarea'
    | 'email'
    | 'phone'
    | 'code'
    | 'number'
    | 'switch'
    | 'choice'
    | 'select';

/** One field of a list, as the server describes it (App\Support\Laboratory\LabMasters). */
export interface MasterField {
    name: string;
    label: string;
    type: MasterFieldType;
    required: boolean;
    max: number | null;
    min: number | null;
    step: number | null;
    options: { value: string; label: string }[] | null;
    /** A `select`: the list it picks from, or `locations` for branches. */
    source: MasterKey | 'locations' | null;
    default: unknown;
    placeholder: string | null;
    hint: string | null;
    /** Width in a 12-column form row. */
    span: number;
    /** Shown as a column in the table. */
    list: boolean;
}

export interface MasterDefinition {
    key: MasterKey;
    singular: string;
    plural: string;
    icon: string;
    description: string;
    /** The field a row is read by: name, symbol or reason. */
    label: string;
    starter: boolean;
    fields: MasterField[];
}

/** A row of any list: its own fields plus `label`, and `<x>_name` beside each `<x>_id`. */
export interface MasterRow {
    id: number;
    label: string;
    is_active: boolean;
    position: number;
    created_at: string | null;
    updated_at: string | null;
    [field: string]: unknown;
}
