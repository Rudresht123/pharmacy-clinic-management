import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { LineChart } from '@/shared/components/ui/ReportCharts';
import { formatDay, formatPaise } from '@/core/subscription/money';
import { usePlans, useSubscriptionInsights, useSubscriptions } from '../api';
import { StatusChip } from '../components/Common';
import { AddSubscriptionDialog } from '../components/AddSubscriptionDialog';
import { ChartCard, KpiCard, KpiRow } from '../components/Visuals';

const STATUSES = [
    { value: '', label: 'All Statuses' },
    { value: 'active', label: 'Active' },
    { value: 'trial', label: 'Trial' },
    { value: 'grace', label: 'Grace' },
    { value: 'locked', label: 'Locked' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'cancelled', label: 'Cancelled' },
];

/**
 * Every organization's subscription — growth, the spread across plans, and
 * the list, by EFFECTIVE status (what the clock says now).
 */
export default function SubscriptionsPage() {
    const [params, setParams] = useSearchParams();
    const navigate = useNavigate();
    const [adding, setAdding] = useState(false);
    const [page, setPage] = useState(1);
    const [draft, setDraft] = useState({
        search: params.get('search') ?? '',
        plan_id: params.get('plan_id') ?? '',
        status: params.get('status') ?? '',
    });

    const filters = {
        status: params.get('status') || undefined,
        expiring_within: params.get('expiring_within') || undefined,
        plan_id: params.get('plan_id') || undefined,
        search: params.get('search') || undefined,
        page,
        per_page: 10,
    };

    const { data: insights } = useSubscriptionInsights();
    const { data, isLoading, isFetching } = useSubscriptions(filters);
    const { data: plans } = usePlans();
    const t = insights?.tiles;
    const total = data?.meta.total ?? 0;

    function apply(next: typeof draft, extra: Record<string, string> = {}) {
        setPage(1);
        setParams(Object.fromEntries(Object.entries({ ...next, ...extra }).filter(([, v]) => v)));
    }

    return (
        <>
            <PageHeader
                title="Subscriptions"
                subtitle="Manage all organization subscriptions, renewals and status."
                icon="ti ti-users-group"
                actions={<Button icon="ti ti-plus" onClick={() => setAdding(true)}>Add Subscription</Button>}
            />

            <KpiRow>
                <KpiCard icon="ti ti-circle-check" tone="emerald" label="Active Subscriptions" value={t?.active ?? 0} change={t?.active_change} onClick={() => apply({ ...draft, status: 'active' })} />
                <KpiCard icon="ti ti-clock-exclamation" tone="amber" label="Expiring Soon" value={t?.expiring_soon ?? 0} hint="Next 30 days" onClick={() => apply(draft, { expiring_within: '30' })} />
                <KpiCard icon="ti ti-player-pause" tone="violet" label="Suspended" value={t?.suspended ?? 0} hint={`${t?.grace ?? 0} in grace · ${t?.expired ?? 0} expired`} onClick={() => apply({ ...draft, status: 'suspended' })} />
                <KpiCard icon="ti ti-circle-x" tone="rose" label="Cancelled" value={t?.cancelled ?? 0} onClick={() => apply({ ...draft, status: 'cancelled' })} />
                <KpiCard icon="ti ti-hourglass" tone="sky" label="Trial" value={t?.trial ?? 0} onClick={() => apply({ ...draft, status: 'trial' })} />
            </KpiRow>

            <div className="sxa-split">
                <ChartCard title="Subscription Growth" legend={[{ label: 'Total Subscriptions', color: 'rgb(var(--brand))' }]}>
                    {insights && insights.growth.some((p) => p.value > 0) ? (
                        <LineChart
                            points={insights.growth}
                            series={[{ label: 'Total Subscriptions', color: 'rgb(var(--brand))', values: insights.growth.map((p) => p.value), area: true }]}
                            format={(v) => `${v} subscriptions`}
                            axis={(v) => String(Math.round(v))}
                        />
                    ) : (
                        <div className="donut-empty"><i className="ti ti-chart-line" aria-hidden="true" /><p>No subscriptions yet.</p></div>
                    )}
                </ChartCard>

                <ChartCard title="Plan Wise Distribution">
                    <DonutChart slices={insights?.plan_distribution ?? []} centreLabel="Total" empty="No subscriptions yet." />
                </ChartCard>
            </div>

            <Card>
                <form
                    className="sxa-filters"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply(draft);
                    }}
                >
                    <div className="sxa-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input className="form-control" placeholder="Search organization…" value={draft.search} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </div>
                    <select className="form-select" value={draft.plan_id} onChange={(e) => setDraft({ ...draft, plan_id: e.target.value })}>
                        <option value="">All Plans</option>
                        {(plans ?? []).map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
                    </select>
                    <select className="form-select" value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                        {STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                    <Button type="submit" icon="ti ti-filter">Filter</Button>
                </form>

                <div className={`sxa-table ${isFetching ? 'is-fetching' : ''}`}>
                    <table className="table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Organization</th>
                                <th>Plan</th>
                                <th>Start Date</th>
                                <th>Renewal Date</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(data?.rows ?? []).map((row, i) => (
                                <tr key={row.uuid}>
                                    <td>{(page - 1) * 10 + i + 1}</td>
                                    <td>
                                        <b>{row.organization?.name ?? '—'}</b>
                                        <div className="dt-muted">{row.organization?.code}</div>
                                    </td>
                                    <td>{row.plan.name ?? '—'}</td>
                                    <td>{formatDay(row.started_at)}</td>
                                    <td>{row.next_renewal_at ? formatDay(row.next_renewal_at) : row.expires_at ? formatDay(row.expires_at) : '—'}</td>
                                    <td>
                                        <StatusChip
                                            status={row.locked ? 'expired' : row.effective_status}
                                            label={
                                                row.effective_status === 'active' && row.days_remaining !== null && row.days_remaining <= 30
                                                    ? 'Expiring soon'
                                                    : row.billing_cycle === 'complimentary'
                                                      ? 'Complimentary'
                                                      : undefined
                                            }
                                        />
                                    </td>
                                    <td>{row.billing_cycle === 'complimentary' ? '—' : formatPaise(row.price, row.currency)}</td>
                                    <td>
                                        <Link className="sxa-link" to={`/billing/organizations/${row.organization?.uuid}`}>View</Link>
                                    </td>
                                </tr>
                            ))}
                            {!isLoading && (data?.rows.length ?? 0) === 0 && (
                                <tr><td colSpan={8} className="text-center text-muted py-4">No subscription matches.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="sxa-pager">
                    <span>{total === 0 ? 'No results' : `Showing ${(page - 1) * 10 + 1}–${Math.min(page * 10, total)} of ${total}`}</span>
                    <div className="d-flex gap-2">
                        <Button size="sm" variant="light" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                        <Button size="sm" variant="light" disabled={page * 10 >= total} onClick={() => setPage(page + 1)}>Next</Button>
                    </div>
                </div>
            </Card>

            {adding && <AddSubscriptionDialog onClose={() => { setAdding(false); navigate('/billing/subscriptions'); }} />}
        </>
    );
}
