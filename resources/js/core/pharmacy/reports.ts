import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';

/** Every report, in the order the screen offers them. */
export const REPORTS = ['sales', 'purchases', 'stock', 'expiry', 'profit', 'gst'] as const;

export type ReportKey = (typeof REPORTS)[number];

export const REPORT_LABELS: Record<ReportKey, string> = {
    sales: 'Sales',
    purchases: 'Purchases',
    stock: 'Stock',
    expiry: 'Expiry',
    profit: 'Profit',
    gst: 'GST',
};

/** What each report is for, in a line under its title. */
export const REPORT_BLURBS: Record<ReportKey, string> = {
    sales: 'What the counter took, bill by bill.',
    purchases: 'What was bought in, against goods received notes.',
    stock: 'What is on the shelf now, and what it is worth.',
    expiry: 'What is about to be worth nothing.',
    profit: 'What each item made, against what its stock cost.',
    gst: 'Tax collected, by rate and HSN code.',
};

/** One headline figure on a report's dashboard. */
export interface ReportFigure {
    label: string;
    value: number;
    money?: boolean;
    suffix?: string;
    hint?: string;
}

export interface ReportSummary {
    report: ReportKey;
    from: string;
    to: string;
    /** False for the reports about the shelf right now, which ignore dates. */
    dated: boolean;
    figures: ReportFigure[];
    series: { date: string; label: string; title: string; value: number }[];
    /**
     * The splits behind the figures.
     *
     * Each says how it should be drawn: a ring for a total dividing up, bars
     * for a ranking. `money: false` marks the ones counted in things rather
     * than rupees — "38 out of stock" is not ₹38.
     */
    splits: {
        title: string;
        kind: 'donut' | 'bars';
        money?: boolean;
        /** Labels are stored tender codes (`upi`) and need reading as words. */
        tenders?: boolean;
        rows: { label: string; value: number; muted?: boolean }[];
    }[];
    /** Present only when asked for with `dashboard: true`. */
    dashboard?: ReportDashboard;
}

/* --- the reports screen's dashboard --------------------------------- */

/** One of the four figures across the top of a report's dashboard. */
export interface DashboardKpi {
    key: string;
    label: string;
    value: number;
    money?: boolean;
    suffix?: string;
    /** Against the period before; null or absent where there is none. */
    change?: number | null;
    /** A cost, where a rise is the bad direction. */
    cost?: boolean;
    hint?: string;
    hint_tone?: 'warn' | 'bad' | null;
    /** What the figure counts, behind the ⓘ beside its name. */
    info?: string;
    /** A short run of bars across the window; empty when there is no shape to draw. */
    spark?: number[];
}

export interface DashboardChart {
    title: string;
    subtitle?: string;
    icon: string;
    kind: 'area' | 'bars' | 'stacked' | 'lines' | 'donut' | 'meter';
    money: boolean;
    /** Day-by-day points the screen may fold into weeks or months. */
    granular?: boolean;
    /** A donut that takes the wide slot, and lays its legend out beside it. */
    wide?: boolean;
    points?: { date: string; label: string; title: string }[];
    series?: { label: string; tone: string; values: number[]; dashed?: boolean }[];
    /** What the figure in a donut's hole is called. */
    centre?: string;
    rows?: { key?: string; label: string; value: number; muted?: boolean }[];
}

export type CellFormat =
    | 'rank'
    | 'money'
    | 'count'
    | 'percent'
    | 'date'
    | 'datetime'
    | 'badge'
    | 'delta'
    | 'muted'
    | 'expiry';

export interface DashboardTable {
    title: string;
    subtitle?: string;
    icon: string;
    /** Which report's table "View all" opens. */
    link: ReportKey;
    /** `sub` names a second value drawn small under the first — a batch under its medicine. */
    columns: { key: string; label: string; format?: CellFormat; sub?: string; sub_prefix?: string }[];
    rows: Record<string, unknown>[];
    /** A closing row of totals, where the list has one. */
    total?: Record<string, unknown> | null;
}

export interface ReportDashboard {
    kpis: DashboardKpi[];
    charts: DashboardChart[];
    tables: DashboardTable[];
}

/** What each of a table's filters can be set to. */
export type FilterOptions = Record<string, { value: string; label: string }[]>;

export interface ReportRows {
    data: Record<string, unknown>[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        filters?: FilterOptions;
    };
}

interface Window {
    from?: string;
    to?: string;
}

/**
 * `report` is optional: a person holding none of the six capabilities has
 * nothing to ask about, and the page passes `undefined` rather than guessing
 * one — which would otherwise fetch a report they cannot read and fail with a
 * 403 they never caused.
 */
export function useReportSummary(
    storeId: number | undefined,
    report: ReportKey | undefined,
    window: Window,
    /** The reports screen's figures, charts and lists as well — see ReportDashboards. */
    dashboard = false,
) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'report-summary', storeId, report, window, dashboard),
        queryFn: async (): Promise<ReportSummary> => {
            const { data } = await http.get<ApiResponse<ReportSummary>>(
                `/tenant/pharmacy-stores/${storeId}/reports/${report}/summary`,
                { params: { ...window, ...(dashboard ? { dashboard: 1 } : {}) } },
            );

            return data.data;
        },
        enabled: storeId !== undefined && report !== undefined,
        // A new range repaints in place rather than blanking to a spinner.
        placeholderData: keepPreviousData,
    });
}

export function useReportRows(
    storeId: number | undefined,
    report: ReportKey | undefined,
    params: Window & {
        page?: number;
        per_page?: number;
        search?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
    } & Record<string, string | number | undefined>,
    enabled = true,
) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'report-rows', storeId, report, params),
        queryFn: async (): Promise<ReportRows> => {
            const { data } = await http.get<ReportRows>(
                `/tenant/pharmacy-stores/${storeId}/reports/${report}`,
                { params },
            );

            return data;
        },
        enabled: enabled && storeId !== undefined && report !== undefined,
        placeholderData: keepPreviousData,
    });
}

/**
 * The medicine catalogue at a glance.
 *
 * Its own endpoint rather than a report, because the catalogue belongs to the
 * organisation rather than to a counter: the same list is prescribed from at
 * a clinic with no pharmacy at all.
 */
export function useCatalogueOverview(enabled = true) {
    return useQuery({
        queryKey: resourceKey('tenant/medicines', 'overview'),
        queryFn: async (): Promise<ReportSummary> => {
            const { data } = await http.get<ApiResponse<ReportSummary>>('/tenant/medicines/overview');

            return data.data;
        },
        enabled,
    });
}
