import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import type { AutomationRule } from '../types';

/** "1440 minutes before" is not how anybody says a day. */
function lead(minutes: number | null): string | null {
    if (minutes === null || minutes === 0) {
        return null;
    }

    if (minutes % 1440 === 0) {
        const days = minutes / 1440;

        return `${days} day${days === 1 ? '' : 's'} before`;
    }

    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return `${hours} hour${hours === 1 ? '' : 's'} before`;
    }

    return `${minutes} minutes before`;
}

/**
 * What sends itself, and what a clinic has chosen to keep manual.
 *
 * The switch is the row's point, so it sits where the eye lands after reading
 * what the rule does. Each row also says WHEN it fires and WHAT it sends,
 * because a rule switched on with no template is the one failure mode that is
 * otherwise invisible until nothing arrives — the server refuses it, and the
 * row shows why before anybody tries.
 */
export function AutomationRules({
    rules,
    onToggle,
    onEdit,
    description,
    saving,
}: {
    rules: AutomationRule[];
    /**
     * Both absent for somebody who may only read this list.
     *
     * The switch then renders locked rather than vanishing: what a clinic has
     * automated is worth seeing from a desk, and a rule with no visible state
     * would be a list of titles. Editing simply is not offered.
     */
    onToggle?: (rule: AutomationRule) => void;
    onEdit?: (rule: AutomationRule) => void;
    description: string;
    saving?: boolean;
}) {
    return (
        <Card
            className="comm-card comm-rules-card"
            title="Automation rules"
            icon="ti ti-settings-automation"
            description={description}
        >
            {rules.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-mood-empty" aria-hidden="true" />
                    No automation set up for this channel yet.
                </p>
            ) : (
                <ul className="comm-rules">
                    {rules.map((rule) => {
                        const timing = lead(rule.lead_minutes);

                        return (
                            <li key={rule.id}>
                                <i className={rule.icon ?? 'ti ti-bolt'} aria-hidden="true" />

                                <span className="comm-rule-text">
                                    <b>{rule.title}</b>
                                    <small>{rule.description}</small>

                                    <span className="comm-rule-meta">
                                        {timing && (
                                            <span className="comm-tag">
                                                <i className="ti ti-clock" aria-hidden="true" />
                                                {timing}
                                            </span>
                                        )}

                                        {rule.template_name ? (
                                            <span className="comm-tag">{rule.template_name}</span>
                                        ) : (
                                            <span className="comm-pill is-wait">No template</span>
                                        )}
                                    </span>
                                </span>

                                <div className="form-check form-switch m-0">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id={`rule-${rule.id}`}
                                        checked={rule.is_enabled}
                                        disabled={saving || !onToggle}
                                        onChange={() => onToggle?.(rule)}
                                    />

                                    <label className="visually-hidden" htmlFor={`rule-${rule.id}`}>
                                        {rule.title}
                                    </label>
                                </div>

                                {onEdit && (
                                    <Button variant="light" size="sm" onClick={() => onEdit(rule)}>
                                        Edit
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}
