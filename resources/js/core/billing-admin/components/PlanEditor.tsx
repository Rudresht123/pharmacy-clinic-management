import { useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { paiseToRupees, rupeesToPaise } from '@/core/subscription/money';
import { useBillingMutation, useModulePrices, type Plan, type PlanInput } from '../api';
import { FEATURE_KEYS, LABELS, LIMIT_KEYS } from '../labels';
import { FieldError } from './Common';

/**
 * Create or edit a plan: prices (typed in rupees, sent as paise), trial and
 * grace, every limit and monthly allowance (blank is unlimited), the modules
 * and features it includes, and the lines the website shows.
 */
export function PlanEditor({ plan, onClose }: { plan: Plan | null; onClose: () => void }) {
    const { data: modules } = useModulePrices();
    const save = useBillingMutation<PlanInput>(plan ? 'put' : 'post', plan ? `/plans/${plan.uuid}` : '/plans');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [failure, setFailure] = useState<string | null>(null);

    const [form, setForm] = useState({
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        description: plan?.description ?? '',
        is_public: plan?.is_public ?? true,
        sort_order: plan?.sort_order ?? 0,
        monthly: paiseToRupees(plan?.monthly_price ?? 0),
        annual: paiseToRupees(plan?.annual_price ?? 0),
        trial_days: plan?.trial_days ?? 14,
        grace_days: plan?.grace_days ?? 3,
        features: plan?.features ?? [],
        highlights: (plan?.highlights ?? []).join('\n'),
        modules: plan?.modules ?? [],
        limits: Object.fromEntries(
            LIMIT_KEYS.map((key) => [key, plan ? (plan.limits[key] === null ? '' : String(plan.limits[key] ?? 0)) : '']),
        ) as Record<string, string>,
    });

    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm({ ...form, [key]: value });

    async function submit() {
        setErrors({});
        setFailure(null);

        const monthly = rupeesToPaise(form.monthly);
        const annual = rupeesToPaise(form.annual);

        if (monthly === null || annual === null) {
            setErrors({ monthly_price: ['Enter prices like 1499 or 1499.50.'] });

            return;
        }

        try {
            await save.mutateAsync({
                name: form.name,
                ...(plan === null || plan.status === 'draft' ? { slug: form.slug } : {}),
                description: form.description || null,
                is_public: form.is_public,
                sort_order: Number(form.sort_order) || 0,
                monthly_price: monthly,
                annual_price: annual,
                trial_days: Number(form.trial_days) || 0,
                grace_days: Number(form.grace_days) || 0,
                features: form.features,
                highlights: form.highlights.split('\n').map((line) => line.trim()).filter(Boolean),
                modules: form.modules,
                limits: Object.fromEntries(
                    Object.entries(form.limits).map(([key, value]) => [key, value.trim() === '' ? null : Number(value)]),
                ),
            });
            onClose();
        } catch (error) {
            const validation = getValidationErrors(error);
            validation ? setErrors(validation) : setFailure(resolveErrorMessage(error));
        }
    }

    const toggle = (list: string[], value: string) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);

    return (
        <Modal
            open
            size="xl"
            title={plan ? `Edit ${plan.name}` : 'New plan'}
            subtitle={plan?.status === 'published' ? 'Changes apply to new sales and renewals. Running terms keep what they agreed.' : 'Saved as a draft until you publish it.'}
            icon={<i className="ti ti-stack-2" />}
            onClose={onClose}
            busy={save.isPending}
            footer={
                <>
                    <Button variant="light" onClick={onClose}>Cancel</Button>
                    <Button loading={save.isPending} onClick={() => void submit()}>Save plan</Button>
                </>
            }
        >
            <div className="row g-3">
                <div className="col-md-6">
                    <label className="form-label">Name *</label>
                    <input className={`form-control ${errors.name ? 'is-invalid' : ''}`} value={form.name} onChange={(e) => set('name', e.target.value)} />
                    <FieldError errors={errors} name="name" />
                </div>
                <div className="col-md-6">
                    <label className="form-label">Address on the website *</label>
                    <input
                        className={`form-control ${errors.slug ? 'is-invalid' : ''}`}
                        value={form.slug}
                        disabled={Boolean(plan && plan.status !== 'draft')}
                        placeholder="growth"
                        onChange={(e) => set('slug', e.target.value.toLowerCase())}
                    />
                    <FieldError errors={errors} name="slug" />
                </div>
                <div className="col-12">
                    <label className="form-label">Description</label>
                    <textarea className="form-control" rows={2} value={form.description} onChange={(e) => set('description', e.target.value)} />
                </div>
                <div className="col-md-3">
                    <label className="form-label">Monthly price (₹) *</label>
                    <input className={`form-control ${errors.monthly_price ? 'is-invalid' : ''}`} inputMode="decimal" value={form.monthly} onChange={(e) => set('monthly', e.target.value)} />
                    <FieldError errors={errors} name="monthly_price" />
                </div>
                <div className="col-md-3">
                    <label className="form-label">Annual price (₹) *</label>
                    <input className={`form-control ${errors.annual_price ? 'is-invalid' : ''}`} inputMode="decimal" value={form.annual} onChange={(e) => set('annual', e.target.value)} />
                    <FieldError errors={errors} name="annual_price" />
                </div>
                <div className="col-md-2">
                    <label className="form-label">Trial days</label>
                    <input type="number" min={0} className="form-control" value={form.trial_days} onChange={(e) => set('trial_days', Number(e.target.value))} />
                </div>
                <div className="col-md-2">
                    <label className="form-label">Grace days</label>
                    <input type="number" min={0} className="form-control" value={form.grace_days} onChange={(e) => set('grace_days', Number(e.target.value))} />
                </div>
                <div className="col-md-2">
                    <label className="form-label">Order</label>
                    <input type="number" min={0} className="form-control" value={form.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))} />
                </div>
            </div>

            <div className="sxa-section-title">Limits and monthly allowances — leave blank for unlimited</div>
            <div className="sxa-limits">
                {LIMIT_KEYS.map((key) => (
                    <div key={key}>
                        <label className="form-label small">{LABELS[key]}</label>
                        <input
                            type="number"
                            min={0}
                            className={`form-control ${errors[`limits.${key}`] ? 'is-invalid' : ''}`}
                            placeholder="Unlimited"
                            value={form.limits[key]}
                            onChange={(e) => set('limits', { ...form.limits, [key]: e.target.value })}
                        />
                    </div>
                ))}
            </div>
            <FieldError errors={errors} name="limits" />

            <div className="sxa-section-title">Modules included</div>
            <div className="sxa-checks">
                {(modules ?? []).map((row) => (
                    <label key={row.module.key}>
                        <input type="checkbox" className="form-check-input" checked={form.modules.includes(row.module.key)} onChange={() => set('modules', toggle(form.modules, row.module.key))} />
                        {row.module.name}
                    </label>
                ))}
            </div>
            <FieldError errors={errors} name="modules" />

            <div className="sxa-section-title">Features</div>
            <div className="sxa-checks">
                {FEATURE_KEYS.map((key) => (
                    <label key={key}>
                        <input type="checkbox" className="form-check-input" checked={form.features.includes(key)} onChange={() => set('features', toggle(form.features, key))} />
                        {LABELS[key]}
                    </label>
                ))}
                <label>
                    <input type="checkbox" className="form-check-input" checked={form.is_public} onChange={(e) => set('is_public', e.target.checked)} />
                    Show on the website
                </label>
            </div>

            <div className="sxa-section-title">Highlights for the website — one per line</div>
            <textarea className="form-control" rows={3} value={form.highlights} onChange={(e) => set('highlights', e.target.value)} />

            {failure && <div className="alert alert-danger py-2 mt-3 mb-0">{failure}</div>}
        </Modal>
    );
}
