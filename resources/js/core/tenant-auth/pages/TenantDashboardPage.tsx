import { useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useDashboard, type DashboardRange } from '@/core/dashboard/api';
import { OrganizationDashboard } from '@/core/dashboard/organization/OrganizationDashboard';
import { currentMonthWindow } from '@/core/billing/components/BillingWidgets';

/**
 * The landing page inside an organization's workspace.
 *
 * One screen for head office and for a branch: the same cards, about the
 * whole network or about the branch somebody is standing in. Which cards
 * appear is the server's answer, not this page's — a panel the person may
 * not see is never computed (App\Services\Tenant\DashboardSummary), and the
 * screen draws only what arrived.
 */
export default function TenantDashboardPage() {
    // The days the period figures are about — this month until somebody
    // picks another range.
    const [range, setRange] = useState<DashboardRange>(currentMonthWindow);

    const { data, isLoading, isError, isFetching, isPlaceholderData, refetch } = useDashboard(range);

    if (isError) {
        return (
            <Card>
                <ErrorState onRetry={() => refetch()} />
            </Card>
        );
    }

    if (isLoading || !data) {
        return <LoadingBlock label="Loading your dashboard…" />;
    }

    return (
        <OrganizationDashboard
            data={data}
            range={range}
            onRange={setRange}
            refreshing={isFetching && isPlaceholderData}
        />
    );
}
