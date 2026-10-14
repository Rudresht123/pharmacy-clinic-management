import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useReportSummary, type ReportKey } from '../../reports';
import { defaultRange, rangeComparison } from './RangeSelect';
import { ReportDashboardView } from './ReportDashboardView';

/**
 * One report's dashboard on a pharmacy screen of its own — Stock, Bills,
 * Goods received — the same view the Reports page draws for that report, so
 * the pharmacy reads one way wherever somebody opens it.
 *
 * Over this month, as the Reports page opens on. "View all" on a list about
 * this screen's own report opens this screen's table; one about another
 * report opens that report's table on the Reports page.
 */
export function StoreReportDashboard({
    storeId,
    report,
    onShowTable,
}: {
    storeId: number;
    report: ReportKey;
    onShowTable: () => void;
}) {
    const navigate = useNavigate();
    const range = useMemo(defaultRange, []);

    const summary = useReportSummary(storeId, report, range, true);

    if (summary.isError) {
        return <ErrorState onRetry={() => summary.refetch()} />;
    }

    if (!summary.data?.dashboard) {
        return <LoadingBlock label="Adding it up…" />;
    }

    return (
        <div className={summary.isFetching && summary.isPlaceholderData ? 'phr-refreshing' : undefined}>
            <ReportDashboardView
                dashboard={summary.data.dashboard}
                compare={rangeComparison(range)}
                onViewAll={(key) =>
                    key === report
                        ? onShowTable()
                        : navigate(`/pharmacy/reports?store=${storeId}&report=${key}&view=table`)
                }
            />
        </div>
    );
}
