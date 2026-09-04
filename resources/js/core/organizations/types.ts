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

/** The technical (not commercial) state of a tenant's physical database. */
export interface TenantDatabase {
    db_name: string;
    db_cluster: string | null;
    provision_status: 'pending' | 'provisioning' | 'provisioned' | 'failed';
    status: 'healthy' | 'degraded' | 'unreachable' | null;
    size_bytes: number | null;
    last_backup_at: string | null;
    last_backup_status: 'success' | 'failed' | null;
}

/** Current vs. target schema version for one tenant's database. */
export interface TenantMigrationState {
    current_version: string | null;
    target_version: string | null;
    status: 'in_sync' | 'behind' | 'failed';
    attempts: number;
    last_error: string | null;
    last_run_at: string | null;
}

export interface Organization extends Timestamps {
    /**
     * The only identifier the API exposes. The row id stays on the server, so
     * a URL can never be walked from one organization to the next.
     */
    uuid: string;
    slug: string;
    tenant_key: string;

    organization_name: string;
    organization_code: string;
    organization_type_id: number | null;
    organization_type?: OrganizationType | null;

    /** Only present on the detail endpoint, not the list. */
    tenant_database?: TenantDatabase | null;
    migration_state?: TenantMigrationState | null;

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

/**
 * Why a module is or is not usable by one organization.
 *
 * `core` means it was never a commercial choice; `unbound` means it has not
 * been sold. The other three are all "has a binding, but" — kept apart
 * because they are different conversations with the customer.
 */
export type ModuleState = 'core' | 'unbound' | 'active' | 'scheduled' | 'expired' | 'revoked';

export interface ModuleCapability {
    key: string;
    name: string;
}

/** A catalogue row, joined with where this organization stands on it. */
export interface OrganizationModule {
    key: string;
    name: string;
    description: string | null;
    group: string;
    icon: string | null;
    is_core: boolean;

    state: ModuleState;
    /** Usable right now — enabled, started and unexpired. */
    is_live: boolean;

    starts_at: string | null;
    expires_at: string | null;
    note: string | null;

    /** What this module lets somebody do, once roles exist to grant it. */
    capabilities: ModuleCapability[];
}

export interface OrganizationModulesPayload {
    modules: OrganizationModule[];
    /** Everything the organization is in a position to grant its own roles. */
    capabilities: string[];
}

/** One binding as the screen submits it. */
export interface ModuleBindingInput {
    key: string;
    is_enabled: boolean;
    starts_at: string | null;
    expires_at: string | null;
    note: string | null;
}
