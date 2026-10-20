import { useDeferredValue, useState } from 'react';
import { formatPaise, rupeesToPaise } from '@/core/subscription/money';
import { useBillingMutation, useOrganizationOptions, usePlans } from '../api';
import { ActionDialog, FieldError } from './Common';

type Cycle = 'monthly' | 'annual' | 'complimentary';

const CYCLES: Array<{ value: Cycle; label: string }> = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'annual', label: 'Annual' },
    { value: 'complimentary', label: 'Complimentary' },
];

const rupees = (paise: number) => formatPaise(paise).replace(/\.00$/, '');

/** "City Care Hospital" → "CC". */
function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

/**
 * "Add Subscription": put an organization on a plan — a new contract — from
 * the billing screens, without going to the organization first.
 */
export function AddSubscriptionDialog({ onClose }: { onClose: () => void }) {
    const [search, setSearch] = useState('');
    const query = useDeferredValue(search.trim());
    const { data: organizations, isFetching } = useOrganizationOptions(query);
    const { data: plans } = usePlans();
    const assign = useBillingMutation<Record<string, unknown>>('post', (body) => String(body.__path));

    const [organization, setOrganization] = useState<{ uuid: string; name: string; code: string } | null>(null);
    const [planId, setPlanId] = useState<number | null>(null);
    const [cycle, setCycle] = useState<Cycle>('monthly');
    const [price, setPrice] = useState('');
    const [trial, setTrial] = useState(false);
    const [waived, setWaived] = useState(false);

    const choices = (plans ?? []).filter((p) => p.status !== 'archived');
    const plan = choices.find((p) => p.id === planId) ?? null;
    const listPrice = plan && cycle !== 'complimentary' ? (cycle === 'annual' ? plan.annual_price : plan.monthly_price) : null;
    const priceValid = price.trim() === '' || rupeesToPaise(price) !== null;
    const canTrial = Boolean(plan && plan.trial_days > 0 && cycle !== 'complimentary');

    return (
        <ActionDialog
            title="Add Subscription"
            subtitle="Put an organization on a plan. Its live add-ons and overrides carry across."
            icon="ti ti-user-plus"
            submitLabel="Assign Plan"
            size="lg"
            canSubmit={Boolean(organization && plan && priceValid)}
            onClose={onClose}
            submit={(reason) =>
                assign.mutateAsync({
                    __path: `/organizations/${organization?.uuid}/subscription/assign`,
                    plan_id: planId,
                    billing_cycle: cycle,
                    trial: canTrial && trial,
                    payment_status: waived ? 'waived' : 'pending',
                    ...(price.trim() && cycle !== 'complimentary' ? { price: rupeesToPaise(price) } : {}),
                    reason,
                })
            }
        >
            {(errors) => (
                <>
                    <div className="sxa-mfield">
                        <label className="form-label">Organization</label>
                        {organization ? (
                            <div className="sxa-picked">
                                <span className="sxa-avatar" aria-hidden="true">{initials(organization.name)}</span>
                                <span className="sxa-picked-text">
                                    <b>{organization.name}</b>
                                    <small>{organization.code}</small>
                                </span>
                                <button type="button" className="sxa-link-btn" onClick={() => setOrganization(null)}>Change</button>
                            </div>
                        ) : (
                            <div className="sxa-orgpick">
                                <div className="sxa-orgpick-search">
                                    <i className={isFetching ? 'ti ti-loader-2 sxa-spin' : 'ti ti-search'} aria-hidden="true" />
                                    <input className="form-control" autoFocus placeholder="Search by name or code…" aria-label="Search organizations" value={search} onChange={(e) => setSearch(e.target.value)} />
                                </div>
                                <ul className="sxa-orgpick-list" role="listbox" aria-label="Organizations">
                                    {(organizations ?? []).map((org) => (
                                        <li key={org.uuid}>
                                            <button
                                                type="button"
                                                role="option"
                                                aria-selected={false}
                                                onClick={() => setOrganization({ uuid: org.uuid, name: org.organization_name, code: org.organization_code })}
                                            >
                                                <span className="sxa-avatar" aria-hidden="true">{initials(org.organization_name)}</span>
                                                <span className="sxa-picked-text">
                                                    <b>{org.organization_name}</b>
                                                    <small>{org.organization_code}</small>
                                                </span>
                                                <i className="ti ti-chevron-right" aria-hidden="true" />
                                            </button>
                                        </li>
                                    ))}
                                    {organizations && organizations.length === 0 && (
                                        <li className="sxa-orgpick-empty">{query ? `No organization matches “${query}”.` : 'No organizations yet.'}</li>
                                    )}
                                </ul>
                            </div>
                        )}
                    </div>

                    <div className="sxa-mfield">
                        <div className="sxa-mfield-head">
                            <label className="form-label mb-0">Plan</label>
                            <div className="sxa-seg" role="radiogroup" aria-label="Billing cycle">
                                {CYCLES.map((c) => (
                                    <button key={c.value} type="button" role="radio" aria-checked={cycle === c.value} className={cycle === c.value ? 'is-on' : ''} onClick={() => setCycle(c.value)}>
                                        {c.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="sxa-planpick" role="radiogroup" aria-label="Plan">
                            {choices.map((p) => {
                                const amount = cycle === 'annual' ? p.annual_price : p.monthly_price;

                                return (
                                    <button key={p.id} type="button" role="radio" aria-checked={planId === p.id} className={planId === p.id ? 'is-on' : ''} onClick={() => setPlanId(p.id)}>
                                        <span className="sxa-planpick-radio" aria-hidden="true" />
                                        <b>{p.name}</b>
                                        <span className="sxa-planpick-price">
                                            {cycle === 'complimentary' || p.is_system || amount === 0 ? (
                                                'Free'
                                            ) : (
                                                <>
                                                    {rupees(amount)}
                                                    <small>/{cycle === 'annual' ? 'year' : 'month'}</small>
                                                </>
                                            )}
                                        </span>
                                        <small>
                                            {p.trial_days > 0 ? `${p.trial_days}-day trial` : 'No trial'}
                                            {p.status !== 'published' ? ` · ${p.status}` : ''}
                                        </small>
                                    </button>
                                );
                            })}
                        </div>
                        <FieldError errors={errors} name="plan_id" />
                        <FieldError errors={errors} name="billing_cycle" />
                    </div>

                    <div className="sxa-mfield">
                        <label className="form-label" htmlFor="agreed-price">Agreed Price</label>
                        <span className="sxa-affix">
                            <span className="sxa-affix-text">₹</span>
                            <input
                                id="agreed-price"
                                className={`form-control ${!priceValid || errors.price ? 'is-invalid' : ''}`}
                                inputMode="decimal"
                                disabled={cycle === 'complimentary'}
                                placeholder={listPrice !== null ? paiseText(listPrice) : cycle === 'complimentary' ? 'Free' : 'Choose a plan first'}
                                value={cycle === 'complimentary' ? '' : price}
                                onChange={(e) => setPrice(e.target.value)}
                            />
                        </span>
                        {!priceValid ? (
                            <div className="invalid-feedback d-block">Enter an amount like 9999 or 9999.50.</div>
                        ) : (
                            <FieldError errors={errors} name="price" />
                        )}
                        <small className="sxa-mfield-hint">
                            {cycle === 'complimentary'
                                ? 'Complimentary terms are free and do not expire.'
                                : listPrice !== null
                                  ? `Leave blank to charge the list price, ${rupees(listPrice)} a ${cycle === 'annual' ? 'year' : 'month'}, before tax.`
                                  : 'Leave blank to charge the plan’s list price.'}
                        </small>
                    </div>

                    <div className="sxa-toggles is-2 sxa-mfield">
                        <label className={`sxa-toggle ${canTrial && trial ? 'is-on' : ''} ${canTrial ? '' : 'is-disabled'}`}>
                            <span className="sxa-toggle-icon" aria-hidden="true"><i className="ti ti-hourglass" /></span>
                            <span className="sxa-toggle-text">
                                <b>Start with a trial</b>
                                <small>{plan ? (canTrial ? `${plan.trial_days} days before the first payment.` : 'This plan has no trial.') : 'Uses the plan’s trial length.'}</small>
                            </span>
                            <span className="form-check form-switch">
                                <input type="checkbox" role="switch" className="form-check-input" disabled={!canTrial} checked={canTrial && trial} onChange={(e) => setTrial(e.target.checked)} />
                            </span>
                        </label>
                        <label className={`sxa-toggle ${waived ? 'is-on' : ''}`}>
                            <span className="sxa-toggle-icon" aria-hidden="true"><i className="ti ti-receipt-off" /></span>
                            <span className="sxa-toggle-text">
                                <b>Payment waived</b>
                                <small>No payment is expected for this term.</small>
                            </span>
                            <span className="form-check form-switch">
                                <input type="checkbox" role="switch" className="form-check-input" checked={waived} onChange={(e) => setWaived(e.target.checked)} />
                            </span>
                        </label>
                    </div>
                </>
            )}
        </ActionDialog>
    );
}

/** 999900 → "9999", for a placeholder that reads like what you would type. */
function paiseText(paise: number): string {
    return (paise / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 });
}
