export type ResultType = 'numeric' | 'text' | 'choice' | 'positive_negative';

export type ResultLayout = 'parameters' | 'narrative';

export type Sex = 'any' | 'male' | 'female';

export type AgeUnit = 'days' | 'months' | 'years';

export const RESULT_TYPE_LABELS: Record<ResultType, string> = {
    numeric: 'Number',
    text: 'Text',
    choice: 'Dropdown',
    positive_negative: 'Positive / Negative',
};

export const SEX_LABELS: Record<Sex, string> = {
    any: 'Anyone',
    male: 'Male',
    female: 'Female',
};

export const AGE_UNIT_LABELS: Record<AgeUnit, string> = {
    days: 'Days',
    months: 'Months',
    years: 'Years',
};

export interface Choice {
    value: string;
    abnormal?: boolean;
}

export interface ReferenceRange {
    id: number;
    sex: Sex;
    age_min: number | null;
    age_max: number | null;
    age_unit: AgeUnit | null;
    low: string | null;
    high: string | null;
    critical_low: string | null;
    critical_high: string | null;
    normal_text: string | null;
    display_text: string | null;
    notes: string | null;
    position: number;
    applies_to: string;
    printed: string | null;
}

export interface LabParameter {
    id: number;
    name: string;
    code: string | null;
    section: string | null;
    result_type: ResultType;
    unit_id: number | null;
    unit_symbol?: string | null;
    decimals: number | null;
    choices: Choice[] | null;
    normal_value: string | null;
    method_id: number | null;
    method_name?: string | null;
    is_required: boolean;
    is_printed: boolean;
    position: number;
    ranges?: ReferenceRange[];
}

export interface BranchPrice {
    location_id: number;
    location_name: string | null;
    price: number;
    tax_percent: number | null;
}

export interface LabTest {
    id: number;
    code: string;
    name: string;
    short_name: string | null;
    department_id: number;
    department_name?: string | null;
    category_id: number | null;
    category_name?: string | null;
    sample_type_id: number | null;
    sample_type_name?: string | null;
    container_id: number | null;
    container_name?: string | null;
    method_id: number | null;
    method_name?: string | null;
    sample_volume: string | null;
    description: string | null;
    collection_instructions: string | null;
    processing_instructions: string | null;
    result_layout: ResultLayout;
    report_template: string | null;
    tat_hours: number | null;
    price: number;
    tax_percent: number;
    hsn_code: string | null;
    is_outsourced: boolean;
    outsource_lab_id: number | null;
    outsource_lab_name?: string | null;
    outsource_cost: number | null;
    is_active: boolean;
    position: number;
    from_old_price_list: boolean;
    parameters_count?: number;
    branch_prices_count?: number;
    parameters?: LabParameter[];
    branch_prices?: BranchPrice[];
    created_at: string | null;
    updated_at: string | null;
}
