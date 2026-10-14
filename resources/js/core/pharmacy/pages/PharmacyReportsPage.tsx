import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { RangeSelect, defaultRange, rangeComparison, type Range } from '../components/reports/RangeSelect';
import { ReportDashboardView } from '../components/reports/ReportDashboardView';
import { FILTER_KEYS, ReportTableView } from '../components/reports/ReportTableView';
import {
    REPORTS,
    REPORT_BLURBS,
    REPORT_LABELS,
    useReportRows,
    useReportSummary,
    type ReportKey,
} from '../reports';

type View = 'dashboard' | 'table';

/** Reports about the shelf today rather than a span of dates. */
const UNDATED: ReportKey[] = ['stock', 'expiry'];

/**
 * The pharmacy's reports — sales, purchases, stock, expiry, profit and GST —
 * each readable two ways.
 *
 * **Dashboard** is the shape of it: four figures, two charts and two short
 * lists, drawn from App\Services\Pharmacy\Reports\ReportDashboards. **Table**
 * is the evidence, a page at a time, filtered and sorted by whatever that
 * report is about. Both read the same store and dates, so a figure on one is
 * the sum of the other.
 *
 * Which report, which view, which store, which dates, and every filter live
 * in the URL, so a refresh lands where it was and "unpaid sales, last month"
 * is a link somebody can send.
 */
export default function PharmacyReportsPage() {
    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();
    const [params, setParams] = useSearchParams();

    /*
     * Which of the six this person actually holds — its own capability per
     * report (`reports.sales` … `reports.gst`), not one capability for all
     * six. A cashier who may see what sold today has no structural need to
     * see what it cost.
     */
    const { can } = useTenantAuth();
    const allowedReports = REPORTS.filter((key) => can(`reports.${key}`));

    const requested = params.get('report');
    const report = (
        requested && allowedReports.includes(requested as ReportKey) ? requested : allowedReports[0]
    ) as ReportKey | undefined;
    const view: View = params.get('view') === 'table' ? 'table' : 'dashboard';

    const fallback = useMemo(defaultRange, []);
    const range: Range = {
        from: params.get('from') ?? fallback.from,
        to: params.get('to') ?? fallback.to,
    };

    function set(changes: Record<string, string | undefined>) {
        const next = new URLSearchParams(params);

        for (const [key, value] of Object.entries(changes)) {
            if (value === undefined || value === '') {
                next.delete(key);
            } else {
                next.set(key, value);
            }
        }

        setParams(next, { replace: true });
    }

    /** Another report: its own filters, page and sort start afresh. */
    function openReport(key: ReportKey, nextView: View = view) {
        set({
            report: key,
            view: nextView === 'table' ? 'table' : undefined,
            page: undefined,
            q: undefined,
            sort: undefined,
            direction: undefined,
            ...Object.fromEntries(FILTER_KEYS.map((filter) => [filter, undefined])),
        });
    }

    const summary = useReportSummary(store?.id, report, range, true);

    const rowParams = {
        ...range,
        page: Number(params.get('page') ?? 1),
        per_page: Number(params.get('per_page') ?? 25),
        search: params.get('q') || undefined,
        sort: params.get('sort') || undefined,
        direction: (params.get('direction') as 'asc' | 'desc' | null) || undefined,
        ...Object.fromEntries(FILTER_KEYS.map((key) => [key, params.get(key) || undefined])),
    };

    const rows = useReportRows(store?.id, report, rowParams, view === 'table');

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    if (!store) {
        return (
            <Card>
                <NoStores />
            </Card>
        );
    }

    /*
     * Reached only by a direct link, or by a role somebody has since edited —
     * the menu hides "Reports" once nobody holds any of the six. Said plainly
     * rather than defaulting to Sales and letting the request 403.
     */
    if (!report) {
        return (
            <Card>
                <div className="org-pending">
                    <i className="ti ti-report-analytics" />
                    <h6>No reports to show</h6>
                    <p>Your role does not include any of the pharmacy reports.</p>
                </div>
            </Card>
        );
    }

    return (
        <div className="phr-page">
            <PageHeader
                title="Pharmacy Reports"
                subtitle={REPORT_BLURBS[report]}
                icon="ti ti-first-aid-kit"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Reports' }]}
                actions={
                    <div className="phr-head-controls">
                        <StorePicker stores={stores} value={store} onChange={choose} />
                        <RangeSelect
                            value={range}
                            onChange={(next) => set({ from: next.from, to: next.to, page: undefined })}
                            disabled={UNDATED.includes(report)}
                        />
                    </div>
                }
            />

            {/* Which report. Its own row, because it changes everything below. */}
            <nav className="phr-tabs" aria-label="Report">
                {allowedReports.map((key) => (
                    <button
                        type="button"
                        key={key}
                        className={key === report ? 'is-active' : undefined}
                        aria-current={key === report ? 'page' : undefined}
                        onClick={() => openReport(key)}
                    >
                        {REPORT_LABELS[key]}
                    </button>
                ))}
            </nav>

            <div className="phr-views" role="group" aria-label="How to read it">
                <button
                    type="button"
                    className={view === 'dashboard' ? 'is-active' : undefined}
                    aria-pressed={view === 'dashboard'}
                    onClick={() => set({ view: undefined })}
                >
                    <i className="ti ti-chart-bar" aria-hidden="true" />
                    Dashboard
                </button>
                <button
                    type="button"
                    className={view === 'table' ? 'is-active' : undefined}
                    aria-pressed={view === 'table'}
                    onClick={() => set({ view: 'table' })}
                >
                    <i className="ti ti-table" aria-hidden="true" />
                    Table
                </button>
            </div>

            {view === 'table' ? (
                <ReportTableView report={report} rows={rows} params={params} set={set} />
            ) : summary.isError ? (
                <Card>
                    <ErrorState onRetry={() => summary.refetch()} />
                </Card>
            ) : !summary.data?.dashboard ? (
                <LoadingBlock label="Adding it up…" />
            ) : (
                <div className={summary.isFetching && summary.isPlaceholderData ? 'phr-refreshing' : undefined}>
                    <ReportDashboardView
                        dashboard={summary.data.dashboard}
                        compare={rangeComparison(range)}
                        onViewAll={(key) => (allowedReports.includes(key) ? openReport(key, 'table') : set({ view: 'table' }))}
                    />
                </div>
            )}
        </div>
    );
}
