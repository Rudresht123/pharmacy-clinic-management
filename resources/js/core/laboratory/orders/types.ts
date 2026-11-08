export type OrderStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';

export type ItemStatus = 'pending' | 'completed' | 'cancelled';

export interface LabOrderItem {
    id: number;
    test_name: string;
    test_code: string | null;
    lab_test_id: number | null;
    department_name: string | null;
    sample_type_name: string | null;
    specimen: string | null;
    /** The doctor's note on this test — "Fasting". */
    instructions?: string | null;
    status: ItemStatus;
    result_value: string | null;
    result_unit: string | null;
    reference_range: string | null;
    is_abnormal: boolean;
    result_text: string | null;
    notes: string | null;
    completed_at: string | null;
    sort_order: number;
}

export interface LabOrder {
    id: number;
    order_number: string;
    location_id: number;
    location_name?: string | null;
    customer_id: number;
    patient_name?: string | null;
    patient_code?: string | null;
    patient_age?: number | null;
    patient_gender?: string | null;
    patient_photo_url?: string | null;
    doctor_id: number | null;
    doctor_name?: string | null;
    appointment_id: number | null;
    token_label?: string | null;
    order_date: string | null;
    status: OrderStatus;
    clinical_notes: string | null;
    started_at: string | null;
    started_by_name?: string | null;
    completed_at: string | null;
    completed_by_name?: string | null;
    cancelled_at: string | null;
    cancelled_by_name?: string | null;
    cancellation_reason: string | null;
    departments?: string[];
    sample_types?: string[];
    abnormal_count?: number;
    tests_summary?: string;
    pending_count?: number;
    items?: LabOrderItem[];
    created_at: string | null;
}

export type OrderCounts = Record<'all' | OrderStatus, number>;
