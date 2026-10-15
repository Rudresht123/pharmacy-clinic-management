import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { ErrorState } from '@/shared/components/ui/Feedback';
import { formatDay, formatPaise } from '@/core/subscription/money';
import { useOverview } from '../api';
import { StatusChip } from '../components/Common';
import { AddSubscriptionDialog } from '../components/AddSubscriptionDialog';
import { ChartCard, compactPaise, GroupedBars, KpiCard, KpiRow, monthBounds, RangePicker } from '../components/Visuals';

const REVENUE = [
    { label: 'Invoiced', color: 'rgb(var(--brand))' },
    { label: 'Collected', color: 'rgba(var(--brand), 0.35)' },
];

/**
 * Billing at a glance for a date range: revenue, subscriptions, invoices,
 * what is owed and what is overdue — each against the previous period —
 * then the revenue trend, revenue by plan, the latest invoices and the
 * subscriptions by status. Every figure is from the records.
 */
export default function BillingOverviewPage() {
    const [range, setRange] = useState(monthBounds);
    const [adding, setAdding] = useState(false);
    const { data, isError, refetch } = useOverview(range);
    const navigate = useNavigate();
    const k = data?.kpis;
    const status = data?.subscription_status;

    return (
        <>
            <PageHeader
                title="Billing Overview"
                subtitle="Track your revenue, subscriptions, invoices and payments across all organizations."
                icon="ti ti-crown"
                actions={
                    <div className="d-flex flex-wrap gap-2 align-items-center">
                        <RangePicker from={range.from} to={range.to} onChange={(from, to) => setRange({ from, to })} />
                        <Button icon="ti ti-plus" onClick={() => setAdding(true)}>Add Subscription</Button>
                    </div>
                }
            />

            {isError && <ErrorState onRetry={() => void refetch()} />}

            <KpiRow>
                <KpiCard icon="ti ti-currency-rupee" tone="emerald" label="Total Revenue" value={formatPaise(k?.total_revenue.value ?? 0)} change={k?.total_revenue.change} hint="Received in this period" />
                <KpiCard icon="ti ti-users-group" tone="violet" label="Active Subscriptions" value={k?.active_subscriptions.value ?? 0} change={k?.active_subscriptions.change} onClick={() => navigate('/billing/subscriptions')} />
                <KpiCard icon="ti ti-file-invoice" tone="sky" label="Total Invoices" value={k?.total_invoices.value ?? 0} change={k?.total_invoices.change} hint="Issued in this period" onClick={() => navigate('/billing/invoices')} />
                <KpiCard icon="ti ti-clock-dollar" tone="amber" label="Pending Payments" value={formatPaise(k?.pending_payments.value ?? 0)} hint={`${k?.pending_payments.count ?? 0} unpaid invoice(s)`} onClick={() => navigate('/billing/invoices?status=pending')} />
                <KpiCard icon="ti ti-alert-octagon" tone="rose" label="Overdue Invoices" value={k?.overdue_invoices.value ?? 0} hint={formatPaise(k?.overdue_invoices.amount ?? 0)} onClick={() => navigate('/billing/invoices?status=overdue')} />
            </KpiRow>

            <div className="sxa-split">
                <ChartCard title="Revenue Trend" legend={REVENUE}>
                    <GroupedBars points={data?.revenue_trend ?? []} series={REVENUE} format={(v) => formatPaise(v)} axis={compactPaise} empty="No invoices or payments in the last six months." />
                </ChartCard>

                <ChartCard title="Revenue by Plan Type">
                    <DonutChart
                        slices={data?.revenue_by_plan ?? []}
                        centreLabel="Total Revenue"
                        format={compactPaise}
                        empty="No payments received in this period."
                    />
                </ChartCard>
            </div>

            <div className="sxa-split">
                <Card className="sxa-chart">
                    <div className="sxa-chart-head">
                        <h6>Recent Invoices</h6>
                        <Link to="/billing/invoices" className="sxa-link">View All</Link>
                    </div>
                    <div className="sxa-table">
                        <table className="table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Invoice ID</th>
                                    <th>Organization</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(data?.recent_invoices ?? []).map((invoice, i) => (
                                    <tr key={invoice.uuid}>
                                        <td>{i + 1}</td>
                                        <td><b>{invoice.number ?? '—'}</b></td>
                                        <td>{invoice.organization?.name ?? invoice.bill_to ?? '—'}</td>
                                        <td>{formatPaise(invoice.total, invoice.currency)}</td>
                                        <td>{formatDay(invoice.issued_at)}</td>
                                        <td><StatusChip status={invoice.status} /></td>
                                        <td>
                                            <Link className="sxa-link" to={`/billing/invoices?search=${encodeURIComponent(invoice.number ?? '')}`}>View</Link>
                                        </td>
                                    </tr>
                                ))}
                                {data && data.recent_invoices.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-center text-muted py-4">No invoices yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <ChartCard title="Subscription Status" actions={<Link to="/billing/subscriptions" className="sxa-link">View All</Link>}>
                    <DonutChart
                        centreLabel="Total"
                        empty="No subscriptions yet."
                        slices={
                            status
                                ? [
                                      { label: 'Active', value: status.active },
                                      { label: 'Expiring Soon', value: status.expiring_soon },
                                      { label: 'Trial', value: status.trial },
                                      { label: 'Grace', value: status.grace },
                                      { label: 'Suspended', value: status.suspended },
                                      { label: 'Expired', value: status.expired },
                                      { label: 'Cancelled', value: status.cancelled },
                                      { label: 'Complimentary', value: status.complimentary },
                                  ]
                                : []
                        }
                    />
                </ChartCard>
            </div>

            {adding && <AddSubscriptionDialog onClose={() => setAdding(false)} />}
        </>
    );
}
