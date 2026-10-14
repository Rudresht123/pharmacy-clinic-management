import { useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { currentMonthWindow, DateRangePicker } from '@/core/billing/components/BillingWidgets';
import type { DashboardRange } from '../api';
import { usePlatformDashboard } from '../platform-api';
import { PlatformDashboard } from '../platform/PlatformDashboard';

/**
 * The super admin's landing page.
 *
 * The range drives the period figures — growth against the period before,
 * organizations joined in it. Plan, status and module counts are as they
 * stand now, whatever the range.
 */
export default function DashboardPage() {
    const [range, setRange] = useState<DashboardRange>(currentMonthWindow);

    const { data, isLoading, isError, isFetching, isPlaceholderData, refetch } = usePlatformDashboard(range);

    return (
        <div className={`od-page sa-page${isFetching && isPlaceholderData ? ' is-refreshing' : ''}`}>
            <header className="od-greeting">
                <div>
                    <h1>Super Admin Dashboard</h1>
                    <p>Overview of your platform, organizations, subscriptions and revenue.</p>
                </div>

                <DateRangePicker value={range} onChange={setRange} />
            </header>

            {isError ? (
                <Card>
                    <ErrorState onRetry={() => refetch()} />
                </Card>
            ) : isLoading || !data ? (
                <LoadingBlock label="Loading the dashboard…" />
            ) : (
                <PlatformDashboard data={data} />
            )}
        </div>
    );
}
