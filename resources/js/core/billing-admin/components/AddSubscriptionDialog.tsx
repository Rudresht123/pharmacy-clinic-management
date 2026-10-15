import { useState } from 'react';
import { formatPaise, rupeesToPaise } from '@/core/subscription/money';
import { useBillingMutation, useOrganizationOptions, usePlans } from '../api';
import { ActionDialog, FieldError } from './Common';

/**
 * "Add Subscription": put an organization on a plan — a new contract — from
 * the billing screens, without going to the organization first.
 */
export function AddSubscriptionDialog({ onClose }: { onClose: () => void }) {
    const [search, setSearch] = useState('');
    const { data: organizations } = useOrganizationOptions(search);
    const { data: plans } = usePlans();
    const assign = useBillingMutation<Record<string, unknown>>('post', (body) => String(body.__path));
    const [form, setForm] = useState({ organization: '', plan_id: '', billing_cycle: 'monthly', trial: false, price: '', waived: false });

    return (
        <ActionDialog
            title="Add subscription"
            subtitle="Put an organization on a plan. Its live add-ons and overrides carry across."
            icon="ti ti-plus"
            submitLabel="Assign plan"
            onClose={onClose}
            submit={(reason) =>
                assign.mutateAsync({
                    __path: `/organizations/${form.organization}/subscription/assign`,
                    plan_id: Number(form.plan_id),
                    billing_cycle: form.billing_cycle,
                    trial: form.trial,
                    payment_status: form.waived ? 'waived' : 'pending',
                    ...(form.price ? { price: rupeesToPaise(form.price) } : {}),
                    reason,
                })
            }
        >
            {(errors) => (
                <>
                    <div className="mb-3">
                        <label className="form-label">Organization</label>
                        <input className="form-control mb-2" placeholder="Search organizations…" value={search} onChange={(e) => setSearch(e.target.value)} />
                        <select className="form-select" value={form.organization} onChange={(e) => setForm({ ...form, organization: e.target.value })}>
                            <option value="">Choose…</option>
                            {(organizations ?? []).map((org) => (
                                <option key={org.uuid} value={org.uuid}>
                                    {org.organization_name} ({org.organization_code})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="row g-3 mb-3">
                        <div className="col-md-7">
                            <label className="form-label">Plan</label>
                            <select className="form-select" value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })}>
                                <option value="">Choose…</option>
                                {(plans ?? []).filter((p) => p.status !== 'archived').map((plan) => (
                                    <option key={plan.id} value={plan.id}>
                                        {plan.name} — {formatPaise(plan.monthly_price)}/mo
                                    </option>
                                ))}
                            </select>
                            <FieldError errors={errors} name="plan_id" />
                        </div>
                        <div className="col-md-5">
                            <label className="form-label">Billing</label>
                            <select className="form-select" value={form.billing_cycle} onChange={(e) => setForm({ ...form, billing_cycle: e.target.value })}>
                                <option value="monthly">Monthly</option>
                                <option value="annual">Annual</option>
                                <option value="complimentary">Complimentary</option>
                            </select>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Agreed price (₹)</label>
                            <input className="form-control" placeholder="List price" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
                        </div>
                    </div>
                    <div className="sxa-checks mb-3">
                        <label><input type="checkbox" className="form-check-input" checked={form.trial} onChange={(e) => setForm({ ...form, trial: e.target.checked })} /> Start with the plan's trial</label>
                        <label><input type="checkbox" className="form-check-input" checked={form.waived} onChange={(e) => setForm({ ...form, waived: e.target.checked })} /> Payment waived</label>
                    </div>
                </>
            )}
        </ActionDialog>
    );
}
