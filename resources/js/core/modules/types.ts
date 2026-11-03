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

    organizations: number;
    /** Bound at all, including revoked, scheduled and lapsed. */
    assigned: number;
    expiring_soon: number;

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
