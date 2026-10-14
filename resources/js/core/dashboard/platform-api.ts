import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { DashboardRange } from './api';
import type { LocationSource } from '@/shared/geo/geocode';

/*
 * The super admin dashboard — App\Services\Platform\PlatformDashboard.
 *
 * Real figures only. A panel with nothing behind it yet — plans, revenue,
 * anything counted inside the organizations' own databases — arrives as null
 * and is drawn as an empty state, so the layout never changes shape.
 */

export type OrganizationBucket = 'active' | 'trial' | 'setting_up' | 'suspended' | 'cancelled' | 'failed';

export interface PlatformKpi {
    key: 'organizations' | 'active' | 'joined' | 'awaiting' | 'suspended' | 'modules';
    label: string;
    /** Null while the platform has no source for this figure. */
    value: number | null;
    /** Null when the period before had nothing to compare with — or for a live count. */
    change: number | null;
    /** A line of context, shown where a figure has no change to show. */
    hint?: string | null;
    money?: boolean;
}

export interface MonthPoint {
    key: string;
    label: string;
    title: string;
}

export interface RevenueTrendPanel {
    months: MonthPoint[];
    mrr: number[];
    /** The MRR each month's new subscriptions added. */
    new_subscriptions: number[];
}

export type PlanKey = 'basic' | 'standard' | 'professional' | 'enterprise';

export interface SubscriptionRevenuePanel {
    months: MonthPoint[];
    series: Record<PlanKey, number[]>;
}

export interface Slice {
    key: string;
    label: string;
    total: number;
}

/** One organization in a list. Null where this side of the platform cannot say. */
export interface OrganizationRow {
    /** Null on sample rows — there is no organization to open. */
    uuid: string | null;
    name: string;
    type: string | null;
    plan: string | null;
    clinics: number | null;
    status: OrganizationBucket;
    joined_on?: string | null;
    signed_up_at?: string | null;
}

export interface TopOrganization {
    name: string;
    clinics: number;
    revenue: number;
    growth: number | null;
}

export interface ModuleUsagePanel {
    /** The organizations the shares are of. */
    organizations: number;
    rows: { key: string; label: string; organizations: number; share: number }[];
}

/** One organization at its exact point on the map. */
export interface LocationPlace {
    uuid: string;
    name: string;
    city: string | null;
    state: string | null;
    latitude: number;
    longitude: number;
    /** How far the point can be trusted: manual, address, pincode or city. */
    source: LocationSource | null;
}

export interface LocationsPanel {
    /** What a point counts — clinics where known, otherwise organizations. */
    unit: 'clinics' | 'organizations';
    organizations: number;
    /** How many have a point on the map — the rest have no address located yet. */
    located: number;
    clinics: number | null;
    places: LocationPlace[];
    /** Organizations per state, for the region and state lists. */
    points: { state: string; total: number }[];
}

export interface PlatformActivity {
    id: number;
    event: string;
    entity_type: string;
    text: string;
    actor_name: string | null;
    created_at: string | null;
}

export type EnquiryType = 'demo' | 'trial' | 'contact';

export type EnquiryStatus = 'new' | 'contacted' | 'converted' | 'closed';

/** A demo, trial or contact request from the marketing website. */
export interface EnquiryRow {
    id: number;
    type: EnquiryType;
    name: string;
    organization_name: string | null;
    email: string;
    phone: string | null;
    interest: string | null;
    status: EnquiryStatus;
    created_at: string | null;
}

export interface EnquiriesPanel {
    /** Received in the chosen period, and against the one before. */
    total: number;
    change: number | null;
    demo: number;
    trial: number;
    contact: number;
    /** Nobody has replied yet — any date. */
    awaiting_reply: number;
    rows: EnquiryRow[];
}

export interface PlatformDashboardData {
    window: { from: string; to: string; compare: string };
    kpis: PlatformKpi[];
    revenue_trend: RevenueTrendPanel | null;
    by_plan: Slice[] | null;
    by_status: Slice[];
    new_organizations: OrganizationRow[];
    top_organizations: TopOrganization[] | null;
    recent_signups: OrganizationRow[];
    subscription_revenue: SubscriptionRevenuePanel | null;
    /** Null while no organization is active — there is nothing to take a share of. */
    module_usage: ModuleUsagePanel | null;
    locations: LocationsPanel;
    activity: PlatformActivity[];
    enquiries: EnquiriesPanel;
}

export function usePlatformDashboard(range: DashboardRange) {
    return useQuery({
        queryKey: resourceKey('admin/dashboard', range),
        queryFn: async (): Promise<PlatformDashboardData> => {
            const { data } = await http.get<ApiResponse<PlatformDashboardData>>('/admin/dashboard', {
                params: range,
            });

            return data.data;
        },
        staleTime: 60 * 1000,
        // A new range repaints the figures in place rather than blanking the
        // screen to a spinner.
        placeholderData: keepPreviousData,
    });
}
