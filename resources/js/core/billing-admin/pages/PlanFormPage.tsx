import { useState, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { formatPaise, paiseToRupees, rupeesToPaise } from '@/core/subscription/money';
import { useBillingMutation, useModulePrices, usePlans, type Plan, type PlanInput } from '../api';
import { FEATURE_KEYS, LABELS, QUOTA_KEYS, RESOURCE_KEYS } from '../labels';
import { FieldError, StatusChip } from '../components/Common';
import { accentFor, PlanFace } from '../components/PlanCard';

const LIMIT_ICONS: Record<string, string> = {
    branches: 'ti ti-building',
    doctors: 'ti ti-stethoscope',
    employees: 'ti ti-users',
    whatsapp_messages: 'ti ti-brand-whatsapp',
    sms_messages: 'ti ti-message',
    emails: 'ti ti-mail',
    video_minutes: 'ti ti-clock-play',
    video_sessions: 'ti ti-video',
};

const FEATURES: Record<string, { icon: string; text: string }> = {
    video_consultation: { icon: 'ti ti-video', text: 'Doctors can consult patients over video.' },
    mobile_app: { icon: 'ti ti-device-mobile', text: 'Staff and patients can sign in on the mobile app.' },
};

interface Draft {
    name: string;
    slug: string;
    description: string;
    is_public: boolean;
    sort_order: string;
    monthly: string;
    annual: string;
    trial_days: string;
    grace_days: string;
    features: string[];
    modules: string[];
    highlights: string[];
    limits: Record<string, { unlimited: boolean; value: string }>;
}

function draftOf(plan: Plan | null): Draft {
    return {
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        description: plan?.description ?? '',
        is_public: plan?.is_public ?? true,
        sort_order: String(plan?.sort_order ?? 0),
        monthly: paiseToRupees(plan?.monthly_price ?? 0),
        annual: paiseToRupees(plan?.annual_price ?? 0),
        trial_days: String(plan?.trial_days ?? 14),
        grace_days: String(plan?.grace_days ?? 3),
        features: plan?.features ?? [],
        modules: plan?.modules ?? [],
        highlights: plan?.highlights.length ? plan.highlights : [''],
        limits: Object.fromEntries(
            [...RESOURCE_KEYS, ...QUOTA_KEYS].map((key) => {
                const value = plan ? plan.limits[key] : null;

                return [key, { unlimited: value === null || value === undefined, value: value === null || value === undefined ? '' : String(value) }];
            }),
        ),
    };
}

/** "Sunrise Clinic Plus" → "sunrise-clinic-plus", as the server's rule wants it. */
function slugify(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
}

const rupees = (paise: number) => formatPaise(paise).replace(/\.00$/, '');

/** A numbered part of the form: icon, title, one line on what it decides. */
function Section({ icon, title, caption, actions, children }: { icon: string; title: string; caption: string; actions?: ReactNode; children: ReactNode }) {
    return (
        <Card className="sxa-pf-section">
            <div className="sxa-pf-head">
                <span className="sxa-pf-icon" aria-hidden="true"><i className={icon} /></span>
                <div className="sxa-pf-title">
                    <h6>{title}</h6>
                    <p>{caption}</p>
                </div>
                {actions && <div className="sxa-pf-actions">{actions}</div>}
            </div>
            {children}
        </Card>
    );
}

/**
 * Create a plan, or edit one, on its own page: the form on the left in
 * sections, and on the right the card exactly as the Plans page and the
 * website will show it, updating as you type.
 */
export default function PlanFormPage() {
    const { uuid } = useParams<{ uuid: string }>();
    const navigate = useNavigate();
    const { data: plans, isLoading, isError, refetch } = usePlans();

    if (isLoading) {
        return <LoadingBlock />;
    }

    if (isError || !plans) {
        return <ErrorState onRetry={() => void refetch()} />;
    }

    const plan = uuid ? (plans.find((p) => p.uuid === uuid) ?? null) : null;

    if (uuid && !plan) {
        return (
            <EmptyState
                icon="ti ti-stack-2"
                title="Plan not found"
                description="It may have been deleted while it was a draft."
                action={<Button icon="ti ti-arrow-left" onClick={() => navigate('/billing/plans')}>Back to Plans</Button>}
            />
        );
    }

    if (plan?.is_system) {
        return (
            <EmptyState
                icon="ti ti-lock"
                title={`${plan.name} is managed by the platform`}
                description="It keeps existing organizations working until a plan is assigned, so it cannot be edited."
                action={<Button icon="ti ti-arrow-left" onClick={() => navigate('/billing/plans')}>Back to Plans</Button>}
            />
        );
    }

    return <PlanForm key={plan?.uuid ?? 'new'} plan={plan} accent={accentFor(plans, plan?.uuid ?? null)} />;
}

function PlanForm({ plan, accent }: { plan: Plan | null; accent: string }) {
    const navigate = useNavigate();
    const { data: modules, isLoading: modulesLoading } = useModulePrices();
    const save = useBillingMutation<PlanInput>(plan ? 'put' : 'post', plan ? `/plans/${plan.uuid}` : '/plans');
    const [d, setDraft] = useState<Draft>(() => draftOf(plan));
    const [slugTouched, setSlugTouched] = useState(Boolean(plan));
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [failure, setFailure] = useState<string | null>(null);

    const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));
    const toggle = (list: string[], value: string) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
    const error = (name: string) => <FieldError errors={errors} name={name} />;
    const invalid = (name: string) => (errors[name] ? 'is-invalid' : '');
    const slugLocked = Boolean(plan && plan.status !== 'draft');

    const monthly = rupeesToPaise(d.monthly);
    const annual = rupeesToPaise(d.annual);
    const yearly = monthly !== null ? monthly * 12 : null;
    const saving = yearly !== null && annual !== null ? yearly - annual : null;
    const savingPct = saving !== null && yearly ? Math.round((saving / yearly) * 100) : null;

    const limits = Object.fromEntries(Object.entries(d.limits).map(([key, l]) => [key, l.unlimited ? null : Number(l.value || 0)]));
    const preview = {
        name: d.name,
        description: d.description,
        is_system: false,
        monthly_price: monthly ?? 0,
        highlights: d.highlights,
        limits,
        modules: d.modules,
        features: d.features,
    };

    const activeModules = (modules ?? []).filter((row) => row.module.is_active);
    const moduleErrors = Object.entries(errors).filter(([key]) => key === 'modules' || key.startsWith('modules.')).flatMap(([, list]) => list);

    async function submit() {
        setErrors({});
        setFailure(null);

        if (monthly === null || annual === null) {
            setErrors({
                ...(monthly === null ? { monthly_price: ['Enter a price like 1499 or 1499.50.'] } : {}),
                ...(annual === null ? { annual_price: ['Enter a price like 14990 or 14990.50.'] } : {}),
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });

            return;
        }

        try {
            await save.mutateAsync({
                name: d.name.trim(),
                ...(slugLocked ? {} : { slug: d.slug }),
                description: d.description.trim() || null,
                is_public: d.is_public,
                sort_order: Number(d.sort_order) || 0,
                monthly_price: monthly,
                annual_price: annual,
                trial_days: Number(d.trial_days) || 0,
                grace_days: Number(d.grace_days) || 0,
                features: d.features,
                highlights: d.highlights.map((line) => line.trim()).filter(Boolean),
                modules: d.modules,
                limits,
            });
            navigate('/billing/plans');
        } catch (failed) {
            const validation = getValidationErrors(failed);
            validation ? setErrors(validation) : setFailure(resolveErrorMessage(failed));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    const saveButtons = (block = false) => (
        <>
            <Button variant="light" className={block ? 'w-100' : undefined} onClick={() => navigate('/billing/plans')}>Cancel</Button>
            <Button loading={save.isPending} icon="ti ti-device-floppy" className={block ? 'w-100' : undefined} onClick={() => void submit()}>
                {plan ? 'Save Changes' : 'Create Plan'}
            </Button>
        </>
    );

    return (
        <>
            <PageHeader
                title={plan ? `Edit ${plan.name}` : 'Create Plan'}
                subtitle={plan ? 'Change what this plan costs and includes.' : 'Set what the plan costs, what it includes, and how it shows on the website.'}
                icon="ti ti-stack-2"
                crumbs={[{ label: 'Plans', to: '/billing/plans' }, { label: plan ? plan.name : 'New plan' }]}
                actions={<div className="d-flex flex-wrap gap-2">{saveButtons()}</div>}
            />

            {failure && <div className="alert alert-danger">{failure}</div>}
            {Object.keys(errors).length > 0 && <div className="alert alert-danger">Some fields need attention — they are marked below.</div>}

            <div className="sxa-pf">
                <div className="sxa-pf-main">
                    <Section icon="ti ti-id-badge-2" title="Plan Details" caption="The name, the web address and how it is described.">
                        {plan && (
                            <p className="sxa-pf-notice">
                                <i className="ti ti-info-circle" aria-hidden="true" />
                                {plan.status === 'published'
                                    ? 'This plan is on sale. Changes apply to new sales and renewals — organizations already on it keep the terms they agreed.'
                                    : plan.status === 'draft'
                                      ? 'A draft — nobody can buy it until you publish it from the Plans page.'
                                      : 'Not on sale right now. Publish it from the Plans page to sell it again.'}
                            </p>
                        )}
                        <div className="sxa-form">
                            <label className="sxa-field">
                                <span className="sxa-field-label">Plan Name *</span>
                                <input
                                    className={`form-control ${invalid('name')}`}
                                    placeholder="e.g. Growth"
                                    maxLength={120}
                                    value={d.name}
                                    onChange={(e) => {
                                        const name = e.target.value;
                                        setDraft((current) => ({ ...current, name, slug: slugTouched ? current.slug : slugify(name) }));
                                    }}
                                />
                                {error('name')}
                            </label>
                            <label className="sxa-field">
                                <span className="sxa-field-label">Web Address *</span>
                                <span className="sxa-affix is-path">
                                    <span className="sxa-affix-text">/plans/</span>
                                    <input
                                        className={`form-control ${invalid('slug')}`}
                                        placeholder="growth"
                                        maxLength={60}
                                        value={d.slug}
                                        disabled={slugLocked}
                                        onChange={(e) => {
                                            setSlugTouched(true);
                                            set('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''));
                                        }}
                                    />
                                </span>
                                {slugLocked ? <small className="sxa-field-hint">Fixed once the plan has been published.</small> : error('slug')}
                            </label>
                            <label className="sxa-field is-wide">
                                <span className="sxa-field-label">Description</span>
                                <textarea className={`form-control ${invalid('description')}`} rows={2} maxLength={2000} placeholder="e.g. For growing hospitals" value={d.description} onChange={(e) => set('description', e.target.value)} />
                                {error('description')}
                            </label>
                            <div className="sxa-pf-visibility">
                                <label className={`sxa-toggle ${d.is_public ? 'is-on' : ''}`}>
                                    <span className="sxa-toggle-icon" aria-hidden="true"><i className="ti ti-world" /></span>
                                    <span className="sxa-toggle-text">
                                        <b>Show on the website</b>
                                        <small>Listed on the public pricing page once it is published.</small>
                                    </span>
                                    <span className="form-check form-switch">
                                        <input type="checkbox" role="switch" className="form-check-input" checked={d.is_public} onChange={(e) => set('is_public', e.target.checked)} />
                                    </span>
                                </label>
                                <label className="sxa-field">
                                    <span className="sxa-field-label">Display Order</span>
                                    <input type="number" min={0} max={999} className={`form-control ${invalid('sort_order')}`} value={d.sort_order} onChange={(e) => set('sort_order', e.target.value)} />
                                    {error('sort_order')}
                                </label>
                            </div>
                        </div>
                    </Section>

                    <Section icon="ti ti-currency-rupee" title="Pricing" caption="What it costs, before tax, and how long the trial and grace period last.">
                        <div className="sxa-form is-4">
                            <label className="sxa-field">
                                <span className="sxa-field-label">Monthly Price *</span>
                                <span className="sxa-affix">
                                    <span className="sxa-affix-text">₹</span>
                                    <input className={`form-control ${invalid('monthly_price')}`} inputMode="decimal" placeholder="0" value={d.monthly} onChange={(e) => set('monthly', e.target.value)} />
                                </span>
                                {error('monthly_price')}
                            </label>
                            <label className="sxa-field">
                                <span className="sxa-field-label">Annual Price *</span>
                                <span className="sxa-affix">
                                    <span className="sxa-affix-text">₹</span>
                                    <input className={`form-control ${invalid('annual_price')}`} inputMode="decimal" placeholder="0" value={d.annual} onChange={(e) => set('annual', e.target.value)} />
                                </span>
                                {error('annual_price')}
                            </label>
                            <label className="sxa-field">
                                <span className="sxa-field-label">Free Trial</span>
                                <span className="sxa-affix is-end">
                                    <input type="number" min={0} max={365} className={`form-control ${invalid('trial_days')}`} value={d.trial_days} onChange={(e) => set('trial_days', e.target.value)} />
                                    <span className="sxa-affix-text">days</span>
                                </span>
                                {error('trial_days')}
                            </label>
                            <label className="sxa-field">
                                <span className="sxa-field-label">Grace Period</span>
                                <span className="sxa-affix is-end">
                                    <input type="number" min={0} max={90} className={`form-control ${invalid('grace_days')}`} value={d.grace_days} onChange={(e) => set('grace_days', e.target.value)} />
                                    <span className="sxa-affix-text">days</span>
                                </span>
                                {error('grace_days')}
                            </label>
                        </div>

                        {monthly !== null && monthly > 0 && (
                            <div className={`sxa-pf-saving ${saving !== null && saving < 0 ? 'is-bad' : ''}`}>
                                <i className={saving !== null && saving < 0 ? 'ti ti-alert-triangle' : 'ti ti-discount-2'} aria-hidden="true" />
                                <span>
                                    {saving === null || annual === null || annual === 0
                                        ? `Twelve monthly payments come to ${rupees(yearly ?? 0)}.`
                                        : saving > 0
                                          ? `Paying yearly saves ${savingPct}% — ${rupees(saving)} less than twelve monthly payments of ${rupees(monthly)}.`
                                          : saving === 0
                                            ? 'The annual price is the same as twelve monthly payments — no saving for paying yearly.'
                                            : `The annual price is ${rupees(-saving)} more than twelve monthly payments.`}
                                </span>
                                <Button size="sm" variant="light" onClick={() => set('annual', paiseToRupees(monthly * 10))}>
                                    Two months free
                                </Button>
                            </div>
                        )}
                    </Section>

                    <Section icon="ti ti-adjustments-horizontal" title="Limits & Allowances" caption="How much an organization on this plan can have and send. Switch on Unlimited for no cap.">
                        <div className="sxa-pf-group">Resources</div>
                        <div className="sxa-limits-grid">
                            {RESOURCE_KEYS.map((key) => (
                                <LimitTile key={key} name={key} label={LABELS[key]} value={d.limits[key]} error={errors[`limits.${key}`]?.[0]} onChange={(next) => set('limits', { ...d.limits, [key]: next })} />
                            ))}
                        </div>
                        <div className="sxa-pf-group">Monthly allowances</div>
                        <div className="sxa-limits-grid">
                            {QUOTA_KEYS.map((key) => (
                                <LimitTile
                                    key={key}
                                    name={key}
                                    label={LABELS[key].replace(' / month', '')}
                                    note={key === 'sms_messages' ? 'Not counted until an SMS provider is connected.' : undefined}
                                    value={d.limits[key]}
                                    error={errors[`limits.${key}`]?.[0]}
                                    onChange={(next) => set('limits', { ...d.limits, [key]: next })}
                                />
                            ))}
                        </div>
                        {error('limits')}
                    </Section>

                    <Section
                        icon="ti ti-apps"
                        title="Modules"
                        caption="What the plan includes beyond the core (branches, people, customers, settings, billing), which every plan has."
                        actions={
                            activeModules.length > 0 && (
                                <>
                                    <button type="button" className="sxa-link-btn" onClick={() => set('modules', activeModules.map((row) => row.module.key))}>Select all</button>
                                    <button type="button" className="sxa-link-btn" onClick={() => set('modules', [])}>Clear</button>
                                </>
                            )
                        }
                    >
                        {modulesLoading ? (
                            <LoadingBlock />
                        ) : (
                            <div className="sxa-modpick">
                                {activeModules.map((row) => {
                                    const on = d.modules.includes(row.module.key);

                                    return (
                                        <button key={row.module.key} type="button" role="checkbox" aria-checked={on} className={on ? 'is-on' : ''} onClick={() => set('modules', toggle(d.modules, row.module.key))}>
                                            <span className="sxa-modpick-check" aria-hidden="true"><i className="ti ti-check" /></span>
                                            <b>{row.module.name}</b>
                                            {row.module.description && <small>{row.module.description}</small>}
                                            <em>{row.current ? `Add-on ${rupees(row.current.monthly_price)}/mo` : 'Not sold separately'}</em>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {moduleErrors.length > 0 && <div className="invalid-feedback d-block">{moduleErrors[0]}</div>}
                    </Section>

                    <Section icon="ti ti-sparkles" title="Features" caption="Extras switched on for every organization on this plan.">
                        <div className="sxa-toggles is-2">
                            {FEATURE_KEYS.map((key) => (
                                <label key={key} className={`sxa-toggle ${d.features.includes(key) ? 'is-on' : ''}`}>
                                    <span className="sxa-toggle-icon" aria-hidden="true"><i className={FEATURES[key].icon} /></span>
                                    <span className="sxa-toggle-text">
                                        <b>{LABELS[key]}</b>
                                        <small>{FEATURES[key].text}</small>
                                    </span>
                                    <span className="form-check form-switch">
                                        <input type="checkbox" role="switch" className="form-check-input" checked={d.features.includes(key)} onChange={() => set('features', toggle(d.features, key))} />
                                    </span>
                                </label>
                            ))}
                        </div>
                        {error('features')}
                    </Section>

                    <Section icon="ti ti-list-check" title="Website Highlights" caption="The lines under the price. The first five show on the card; leave them empty to show the plan's limits instead.">
                        <div className="sxa-hl">
                            {d.highlights.map((line, index) => (
                                <div className="sxa-hl-row" key={index}>
                                    <span className="sxa-hl-mark" aria-hidden="true"><i className="ti ti-check" /></span>
                                    <input
                                        className={`form-control ${invalid(`highlights.${index}`)}`}
                                        maxLength={160}
                                        placeholder={index === 0 ? 'e.g. Up to 5 branches' : 'Another highlight'}
                                        aria-label={`Highlight ${index + 1}`}
                                        value={line}
                                        onChange={(e) => set('highlights', d.highlights.map((l, i) => (i === index ? e.target.value : l)))}
                                    />
                                    <button
                                        type="button"
                                        className="sxa-hl-remove"
                                        aria-label={`Remove highlight ${index + 1}`}
                                        onClick={() => set('highlights', d.highlights.length > 1 ? d.highlights.filter((_, i) => i !== index) : [''])}
                                    >
                                        <i className="ti ti-x" />
                                    </button>
                                </div>
                            ))}
                            <Button size="sm" variant="light" icon="ti ti-plus" disabled={d.highlights.length >= 12} onClick={() => set('highlights', [...d.highlights, ''])}>
                                Add highlight
                            </Button>
                        </div>
                        {error('highlights')}
                    </Section>
                </div>

                <aside className="sxa-pf-side">
                    <div className="sxa-pf-sticky">
                        <div className="sxa-pf-side-label">Live Preview</div>
                        <PlanFace plan={preview} accent={accent}>
                            <div className="sxa-plan-meta">
                                <span>{Number(d.trial_days) > 0 ? `${Number(d.trial_days)}-day free trial` : 'No free trial'}</span>
                                <StatusChip status={plan?.status ?? 'draft'} />
                            </div>
                        </PlanFace>

                        <Card className="sxa-pf-summary">
                            <h6>Summary</h6>
                            <dl>
                                <div><dt>Monthly</dt><dd>{monthly !== null ? rupees(monthly) : '—'}</dd></div>
                                <div><dt>Annual</dt><dd>{annual !== null ? rupees(annual) : '—'}</dd></div>
                                <div>
                                    <dt>Yearly saving</dt>
                                    <dd className={saving !== null && saving < 0 ? 'is-bad' : saving ? 'is-good' : undefined}>
                                        {saving !== null && savingPct !== null && monthly ? `${savingPct}%` : '—'}
                                    </dd>
                                </div>
                                <div><dt>Trial · Grace</dt><dd>{Number(d.trial_days) || 0} · {Number(d.grace_days) || 0} days</dd></div>
                                <div><dt>Modules</dt><dd>{d.modules.length === 0 ? 'None' : d.modules.length}</dd></div>
                                <div><dt>On the website</dt><dd>{d.is_public ? 'Yes' : 'No'}</dd></div>
                            </dl>
                            <p className="sxa-pf-tax">Prices are before tax; tax is added as set in Billing Settings.</p>
                            <div className="sxa-pf-buttons">{saveButtons(true)}</div>
                        </Card>
                    </div>
                </aside>
            </div>
        </>
    );
}

/** One limit: the number, or Unlimited. */
function LimitTile({
    name,
    label,
    note,
    value,
    error,
    onChange,
}: {
    name: string;
    label: string;
    note?: string;
    value: { unlimited: boolean; value: string };
    error?: string;
    onChange: (next: { unlimited: boolean; value: string }) => void;
}) {
    return (
        <div className={`sxa-limit ${value.unlimited ? 'is-unlimited' : ''} ${error ? 'has-error' : ''}`}>
            <div className="sxa-limit-top">
                <span className="sxa-limit-icon" aria-hidden="true"><i className={LIMIT_ICONS[name]} /></span>
                <b>{label}</b>
            </div>
            <input
                type="number"
                min={0}
                className="form-control"
                aria-label={label}
                placeholder={value.unlimited ? 'Unlimited' : '0'}
                disabled={value.unlimited}
                value={value.unlimited ? '' : value.value}
                onChange={(e) => onChange({ unlimited: false, value: e.target.value })}
            />
            <label className="form-check form-switch sxa-limit-switch">
                <input
                    type="checkbox"
                    role="switch"
                    className="form-check-input"
                    checked={value.unlimited}
                    onChange={(e) => onChange({ unlimited: e.target.checked, value: e.target.checked ? '' : value.value || '0' })}
                />
                <span>Unlimited</span>
            </label>
            {note && <small className="sxa-limit-note">{note}</small>}
            {error && <div className="invalid-feedback d-block">{error}</div>}
        </div>
    );
}
