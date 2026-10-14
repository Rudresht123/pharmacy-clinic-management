import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';

/** Which branches this dashboard is about. */
export interface DashboardScope {
    /** Null means every branch — the owner, and head office. */
    branches: number[] | null;
    label: string;
    branch_id: number | null;
    branch_name: string | null;
    city: string | null;
    /** The days every period figure is about, and what they are compared with. */
    window?: { from: string; to: string; compare: string };
    /** The branches a card's "All Branches" filter may narrow to. */
    options?: { id: number; name: string }[];
}

/* --- the network view ------------------------------------------------ */

/** The organization's size, for the banner. A null count is one they may not see. */
export interface NetworkOverview {
    since: string | null;
    branches: number | null;
    doctors: number | null;
    staff: number | null;
}

export interface KpiCard {
    key: 'patients' | 'appointments' | 'revenue' | 'purchases' | 'profit';
    label: string;
    value: number;
    /** Null when the period before had nothing to compare with. */
    change: number | null;
    money?: boolean;
    hint?: string;
    series: number[];
}

export interface RevenuePoint {
    key: string;
    label: string;
    title: string;
    opd: number;
    pharmacy: number;
    laboratory: number;
    procedures: number;
    total: number;
}

export type Granularity = 'daily' | 'weekly' | 'monthly';

export interface RevenueOverviewPanel {
    granularity: Granularity;
    location_id: number | null;
    series: RevenuePoint[];
}

export interface BranchPerformanceRow {
    id: number;
    name: string;
    city: string | null;
    is_active: boolean;
    patients: number;
    /** Null where the caller may not see appointments or money. */
    appointments: number | null;
    revenue: number | null;
    profit: number | null;
    growth: number | null;
}

export interface BranchPerformancePanel {
    total: number;
    rows: BranchPerformanceRow[];
}

export interface VisitPoint {
    key: string;
    label: string;
    title: string;
    new: number;
    returning: number;
}

export interface VisitTrendsPanel {
    granularity: 'day' | 'month';
    location_id: number | null;
    series: VisitPoint[];
    new: number;
    returning: number;
}

export type ServicePeriod = 'this_month' | 'last_month' | 'this_year';

export interface TopServicesPanel {
    period: ServicePeriod;
    total: number;
    rows: { key: string; label: string; amount: number; share: number }[];
}

export interface StockAlertRow {
    key: string;
    name: string;
    branch: string | null;
    quantity: number;
    unit: string;
    status: 'expired' | 'out' | 'low';
    expiry_date: string | null;
}

export interface StockAlertsPanel {
    counts: { expired: number; out: number; low: number };
    rows: StockAlertRow[];
}

export interface PnlFigure {
    value: number;
    change: number | null;
    series: number[];
}

export type PnlPeriod = 'this_year' | 'last_year';

export interface ProfitLossPanel {
    period: PnlPeriod;
    /** The months the three series run over, in order. */
    months: { key: string; label: string; title: string }[];
    revenue: PnlFigure;
    expenses: PnlFigure;
    profit: PnlFigure;
}

export interface BranchActivityEntry {
    id: number;
    event: string;
    entity_type: string;
    text: string;
    detail?: string | null;
    branch: string | null;
    actor_name: string | null;
    created_at: string | null;
}

/** A branch's own day. */
export interface VisitsPanel {
    points: { label: string; title: string; value: number }[];
}

export interface TypeSlice {
    label: string;
    total: number;
    muted: boolean;
}

export interface StockRow {
    name: string;
    stock: number;
    status: 'low' | 'out';
}

export interface RevenuePanel {
    rows: { label: string; amount: number; today?: boolean }[];
    total: number;
}

export interface TaskRow {
    label: string;
    badge: string | null;
    tone: string | null;
}

export interface RecentPatient {
    id: number;
    name: string;
    age?: number;
    type?: string;
    phone?: string | null;
    city?: string | null;
    joined_at: string | null;
}

export interface BranchInfoPanel {
    id: number;
    name: string;
    city: string | null;
    state: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    pincode: string | null;
}

