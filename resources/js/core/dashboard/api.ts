import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';

/** Which branches this dashboard is about. */
export interface DashboardScope {
    /** Null means every branch — the owner, and head office. */
    branches: number[] | null;
    label: string;
}

/** One of the figures across the top. */
export interface HeadlineCard {
    key: 'branches' | 'staff' | 'patients' | 'appointments';
    label: string;
    total: number;
    this_month: number;
    /** Null when last month was zero — a rise from nothing has no percentage. */
    change: number | null;
}

export interface PatientsPanel {
    total: number;
    active: number;
    by_month: { month: string; total: number }[];
    by_branch: {
        location_id: number | null;
        label: string;
        total: number;
        /** An absence rather than a category — drawn grey, never given a hue. */
        muted: boolean;
    }[];
}

export interface BranchRow {
    id: number;
    name: string;
    city: string | null;
    state: string | null;
    staff: number;
    patients: number;
    is_active: boolean;
    /** The branch the workspace opens on — marked in the list. */
    is_primary?: boolean;
}

export interface BranchesPanel {
    total: number;
    active: number;
    rows: BranchRow[];
    licences_needing_attention: {
        id: number;
        name: string;
        expires_at: string;
        expired: boolean;
    }[];
}

export interface TrendPoint {
    label: string;
    title: string;
    value: number;
}

export interface AppointmentsPanel {
    date: string;
    waiting: number;
    in_consultation: number;
    seen: number;
    expected: number;
    longest_wait_minutes: number | null;
    trend: { current: TrendPoint[]; previous: TrendPoint[] };
    upcoming: {
        id: number;
        time: string | null;
        patient: string;
        branch: string | null;
        type: string;
    }[];
}

/**
 * One figure in the insights strip.
 *
 * `placeholder` marks a figure whose module has not shipped — revenue needs
 * billing, retention needs a record of leavers. The client shows those as
 * sample rather than letting an untraceable number read as a measurement.
 */
export interface Insight {
    key: string;
    label: string;
    value: string;
    icon: string;
    /** Month-on-month, where the figure has a comparison. */
    change?: number | null;
    placeholder?: boolean;
}

/**
 * A department — a module with no table, model or routes yet.
 *
 * Present only under demo mode, which is why the panel is optional like every
 * other: when the module ships it arrives from DashboardSummary instead and
 * nothing on this side changes.
 */
export interface Department {
    id: number;
    name: string;
    head: string;
    staff: number;
    patients: number;
}

export interface PlanPanel {
    modules: number;
    names: string[];
}

export interface ActivityEntry {
    id: number;
    action: string;
    entity_type: string;
    entity_label: string | null;
    /** A fuller line under the title, where the entry has one. */
    detail?: string | null;
    actor_name: string | null;
    created_at: string | null;
}

/**
 * The dashboard, assembled per person.
 *
 * Every panel but `scope` is optional, and that is the contract rather than
 * an oversight: a panel the caller may not see is never computed, and a panel
 * belonging to a module that has not shipped yet simply is not there. A screen
 * written against this has to treat every section as absent-by-default, which
 * is what lets a new module add one without a client release.
 */
export interface DashboardSummary {
    scope: DashboardScope;
    headline?: HeadlineCard[];
    patients?: PatientsPanel;
    branches?: BranchesPanel;
    appointments?: AppointmentsPanel;
    insights?: Insight[];
    departments?: Department[];
    plan?: PlanPanel;
    activity?: ActivityEntry[];
    /** True while the screen is filled with sample figures — see config/hms.php. */
    demo?: boolean;
}

export function useDashboard() {
    return useQuery({
        queryKey: resourceKey('tenant/dashboard'),
        queryFn: async (): Promise<DashboardSummary> => {
            const { data } = await http.get<ApiResponse<DashboardSummary>>('/tenant/dashboard');

            return data.data;
        },
        /*
         * Short: today's queue is the reason somebody looks at this, and a
         * minute-old count of who is waiting is worse than no count.
         */
        staleTime: 30 * 1000,
    });
}
