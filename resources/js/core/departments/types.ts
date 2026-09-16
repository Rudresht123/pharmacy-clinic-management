/**
 * A department, or a sub-department of one.
 *
 * Two levels: a department has `parent_id` null and its sub-departments in
 * `children`; a sub-department has a parent and no children of its own.
 */
export interface Department {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    parent_id: number | null;
    parent_name?: string | null;
    is_top_level: boolean;
    is_active: boolean;
    sort_order: number;
    doctors_count?: number;
    /** People who sign in and work here — receptionists, nurses, technicians. */
    staff_count?: number;
    children_count?: number;
    children?: Department[];
}

/** One department as the form saves it. */
export interface DepartmentPayload {
    name: string;
    code: string | null;
    description: string | null;
    parent_id: number | null;
    is_active: boolean;
}
