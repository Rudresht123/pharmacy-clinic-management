export interface ModuleCapability {
    key: string;
    name: string;
}

/** A catalogue row with how widely it has been sold. */
export interface CatalogueModule {
    key: string;
    name: string;
    description: string | null;
    group: string;
    icon: string | null;
    is_core: boolean;

    /**
     * Null for core modules — every organization has them, so a count would
     * report zero and read as "nobody", which is the opposite of the truth.
     */
    organizations: number | null;
    /** Bound at all, including revoked, scheduled and lapsed. */
    assigned: number | null;
    expiring_soon: number | null;

    capabilities: ModuleCapability[];
}

export interface ModuleCataloguePayload {
    modules: CatalogueModule[];
    groups: string[];
    capability_count: number;
}

/** One organization, summarised by what it has been sold. */
export interface ModuleOrganization {
    uuid: string;
    name: string;
    code: string | null;
    subdomain: string | null;
    status: string;
    type: string | null;

    /** Optional modules only — core ones would make every row read the same. */
    modules: number;
    expiring_soon: number;
    capabilities: number;
}
