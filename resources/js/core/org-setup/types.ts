/**
 * Organisation setup, as GET /tenant/setup returns it.
 *
 * The server decides every status — read from the organization's own data,
 * or from a section the admin signed off. The screen only renders it.
 */

export type SetupStepKey =
    | 'organization'
    | 'branches'
    | 'departments'
    | 'users'
    | 'roles'
    | 'settings'
    | 'review';

/**
 * Completed; a required section still to do; or an optional one not done.
 * "Current" is the screen's, not the server's: it is whichever is open.
 */
export type SetupStepStatus = 'completed' | 'incomplete' | 'pending';

export interface SetupStep {
    key: SetupStepKey;
    mandatory: boolean;
    completed: boolean;
    status: SetupStepStatus;
    /** What is still needed, in words. Empty once complete. */
    missing: string[];
    counts: Record<string, number>;
    completed_at: string | null;
}

export interface SetupOrganization {
    name: string;
    code: string | null;
    /** Set by the platform when the organisation was registered. */
    type: string | null;
    legal_name: string | null;
    contact_person_name: string | null;
    /** The owner's sign-in; changed by the platform, not here. */
    email: string;
    phone_number: string | null;
    address: string | null;
    gstin: string | null;
    drug_license_no: string | null;
    website_url: string | null;
    city: string | null;
    state_province: string | null;
    postal_code: string | null;
}

export interface SetupStatus {
    organization: SetupOrganization;
    steps: SetupStep[];
    total: number;
    completed_count: number;
    percent: number;
    /** The first required section still to do — where the screen opens. */
    current: SetupStepKey;
    can_complete: boolean;
    completed_at: string | null;
    /** When anything behind the setup last changed. */
    last_updated: string | null;
}

/** How each section presents itself. The order is the server's. */
export const STEP_META: Record<SetupStepKey, { title: string; description: string; icon: string }> = {
    organization: {
        title: 'Organisation Information',
        description: 'Basic details about your organisation',
        icon: 'ti ti-building',
    },
    branches: {
        title: 'Clinic / Branch Setup',
        description: 'Add your clinic locations',
        icon: 'ti ti-building-hospital',
    },
    departments: {
        title: 'Departments',
        description: 'Manage your departments',
        icon: 'ti ti-layout-grid',
    },
    users: {
        title: 'Users & Staff',
        description: 'Add your team members',
        icon: 'ti ti-users-group',
    },
    roles: {
        title: 'Roles & Permissions',
        description: 'Set access and permissions',
        icon: 'ti ti-shield-lock',
    },
    settings: {
        title: 'Clinic Settings',
        description: 'Configure system settings',
        icon: 'ti ti-adjustments',
    },
    review: {
        title: 'Final Review',
        description: 'Review and complete setup',
        icon: 'ti ti-clipboard-check',
    },
};

/** The longer line under each step's title, in the content area. */
export const STEP_LEAD: Record<SetupStepKey, string> = {
    organization: 'Add basic information about your organisation. This information is used across the system.',
    branches: 'Add the clinics and branches you operate. Appointments, patients and stock all belong to one of these.',
    departments: 'Doctors are grouped by department on bookings, the OPD board and reports.',
    users: 'Add the people who will sign in. Each gets their own login and a role that decides what they may do.',
    roles: 'Review what each kind of job may do. The owner is never limited by a role.',
    settings: 'Choose what you call the people you serve, and which modules each branch runs.',
    review: 'Check everything below, then complete the setup to open your workspace.',
};
