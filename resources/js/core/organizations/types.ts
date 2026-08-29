import type { Timestamps } from '@/shared/types/api';

export interface OrganizationType extends Timestamps {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
}

/**
 * The lifecycle an organization moves through — Build Spec §5, §9.
 *
 * pending       created, nothing provisioned yet
 * provisioning  the job is running
 * active        live; the owner can sign in
 * suspended     logins blocked, data untouched, reversible
 * cancelled     subscription closed, read-only then blocked
 * failed        provisioning stopped part-way; retryable
 */
export type OrganizationStatus =
    'pending' | 'provisioning' | 'active' | 'suspended' | 'cancelled' | 'failed';

export interface Organization extends Timestamps {
    /**
     * The only identifier the API exposes. The row id stays on the server, so
     * a URL can never be walked from one organization to the next.
     */
    uuid: string;
    slug: string;

    organization_name: string;
    organization_code: string;
    organization_type_id: number | null;
    organization_type?: OrganizationType | null;

    subdomain: string;
    database_name: string;

    legal_name: string | null;
    gstin: string | null;
    drug_license_no: string | null;

    contact_person_name: string | null;
    email: string | null;
    phone_number: string | null;
    address: string | null;

    profile_image_id: number | null;
    profile_image_url: string;

    status: OrganizationStatus;
    is_active: boolean;
    is_setup_completed: boolean;

    plan_id: number | null;
    trial_ends_at: string | null;
    activated_at: string | null;
    suspended_at: string | null;
    suspension_reason: string | null;

    timezone: string;
    currency: string;
    country: string;
    notes: string | null;
}

/** What the create/edit form collects. */
export interface OrganizationFormValues {
    organization_name: string;
    organization_code: string;
    organization_type_id: string;
    subdomain: string;
    contact_person_name: string;
    email: string;
    phone_number: string;
    address: string;
    is_active: boolean;
    profile_image?: FileList;

    // Licensing — nullable at creation, required before going live (§5).
    legal_name: string;
    gstin: string;
    drug_license_no: string;
}
