import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { formatDay, formatPaise, rupeesToPaise } from '@/core/subscription/money';
import { SubscriptionStatusCard } from '@/core/subscription/components/SubscriptionStatusCard';
import { useBillingMutation, useOrganizationSubscription, usePlans } from '../api';
import { FEATURE_KEYS, LABELS, QUOTA_KEYS, RESOURCE_KEYS } from '../labels';
import { ActionDialog, FieldError, StatusChip } from '../components/Common';
import { EventList } from '../components/EventList';

type Lever =
    | 'assign'
    | 'change'
    | 'trial'
    | 'extend'
    | 'suspend'
    | 'unlock'
    | 'cancel'
    | 'addon'
    | 'credits'
    | 'limit'
    | 'feature'
    | 'invoice'
    | { remove: number }
    | { pay: { uuid: string; number: string | null; total: number } }
    | { void: { uuid: string; number: string | null } };

/**
 * One organization's subscription, and every lever on it. Each lever asks
 * why, and the answer is written to the subscription's history with the
 * administrator's name — the same history shown at the bottom of this page.
 *
 * Nothing here touches the organization's own (security) status; that stays
 * on the organization screen, and paying never lifts it.
 */
export default function OrganizationSubscriptionPage() {
    const { uuid = '' } = useParams();
    const { data, isLoading, isError, refetch } = useOrganizationSubscription(uuid);
    const [lever, setLever] = useState<Lever | null>(null);
    const close = () => setLever(null);

    if (isLoading) {
        return <LoadingBlock />;
    }

    if (isError || !data) {
        return <ErrorState onRetry={() => void refetch()} />;
    }

    const sub = data.subscription;
    const comp = data.status.is_complimentary;

    return (
        <>
            <PageHeader
                title={data.organization.name}
                subtitle="Subscription"
                icon="ti ti-crown"
                crumbs={[
                    { label: 'Billing', to: '/billing' },
                    { label: 'Subscriptions', to: '/billing/subscriptions' },
                    { label: data.organization.name },
                ]}
                actions={
                    <Link to={`/organizations/${data.organization.uuid}`} className="btn btn-light">
                        Organization
                    </Link>
                }
            />

            {data.organization.status === 'suspended' && (
                <div className="sx-banner sx-banner--grace">
                    <i className="ti ti-shield-lock" aria-hidden="true" />
                    <span>
                        The organization itself is suspended{data.organization.suspension_reason ? `: ${data.organization.suspension_reason}` : ''}. That is
                        separate from billing — renewing does not lift it.
                    </span>
                </div>
            )}

            <SubscriptionStatusCard status={{ ...data.status, can_manage: false }} showActions={false} />

            <Card title="Levers" icon="ti ti-adjustments" className="mb-4">
                <div className="sxa-actions">
                    <Button onClick={() => setLever('assign')} icon="ti ti-replace">Assign plan</Button>
                    {!comp && <Button variant="light" onClick={() => setLever('change')} icon="ti ti-arrows-exchange">Change plan</Button>}
                    <Button variant="light" onClick={() => setLever('trial')} icon="ti ti-hourglass">Start trial</Button>
                    {!comp && <Button variant="light" onClick={() => setLever('extend')} icon="ti ti-calendar-plus">Extend</Button>}
                    {!comp && <Button variant="light" onClick={() => setLever('invoice')} icon="ti ti-file-invoice">Issue renewal invoice</Button>}
                    <Button variant="light" onClick={() => setLever('addon')} icon="ti ti-plus">Enable add-on</Button>
                    <Button variant="light" onClick={() => setLever('credits')} icon="ti ti-coins">Grant credits</Button>
                    <Button variant="light" onClick={() => setLever('limit')} icon="ti ti-adjustments-horizontal">Override a limit</Button>
                    <Button variant="light" onClick={() => setLever('feature')} icon="ti ti-sparkles">Grant a feature</Button>
                    {sub?.suspended_at ? (
                        <Button variant="light" onClick={() => setLever('unlock')} icon="ti ti-lock-open">Lift suspension</Button>
                    ) : (
                        <Button variant="danger" onClick={() => setLever('suspend')} icon="ti ti-lock">Suspend</Button>
                    )}
                    {!sub?.cancelled_at && <Button variant="danger" onClick={() => setLever('cancel')} icon="ti ti-x">Cancel</Button>}
                </div>
            </Card>

            <div className="sx-grid mb-4">
                <Card title="Capacity" icon="ti ti-building-hospital" className="sx-card">
                    {data.resources ? (
                        <ul className="sx-list">
                            {Object.values(data.resources).map((row) => (
                                <li key={row.key} className={row.over_limit ? '' : ''}>
                                    <i className={row.over_limit ? 'ti ti-alert-triangle' : 'ti ti-users'} aria-hidden="true" />
                                    <span>{row.label}</span>
                                    <small>
                                        {row.used} / {row.limit === null ? 'unlimited' : row.limit}
                                    </small>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="sx-empty">The workspace could not be counted right now (still provisioning, or unreachable).</p>
                    )}
                </Card>

                <Card title="This month's usage" icon="ti ti-chart-donut" className="sx-card">
                    <ul className="sx-list">
                        {Object.values(data.usage).map((row) => (
                            <li key={row.key}>
                                <i className="ti ti-message" aria-hidden="true" />
                                <span>{row.label}</span>
                                <small>
                                    {row.used} / {row.allowance === null ? 'unlimited' : row.allowance}
                                    {row.granted > 0 ? ` (+${row.granted} granted)` : ''}
                                    {row.overage > 0 ? ` · ${formatPaise(row.overage_charge)} overage` : ''}
                                </small>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            <div className="sx-grid mb-4">
                <Card title="Modules" icon="ti ti-puzzle" className="sx-card">
                    <ul className="sx-list">
                        {data.modules.filter((m) => !m.is_core).map((module) => (
                            <li key={module.key} className={module.is_live ? '' : 'is-off'}>
                                <i className={module.is_live ? 'ti ti-circle-check' : 'ti ti-circle-dashed'} aria-hidden="true" />
                                <span>{module.name}</span>
                                <small>
                                    {{ plan: 'From plan', addon: 'Add-on', manual_grant: 'Manual grant', manual_revoke: 'Manually revoked', off: 'Off' }[module.source] ?? module.source}
                                </small>
                            </li>
                        ))}
                    </ul>
                    <small className="text-muted">
                        Manual grants and revokes are set on the organization's Modules tab and override the plan, both ways.
                    </small>
                </Card>

                <Card title="Add-ons & overrides" icon="ti ti-plus" className="sx-card">
                    {data.items.length === 0 ? (
                        <p className="sx-empty">Nothing beyond the plan.</p>
                    ) : (
                        <ul className="sx-list">
                            {data.items.map((item) => (
                                <li key={item.id} className={item.is_live ? '' : 'is-off'}>
                                    <i className={item.type === 'override' ? 'ti ti-adjustments' : 'ti ti-package'} aria-hidden="true" />
                                    <span>
                                        {item.type === 'override'
                                            ? `${item.target_label}: ${item.grants === null ? 'unlimited' : (item.grants ?? 'on')}`
                                            : `${item.ref}${item.quantity > 1 ? ` × ${item.quantity}` : ''}`}
                                        {item.note && <small className="d-block text-muted">{item.note}</small>}
                                    </span>
                                    <small>{item.is_live ? (item.ends_at ? `until ${formatDay(item.ends_at)}` : 'live') : item.status}</small>
                                    {item.is_live && (
                                        <Button size="sm" variant="light" onClick={() => setLever({ remove: item.id })}>
                                            Remove
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="sx-grid mb-4">
                <Card title="Invoices" icon="ti ti-receipt" className="sx-card">
                    {data.invoices.length === 0 ? (
                        <p className="sx-empty">No invoices yet.</p>
                    ) : (
                        <ul className="sx-list">
                            {data.invoices.map((invoice) => (
                                <li key={invoice.uuid}>
                                    <i className="ti ti-file-invoice" aria-hidden="true" />
                                    <span>
                                        <b>{invoice.number ?? 'Draft'}</b> · {invoice.kind} · {formatPaise(invoice.total, invoice.currency)}
                                        <small className="d-block text-muted">
                                            Issued {formatDay(invoice.issued_at)}
                                            {invoice.paid_at ? ` · paid ${formatDay(invoice.paid_at)}` : invoice.due_at ? ` · due ${formatDay(invoice.due_at)}` : ''}
                                        </small>
                                    </span>
                                    <StatusChip status={invoice.status} />
                                    {invoice.status === 'issued' && (
                                        <>
                                            <Button size="sm" onClick={() => setLever({ pay: { uuid: invoice.uuid, number: invoice.number, total: invoice.total } })}>
                                                Record payment
                                            </Button>
                                            <Button size="sm" variant="light" onClick={() => setLever({ void: { uuid: invoice.uuid, number: invoice.number } })}>
                                                Void
                                            </Button>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Reminders" icon="ti ti-mail" className="sx-card">
                    {data.reminders.length === 0 ? (
                        <p className="sx-empty">No reminders sent yet.</p>
                    ) : (
                        <ul className="sx-list">
                            {data.reminders.map((reminder, i) => (
                                <li key={i} className={reminder.status === 'sent' ? '' : 'is-off'}>
                                    <i className={reminder.status === 'failed' ? 'ti ti-mail-x' : 'ti ti-mail-check'} aria-hidden="true" />
                                    <span>
                                        {reminder.kind.replace('_', ' ')} · {reminder.channel}
                                        {reminder.error && <small className="d-block text-muted">{reminder.error}</small>}
                                    </span>
                                    <small>{reminder.status}</small>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <Card title="History" icon="ti ti-history">
                <EventList events={data.events} />
            </Card>

            {lever && <LeverDialog lever={lever} uuid={uuid} currentCycle={sub?.billing_cycle ?? 'monthly'} onClose={close} />}
        </>
    );
}

function LeverDialog({ lever, uuid, currentCycle, onClose }: { lever: Lever; uuid: string; currentCycle: string; onClose: () => void }) {
    const { data: plans } = usePlans();
    const base = `/organizations/${uuid}/subscription`;
    const [form, setForm] = useState<Record<string, string | boolean>>({
        plan_id: '',
        billing_cycle: currentCycle === 'annual' ? 'annual' : 'monthly',
        trial: false,
        immediately: true,
        days: '30',
        until: '',
        type: 'addon',
        ref: '',
        quantity: '1',
        free: false,
        quota: 'whatsapp_messages',
        amount: '100',
        key: 'doctors',
        value: '',
        feature: 'video_consultation',
        price: '',
        reference: '',
    });
    const set = (key: string, value: string | boolean) => setForm({ ...form, [key]: value });

    const post = useBillingMutation<Record<string, unknown>>('post', (b) => String(b.__path));
    const del = useBillingMutation<Record<string, unknown>>('delete', (b) => String(b.__path));
    const send = (path: string, body: Record<string, unknown>, method: 'post' | 'delete' = 'post') =>
        (method === 'post' ? post : del).mutateAsync({ ...body, __path: path });

    const sellable = (plans ?? []).filter((p) => p.status !== 'archived');
    const planSelect = (errors: Record<string, string[]>, includeSystem = true) => (
        <div className="mb-3">
            <label className="form-label">Plan</label>
            <select className="form-select" value={String(form.plan_id)} onChange={(e) => set('plan_id', e.target.value)}>
                <option value="">Choose…</option>
                {sellable.filter((p) => includeSystem || !p.is_system).map((plan) => (
                    <option key={plan.id} value={plan.id}>
                        {plan.name} — {formatPaise(plan.monthly_price)}/mo · {formatPaise(plan.annual_price)}/yr ({plan.status})
                    </option>
                ))}
            </select>
            <FieldError errors={errors} name="plan_id" />
        </div>
    );
    const cycleSelect = (allowComplimentary: boolean) => (
        <div className="mb-3">
            <label className="form-label">Billing</label>
            <select className="form-select" value={String(form.billing_cycle)} onChange={(e) => set('billing_cycle', e.target.value)}>
                <option value="monthly">Monthly</option>
                <option value="annual">Annual</option>
                {allowComplimentary && <option value="complimentary">Complimentary (no end, no charge)</option>}
            </select>
        </div>
    );

    if (typeof lever === 'object' && 'remove' in lever) {
        return (
            <ActionDialog title="Remove this add-on or override" tone="danger" submitLabel="Remove" onClose={onClose}
                submit={(reason) => send(`${base}/items/${lever.remove}`, { reason }, 'delete')} />
        );
    }

    if (typeof lever === 'object' && 'pay' in lever) {
        return (
            <ActionDialog
                title={`Record payment for ${lever.pay.number}`}
                subtitle="Money received by bank transfer, UPI or cheque. The invoice is paid and what it was for happens — a renewal unlocks the organization."
                icon="ti ti-cash"
                askReason={false}
                submitLabel="Record payment"
                onClose={onClose}
                submit={() => send(`/invoices/${lever.pay.uuid}/payments`, { amount: rupeesToPaise(String(form.price || lever.pay.total / 100)) ?? -1, reference: form.reference })}
            >
                {(errors) => (
                    <>
                        <div className="mb-3">
                            <label className="form-label">Amount received (₹)</label>
                            <input className="form-control" placeholder={String(lever.pay.total / 100)} value={String(form.price)} onChange={(e) => set('price', e.target.value)} />
                            <small className="text-muted">The full invoice amount: {formatPaise(lever.pay.total)}.</small>
                            <FieldError errors={errors} name="amount" />
                            <FieldError errors={errors} name="method" />
                        </div>
                        <div className="mb-3">
                            <label className="form-label">Reference (UTR / cheque no.)</label>
                            <input className="form-control" value={String(form.reference)} onChange={(e) => set('reference', e.target.value)} />
                            <FieldError errors={errors} name="reference" />
                        </div>
                    </>
                )}
            </ActionDialog>
        );
    }

    if (typeof lever === 'object' && 'void' in lever) {
        return (
            <ActionDialog title={`Void ${lever.void.number}`} tone="danger" submitLabel="Void invoice" onClose={onClose}
                submit={(reason) => send(`/invoices/${lever.void.uuid}/void`, { reason })} />
        );
    }

    switch (lever) {
        case 'assign':
            return (
                <ActionDialog title="Assign a plan" subtitle="A new contract. Live add-ons and overrides carry across." icon="ti ti-replace" submitLabel="Assign" onClose={onClose}
                    submit={(reason) => send(`${base}/assign`, {
                        plan_id: Number(form.plan_id), billing_cycle: form.billing_cycle, trial: form.trial, reason,
                        ...(form.until ? { expires_at: form.until } : {}),
                        ...(form.price ? { price: rupeesToPaise(String(form.price)) } : {}),
                        payment_status: form.free ? 'waived' : 'pending',
                    })}>
                    {(errors) => (
                        <>
                            {planSelect(errors)}
                            {cycleSelect(true)}
                            <div className="row g-3 mb-3">
                                <div className="col-md-6">
                                    <label className="form-label">Custom end date</label>
                                    <input type="date" className="form-control" value={String(form.until)} onChange={(e) => set('until', e.target.value)} />
                                    <small className="text-muted">Blank: one full term from today.</small>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label">Agreed price (₹)</label>
                                    <input className="form-control" placeholder="List price" value={String(form.price)} onChange={(e) => set('price', e.target.value)} />
                                </div>
                            </div>
                            <div className="sxa-checks mb-3">
                                <label><input type="checkbox" className="form-check-input" checked={Boolean(form.trial)} onChange={(e) => set('trial', e.target.checked)} /> Start with the plan's trial</label>
                                <label><input type="checkbox" className="form-check-input" checked={Boolean(form.free)} onChange={(e) => set('free', e.target.checked)} /> Payment waived</label>
                            </div>
                        </>
                    )}
                </ActionDialog>
            );
        case 'change':
            return (
                <ActionDialog title="Change plan" subtitle="Now (upgrade or correction) or from the next term (downgrade)." icon="ti ti-arrows-exchange" submitLabel="Change" onClose={onClose}
                    submit={(reason) => send(`${base}/change-plan`, { plan_id: Number(form.plan_id), billing_cycle: form.billing_cycle, immediately: form.immediately, reason })}>
                    {(errors) => (
                        <>
                            {planSelect(errors, false)}
                            {cycleSelect(false)}
                            <div className="sxa-checks mb-3">
                                <label><input type="radio" name="when" checked={Boolean(form.immediately)} onChange={() => set('immediately', true)} /> Now</label>
                                <label><input type="radio" name="when" checked={!form.immediately} onChange={() => set('immediately', false)} /> At the end of this term</label>
                            </div>
                        </>
                    )}
                </ActionDialog>
            );
        case 'trial':
            return (
                <ActionDialog title="Start a trial" subtitle="A new trial contract, for the plan's trial length." icon="ti ti-hourglass" submitLabel="Start trial" onClose={onClose}
                    submit={(reason) => send(`${base}/trial`, { plan_id: Number(form.plan_id), reason })}>
                    {(errors) => planSelect(errors, false)}
                </ActionDialog>
            );
        case 'extend':
            return (
                <ActionDialog title="Extend the term" subtitle="Moves the end date. An expiry lock lifts if the new date is ahead." icon="ti ti-calendar-plus" submitLabel="Extend" onClose={onClose}
                    submit={(reason) => send(`${base}/extend`, form.until ? { until: form.until, reason } : { days: Number(form.days), reason })}>
                    {(errors) => (
                        <div className="row g-3 mb-3">
                            <div className="col-md-6">
                                <label className="form-label">By days</label>
                                <input type="number" min={1} className="form-control" value={String(form.days)} onChange={(e) => set('days', e.target.value)} />
                                <FieldError errors={errors} name="days" />
                            </div>
                            <div className="col-md-6">
                                <label className="form-label">Or to a date</label>
                                <input type="date" className="form-control" value={String(form.until)} onChange={(e) => set('until', e.target.value)} />
                                <FieldError errors={errors} name="until" />
                            </div>
                        </div>
                    )}
                </ActionDialog>
            );
        case 'suspend':
            return <ActionDialog title="Suspend billing" subtitle="Locks the organization now, until you lift it. Renewal does not lift it." tone="danger" icon="ti ti-lock" submitLabel="Suspend" onClose={onClose} submit={(reason) => send(`${base}/suspend`, { reason })} />;
        case 'unlock':
            return <ActionDialog title="Lift the suspension" subtitle="If the term has also ended, the organization stays locked for that — extend it or record a payment." icon="ti ti-lock-open" submitLabel="Lift" onClose={onClose} submit={(reason) => send(`${base}/unlock`, { reason })} />;
        case 'cancel':
            return (
                <ActionDialog title="Cancel the subscription" tone="danger" icon="ti ti-x" submitLabel="Cancel subscription" onClose={onClose}
                    submit={(reason) => send(`${base}/cancel`, { immediately: form.immediately === false ? false : Boolean(form.immediately), reason })}>
                    {() => (
                        <div className="sxa-checks mb-3">
                            <label><input type="radio" name="cancel" checked={!form.immediately} onChange={() => set('immediately', false)} /> At the end of the term</label>
                            <label><input type="radio" name="cancel" checked={Boolean(form.immediately)} onChange={() => set('immediately', true)} /> Now — locks immediately</label>
                        </div>
                    )}
                </ActionDialog>
            );
        case 'invoice':
            return <ActionDialog title="Issue the renewal invoice" subtitle="For the next term, at the current list price. Returns the open one if it already exists." icon="ti ti-file-invoice" askReason={false} submitLabel="Issue" onClose={onClose} submit={() => send(`${base}/invoices`, {})} />;
        case 'addon':
            return (
                <ActionDialog title="Enable an add-on" subtitle="At list price, or free as a goodwill grant." icon="ti ti-plus" submitLabel="Enable" onClose={onClose}
                    submit={(reason) => send(`${base}/addons`, { type: form.type, ref: form.ref, quantity: Number(form.quantity) || 1, ...(form.free ? { unit_price: 0 } : {}), reason })}>
                    {(errors) => (
                        <div className="row g-3 mb-3">
                            <div className="col-md-4">
                                <label className="form-label">Type</label>
                                <select className="form-select" value={String(form.type)} onChange={(e) => set('type', e.target.value)}>
                                    <option value="addon">Add-on</option>
                                    <option value="module">Module</option>
                                </select>
                            </div>
                            <div className="col-md-5">
                                <label className="form-label">{form.type === 'module' ? 'Module key' : 'Add-on key'}</label>
                                <input className="form-control" value={String(form.ref)} placeholder={form.type === 'module' ? 'laboratory' : 'extra_doctor'} onChange={(e) => set('ref', e.target.value)} />
                                <FieldError errors={errors} name="ref" />
                            </div>
                            <div className="col-md-3">
                                <label className="form-label">Quantity</label>
                                <input type="number" min={1} className="form-control" value={String(form.quantity)} onChange={(e) => set('quantity', e.target.value)} />
                            </div>
                            <div className="col-12 sxa-checks">
                                <label><input type="checkbox" className="form-check-input" checked={Boolean(form.free)} onChange={(e) => set('free', e.target.checked)} /> Free (goodwill)</label>
                            </div>
                        </div>
                    )}
                </ActionDialog>
            );
        case 'credits':
            return (
                <ActionDialog title="Grant credits" subtitle="Added to this month's allowance, at no charge." icon="ti ti-coins" submitLabel="Grant" onClose={onClose}
                    submit={(reason) => send(`${base}/credits`, { quota: form.quota, amount: Number(form.amount), reason })}>
                    {(errors) => (
                        <div className="row g-3 mb-3">
                            <div className="col-md-7">
                                <label className="form-label">Allowance</label>
                                <select className="form-select" value={String(form.quota)} onChange={(e) => set('quota', e.target.value)}>
                                    {QUOTA_KEYS.map((key) => <option key={key} value={key}>{LABELS[key]}</option>)}
                                </select>
                            </div>
                            <div className="col-md-5">
                                <label className="form-label">Amount</label>
                                <input type="number" min={1} className="form-control" value={String(form.amount)} onChange={(e) => set('amount', e.target.value)} />
                                <FieldError errors={errors} name="amount" />
                            </div>
                        </div>
                    )}
                </ActionDialog>
            );
        case 'limit':
            return (
                <ActionDialog title="Override a limit" subtitle="Sets the limit for this organization regardless of plan. Blank is unlimited." icon="ti ti-adjustments-horizontal" submitLabel="Override" onClose={onClose}
                    submit={(reason) => send(`${base}/limits`, { key: form.key, value: String(form.value).trim() === '' ? null : Number(form.value), ...(form.until ? { until: form.until } : {}), reason })}>
                    {(errors) => (
                        <div className="row g-3 mb-3">
                            <div className="col-md-4">
                                <label className="form-label">Limit</label>
                                <select className="form-select" value={String(form.key)} onChange={(e) => set('key', e.target.value)}>
                                    {RESOURCE_KEYS.map((key) => <option key={key} value={key}>{LABELS[key]}</option>)}
                                </select>
                            </div>
                            <div className="col-md-4">
                                <label className="form-label">New limit</label>
                                <input type="number" min={0} className="form-control" placeholder="Unlimited" value={String(form.value)} onChange={(e) => set('value', e.target.value)} />
                                <FieldError errors={errors} name="value" />
                            </div>
                            <div className="col-md-4">
                                <label className="form-label">Until</label>
                                <input type="date" className="form-control" value={String(form.until)} onChange={(e) => set('until', e.target.value)} />
                            </div>
                        </div>
                    )}
                </ActionDialog>
            );
        case 'feature':
            return (
                <ActionDialog title="Grant a feature" icon="ti ti-sparkles" submitLabel="Grant" onClose={onClose}
                    submit={(reason) => send(`${base}/features`, { feature: form.feature, ...(form.until ? { until: form.until } : {}), reason })}>
                    {() => (
                        <div className="row g-3 mb-3">
                            <div className="col-md-7">
                                <label className="form-label">Feature</label>
                                <select className="form-select" value={String(form.feature)} onChange={(e) => set('feature', e.target.value)}>
                                    {FEATURE_KEYS.map((key) => <option key={key} value={key}>{LABELS[key]}</option>)}
                                </select>
                            </div>
                            <div className="col-md-5">
                                <label className="form-label">Until</label>
                                <input type="date" className="form-control" value={String(form.until)} onChange={(e) => set('until', e.target.value)} />
                            </div>
                        </div>
                    )}
                </ActionDialog>
            );
        default:
            return null;
    }
}