/** One of the figures across the top. */
export interface HeadlineCard {
    key: 'branches' | 'staff' | 'patients' | 'appointments' | 'consultations' | 'revenue';
    label: string;
    total: number;
    this_month: number;
    /** Null when last month was zero — a rise from nothing has no percentage. */
    change: number | null;
    /** Formatted as currency rather than a count. */
    money?: boolean;
    /** What the "+N" is measured against — "this month", "from yesterday". */
    hint?: string;
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
        /** The branch, on the organization dashboard. */
        branch?: string | null;
        /** The doctor, on a branch's own. */
        doctor?: string | null;
        type: string;
        status?: string;
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
 * A department, top level only — the dashboard's glance at the shape of the
 * organisation, not the tree the Departments screen itself shows. A
 * sub-department's doctors and staff already count toward its parent here.
 */
export interface Department {
    id: number;
    name: string;
    code: string | null;
    doctors_count: number;
    staff_count: number;
    children_count: number;
}

export interface PlanPanel {
    modules: number;
    names: string[];
    /** Only in demo mode — the software has no subscription tiers. */
    name?: string;
    blurb?: string;
    /** Only in demo mode, with `name`. */
    valid_until?: string | null;
    trial_ends_at?: string | null;
    since?: string | null;
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
    /**
     * Which dashboard this is.
     *
     * Somebody working AT a branch gets that branch's day; somebody
     * organization-wide gets the network. Two different questions, so two
     * different sets of panels rather than one set that means less the further
     * out you stand.
     */
    context: 'organization' | 'branch';

    scope: DashboardScope;
    headline?: HeadlineCard[];
    patients?: PatientsPanel;
    branches?: BranchesPanel;
    appointments?: AppointmentsPanel;
    insights?: Insight[];
    departments?: Department[];
    plan?: PlanPanel;

    /* --- a branch's own day ------------------------------------------- */
    visits?: VisitsPanel;
    by_type?: TypeSlice[];
    recent_patients?: RecentPatient[];
    branch_info?: BranchInfoPanel | null;
    stock?: StockRow[];
    revenue?: RevenuePanel;
    tasks?: TaskRow[];
    activity?: ActivityEntry[];

    /* --- the network view --------------------------------------------- */
    overview?: NetworkOverview;
    kpis?: KpiCard[];
    revenue_overview?: RevenueOverviewPanel;
    branch_performance?: BranchPerformancePanel;
    visit_trends?: VisitTrendsPanel;
    top_services?: TopServicesPanel;
    stock_alerts?: StockAlertsPanel;
    profit_loss?: ProfitLossPanel;
    branch_activity?: BranchActivityEntry[];

    /** True while the screen is filled with sample figures — see config/hms.php. */
    demo?: boolean;
}

/** The days the network view is about. Left out, the server uses this month. */
export interface DashboardRange {
    from: string;
    to: string;
}

export function useDashboard(range?: DashboardRange) {
    return useQuery({
        queryKey: resourceKey('tenant/dashboard', range ?? {}),
        queryFn: async (): Promise<DashboardSummary> => {
            const { data } = await http.get<ApiResponse<DashboardSummary>>('/tenant/dashboard', {
                params: range,
            });

            return data.data;
        },
        /*
         * Short: today's queue is the reason somebody looks at this, and a
         * minute-old count of who is waiting is worse than no count.
         */
        staleTime: 30 * 1000,
        // A new range repaints the figures in place rather than blanking the
        // screen to a spinner while it loads.
        placeholderData: keepPreviousData,
    });
}

/**
 * One card re-asked with its own filter — the revenue chart by week, the
 * visit chart for one branch.
 *
 * Only runs once the card's filter differs from what the full dashboard was
 * asked with: until then the card reads the panel that arrived with the rest
 * of the screen, and this costs nothing.
 */
export function useDashboardPanel<T>(
    panel: 'revenue_overview' | 'visit_trends' | 'top_services' | 'profit_loss',
    params: Record<string, string | number | undefined>,
    enabled: boolean,
) {
    return useQuery({
        queryKey: resourceKey(`tenant/dashboard/${panel}`, params),
        queryFn: async (): Promise<T> => {
            const { data } = await http.get<ApiResponse<T>>(`/tenant/dashboard/${panel}`, {
                params,
            });

            return data.data;
        },
        enabled,
        staleTime: 30 * 1000,
        placeholderData: keepPreviousData,
    });
}
