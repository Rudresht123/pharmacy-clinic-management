import type { ReactNode } from 'react';
import { formatPaise } from '@/core/subscription/money';
import type { Plan } from '../api';
import { LABELS } from '../labels';

/** Plan accents, in order — colour on the name, icon and button, never the card. */
export const ACCENTS = ['emerald', 'violet', 'amber', 'sky', 'rose', 'indigo'] as const;

export type PlanFaceData = Pick<Plan, 'name' | 'description' | 'is_system' | 'monthly_price' | 'highlights' | 'limits' | 'modules' | 'features'>;

/**
 * The accent a plan wears: its place among the plans on the list, the same
 * on the Plans page and in the editor's preview. A new plan takes the next.
 */
export function accentFor(plans: Plan[], uuid: string | null): string {
    const listed = plans.filter((plan) => plan.status !== 'archived');
    const found = uuid ? listed.findIndex((plan) => plan.uuid === uuid) : -1;
    const plan = found >= 0 ? listed[found] : null;

    if (plan?.is_system) {
        return 'muted';
    }

    return ACCENTS[(found >= 0 ? found : listed.length) % ACCENTS.length];
}

/** The plan's own highlights, or — when none are written — its limits and contents. */
export function planPoints(plan: PlanFaceData): string[] {
    const written = plan.highlights.map((line) => line.trim()).filter(Boolean);

    if (written.length > 0) {
        return written.slice(0, 5);
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

/**
 * A plan as a card: name, price a month, what is in it. The list adds the
 * subscriber count and the actions underneath as children; the editor shows
 * it as the live preview.
 */
export function PlanFace({ plan, accent, popular = false, children }: { plan: PlanFaceData; accent: string; popular?: boolean; children?: ReactNode }) {
    return (
        <article className={`sxa-plan ${popular ? 'is-popular' : ''}`} data-accent={accent}>
            {popular && <span className="sxa-plan-badge">Most Popular</span>}

            <header>
                <h5>{plan.name || 'Plan name'}</h5>
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
                {planPoints(plan).map((line, index) => (
                    <li key={`${index}-${line}`}>
                        <i className="ti ti-check" aria-hidden="true" />
                        {line}
                    </li>
                ))}
            </ul>

            {children}
        </article>
    );
}
