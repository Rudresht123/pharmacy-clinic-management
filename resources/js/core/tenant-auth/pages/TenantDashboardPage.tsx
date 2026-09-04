import { useMemo } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useConfigurableEntities } from '@/core/field-settings/api';
import { useDashboard } from '@/core/dashboard/api';
import { dashboardPanels, Headline } from '@/core/dashboard/panels';
import { useTenantAuth } from '../TenantAuthProvider';

/**
 * The landing page inside an organization's workspace.
 *
 * Assembled from whatever the server sent rather than from a fixed layout:
 * every panel is optional, because a panel the person may not see is never
 * computed and a panel whose module has not shipped does not exist yet. A
 * screen written this way gains a section when a module does, with no release
 * of its own.
 *
 * Nothing here decides what may be seen. The gating happened on the server —
 * see App\Services\Tenant\DashboardSummary — and this only renders what
 * arrived.
 */
export default function TenantDashboardPage() {
    const { user, organization } = useTenantAuth();
    const { data, isLoading, isError, refetch } = useDashboard();
    const { data: entities } = useConfigurableEntities();

    const labels = useMemo(
        () => Object.fromEntries((entities ?? []).map((entity) => [entity.entity, entity.label])),
        [entities],
    );

    const panels = useMemo(() => dashboardPanels(labels), [labels]);

    const rendered = data
        ? panels
              .map((panel) => ({ ...panel, node: panel.render(data) }))
              .filter((panel) => panel.node !== null)
        : [];

    const main = rendered.filter((panel) => panel.column === 'main');
    const rail = rendered.filter((panel) => panel.column === 'rail');

    /** Their first name — a greeting, not a record. */
    const firstName = (user?.name ?? '').split(' ')[0];

    if (isError) {
        return (
            <Card>
                <ErrorState onRetry={() => refetch()} />
            </Card>
        );
    }

    return (
        <>
            <header className="db-welcome">
                <div>
                    <h1>
                        <span aria-hidden="true">👋</span> Welcome back, {firstName}!
                    </h1>
                    <p>
                        Here’s what’s happening across {organization?.name ?? 'your organization'}.
                    </p>
                </div>

                {data?.scope.label && (
                    <span className="db-scope">
                        <i className="ti ti-map-pin" />
                        {data.scope.label}
                    </span>
                )}
            </header>

            {isLoading ? (
                <LoadingBlock label="Loading your dashboard…" />
            ) : (
                <>
                    {data?.headline && data.headline.length > 0 && (
                        <Headline cards={data.headline} />
                    )}

                    {rendered.length === 0 ? (
                        <Card>
                            <div className="org-pending">
                                <i className="ti ti-layout-dashboard" />
                                <h6>Nothing to show you yet</h6>
                                <p>
                                    Your role does not include any of the areas that appear here.
                                    Ask an owner if you were expecting more.
                                </p>
                            </div>
                        </Card>
                    ) : (
                        <div className={`db-layout${rail.length === 0 ? ' is-single' : ''}`}>
                            <div className="db-main">
                                {main.map((panel) => (
                                    <div key={panel.key}>{panel.node}</div>
                                ))}
                            </div>

                            {rail.length > 0 && (
                                <aside className="db-rail">
                                    {rail.map((panel) => (
                                        <div key={panel.key}>{panel.node}</div>
                                    ))}
                                </aside>
                            )}
                        </div>
                    )}
                </>
            )}
        </>
    );
}
