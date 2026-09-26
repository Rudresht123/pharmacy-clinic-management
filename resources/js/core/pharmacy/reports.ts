import { useQuery } from '@tanstack/react-query';
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
}

export interface ReportRows {
    data: Record<string, unknown>[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
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
) {
    return useQuery({
        queryKey: resourceKey('tenant/pharmacy', 'report-summary', storeId, report, window),
        queryFn: async (): Promise<ReportSummary> => {
            const { data } = await http.get<ApiResponse<ReportSummary>>(
                `/tenant/pharmacy-stores/${storeId}/reports/${report}/summary`,
                { params: window },
            );

            return data.data;
        },
        enabled: storeId !== undefined && report !== undefined,
    });
}

export function useReportRows(
    storeId: number | undefined,
    report: ReportKey | undefined,
    params: Window & { page?: number; per_page?: number; search?: string },
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
