import { useMemo } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useConfigurableEntities } from '@/core/field-settings/api';
import { useDashboard } from '@/core/dashboard/api';
import { dashboardPanels, Headline } from '@/core/dashboard/panels';
import { branchPanels } from '@/core/dashboard/branch-panels';
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

    /*
     * Two screens, chosen by where this person is standing. A branch dashboard
     * is not a narrower organization one — the questions are different, so the
     * panels are.
     */
    const panels = useMemo(
        () => (data?.context === 'branch' ? branchPanels() : dashboardPanels(labels)),
        [data?.context, labels],
    );

    const rendered = data
        ? panels
              .map((panel) => ({ ...panel, node: panel.render(data) }))
              .filter((panel) => panel.node !== null)
        : [];

    const main = rendered.filter((panel) => panel.column === 'main');
    const rail = rendered.filter((panel) => panel.column === 'rail');
    const full = rendered.filter((panel) => panel.column === 'full');

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
                        {data?.context === 'branch' && data.scope.branch_name
                            ? `Here’s what’s happening at ${data.scope.branch_name}${
                                  data.scope.city ? ` (${data.scope.city})` : ''
                              } today.`
                            : `Here’s what’s happening across ${
                                  organization?.name ?? 'your organization'
                              }.`}
                    </p>
                </div>

                <div className="db-welcome-side">
                    {/*
                        The span every figure below is about. Fixed to this
                        month rather than a picker: nothing on this screen can
                        answer a different range yet, and a control that
                        changes nothing is worse than no control.
                    */}
                    <span className="db-range">
                        <i className="ti ti-calendar" />
                        {new Date().toLocaleDateString(undefined, {
                            month: 'short',
                            year: 'numeric',
                        })}
                    </span>

                    {data?.scope.label && (
                        <span className="db-scope">
                            <i className="ti ti-map-pin" />
                            {data.scope.label}
                        </span>
                    )}
                </div>
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
                                    <div
                                        className="db-slot"
                                        style={{ gridColumn: `span ${panel.span ?? 12}` }}
                                        key={panel.key}
                                    >
                                        {panel.node}
                                    </div>
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

                    {/*
                        Below both columns, edge to edge — one grid, so
                        consecutive panels sit side by side rather than
                        stacking.
                    */}
                    {full.length > 0 && (
                        <div className="db-full">
                            {full.map((panel) => (
                                <div
                                    className="db-slot"
                                    style={{ gridColumn: `span ${panel.span ?? 12}` }}
                                    key={panel.key}
                                >
                                    {panel.node}
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </>
    );
}
