import { useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { formatPaise } from '@/core/subscription/money';
import { useBillingMutation, usePlans, usePlansSummary, type Plan } from '../api';
import { LABELS } from '../labels';
import { StatusChip } from '../components/Common';
import { PlanEditor } from '../components/PlanEditor';
import { compactPaise, KpiCard, KpiRow } from '../components/Visuals';

/** Plan accents, in order — colour on the name, icon and button, never the card. */
const ACCENTS = ['emerald', 'violet', 'amber', 'sky', 'rose', 'indigo'] as const;

/**
 * The plans on the price list, as cards: price, what is in it, how many
 * organizations are on it. A plan is drafted, published to be sold,
 * unpublished to pause sales, archived for good. Editing a published plan
 * changes new sales and renewals, never a running term.
 */
export default function PlansPage() {
    const { data, isLoading, isError, refetch } = usePlans();
    const { data: summary } = usePlansSummary();
    const [editing, setEditing] = useState<Plan | 'new' | null>(null);
    const [search, setSearch] = useState('');
    const status = useBillingMutation<{ uuid: string; status: string }>('post', (b) => `/plans/${b.uuid}/status`);
    const remove = useBillingMutation<{ uuid: string }>('delete', (b) => `/plans/${b.uuid}`);
    const confirm = useConfirm();

    const plans = (data ?? [])
        .filter((plan) => plan.status !== 'archived' || search)
        .filter((plan) => !search || `${plan.name} ${plan.slug}`.toLowerCase().includes(search.toLowerCase()));

    return (
        <>
            <PageHeader
                title="Plans"
                subtitle="Create and manage subscription plans for hospitals and clinics."
                icon="ti ti-stack-2"
                actions={
                    <div className="d-flex flex-wrap gap-2 align-items-center">
                        <div className="sxa-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input className="form-control" placeholder="Search plans…" value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                        <Button icon="ti ti-plus" onClick={() => setEditing('new')}>Create Plan</Button>
                    </div>
                }
            />

            <KpiRow columns={4}>
                <KpiCard icon="ti ti-stack-2" tone="emerald" label="Total Plans" value={summary?.total_plans ?? 0} hint="On the price list" />
                <KpiCard icon="ti ti-users-group" tone="violet" label="Active Subscriptions" value={summary?.active_subscriptions ?? 0} hint="Not locked" />
                <KpiCard icon="ti ti-currency-rupee" tone="amber" label="Monthly Revenue" value={compactPaise(summary?.monthly_revenue ?? 0)} hint="Recurring, from paying plans" />
                <KpiCard icon="ti ti-chart-pie" tone="sky" label="Avg. Plan Value" value={compactPaise(summary?.average_plan_value ?? 0)} hint="Per paying organization, monthly" />
            </KpiRow>

            {isLoading && <LoadingBlock />}
            {isError && <ErrorState onRetry={() => void refetch()} />}

            {data && plans.length === 0 && (
                <EmptyState icon="ti ti-stack-2" title="No plans yet" description="Create a plan, set its prices and limits, then publish it." />
            )}

            <div className="sxa-plans">
                {plans.map((plan, index) => {
                    const accent = plan.is_system ? 'muted' : ACCENTS[index % ACCENTS.length];
                    const popular = summary?.most_popular_plan_id === plan.id && !plan.is_system;
                    const subscribers = summary?.subscribers[String(plan.id)] ?? plan.subscribers ?? 0;
                    const move = (to: string) => status.mutate({ uuid: plan.uuid, status: to });

                    return (
                        <article key={plan.uuid} className={`sxa-plan ${popular ? 'is-popular' : ''}`} data-accent={accent}>
                            {popular && <span className="sxa-plan-badge">Most Popular</span>}

                            <header>
                                <h5>{plan.name}</h5>
                                <p>{plan.description || (plan.is_system ? 'Internal — existing organizations' : '')}</p>
                            </header>

                            <div className="sxa-plan-price">
                                {plan.is_system ? (
                                    <b>Free</b>
                                ) : (
                                    <>
                                        <b>{formatPaise(plan.monthly_price).replace(/\.00$/, '')}</b>
                                        <span>/month</span>
                                    </>
                                )}
                            </div>

                            <ul>
                                {planPoints(plan).map((line) => (
                                    <li key={line}>
                                        <i className="ti ti-check" aria-hidden="true" />
                                        {line}
                                    </li>
                                ))}
                            </ul>

                            <div className="sxa-plan-meta">
                                <span>
                                    <b>{subscribers}</b> {subscribers === 1 ? 'Subscription' : 'Subscriptions'}
                                </span>
                                <StatusChip status={plan.status} />
                            </div>

                            <div className="sxa-plan-actions">
                                {plan.is_system ? (
                                    <span className="text-muted small">Managed by the platform</span>
                                ) : (
                                    <>
                                        <Button variant="outline" onClick={() => setEditing(plan)}>View Details</Button>
                                        {(plan.status === 'draft' || plan.status === 'unpublished') && (
                                            <Button size="sm" onClick={() => move('published')}>Publish</Button>
                                        )}
                                        {plan.status === 'published' && (
                                            <Button size="sm" variant="light" onClick={() => move('unpublished')}>Unpublish</Button>
                                        )}
                                        {plan.status !== 'archived' && plan.status !== 'draft' && (
                                            <Button
                                                size="sm"
                                                variant="light"
                                                onClick={async () => {
                                                    if (await confirm({ title: `Archive ${plan.name}?`, message: 'It is taken off sale for good. Organizations on it keep their term and renew at the price they agreed.', confirmLabel: 'Archive', danger: true })) {
                                                        move('archived');
                                                    }
                                                }}
                                            >
                                                Archive
                                            </Button>
                                        )}
                                        {plan.status === 'archived' && <Button size="sm" variant="light" onClick={() => move('unpublished')}>Restore</Button>}
                                        {plan.status === 'draft' && !subscribers && (
                                            <Button size="sm" variant="light" icon="ti ti-trash" onClick={() => remove.mutate({ uuid: plan.uuid })} aria-label="Delete draft" />
                                        )}
                                    </>
                                )}
                            </div>
                        </article>
                    );
                })}
            </div>

            {editing && <PlanEditor plan={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </>
    );
}

/** The plan's own highlights, or — when none are written — its limits and contents. */
function planPoints(plan: Plan): string[] {
    if (plan.highlights.length > 0) {
        return plan.highlights.slice(0, 5);
    }

    const show = (key: string, unit: string) => {
        const value = plan.limits[key];

        return value === null ? `Unlimited ${unit}` : `Up to ${value ?? 0} ${unit}`;
    };

    return [
        show('branches', 'branches'),
        show('doctors', 'doctors'),
        show('employees', 'staff'),
        plan.modules.length > 0 ? `${plan.modules.length} module${plan.modules.length === 1 ? '' : 's'} included` : 'Core modules',
        ...plan.features.map((feature) => LABELS[feature] ?? feature),
    ].slice(0, 5);
}
