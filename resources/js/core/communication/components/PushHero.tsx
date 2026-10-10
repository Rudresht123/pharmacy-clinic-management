import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import type { MessageTemplate, SetupStep } from '../types';

/** One icon per checklist step, so the row reads without its labels. */
const STEP_ICON: Record<string, string> = {
    account: 'ti ti-power',
    handle: 'ti ti-cloud-check',
    templates: 'ti ti-template',
    test: 'ti ti-send',
};

/**
 * The App notifications channel at a glance: is it on, and what still stands
 * between it and a patient's phone.
 *
 * Not the email/WhatsApp connection card. Those describe an account the
 * clinic connected — a sender address, a business number. App notifications
 * have neither: the push service is the platform's, and the only thing the
 * clinic decides is whether it is on. So the card is a switch and a
 * checklist, and says nothing about "connecting an account".
 */
export function PushHero({
    on,
    steps,
    rulesOn,
    rulesTotal,
    toggling,
    testing,
    templates,
    templateId,
    onToggle,
    onTest,
    onTemplateChange,
}: {
    on: boolean;
    steps: SetupStep[];
    rulesOn: number;
    rulesTotal: number;
    toggling?: boolean;
    testing?: boolean;
    /** Every push template there is to pick from, for the test below. */
    templates?: MessageTemplate[];
    /** Which one the test will use — null for a plain "this works" push. */
    templateId?: number | null;
    /** Absent for somebody who may look but not change it. */
    onToggle?: () => void;
    onTest?: () => void;
    onTemplateChange?: (id: number | null) => void;
}) {
    const done = steps.filter((step) => step.done).length;
    const ready = steps.length > 0 && done === steps.length;

    return (
        <Card className="ph-hero">
            <div className="ph-hero-top">
                <span className={`ph-hero-mark${on ? ' is-on' : ''}`} aria-hidden="true">
                    <i className="ti ti-bell-ringing" />
                </span>

                <div className="ph-hero-text">
                    <div className="ph-hero-title">
                        <h2>App notifications</h2>
                        <span className={`ph-state${on ? ' is-on' : ''}`}>
                            <span className="ph-state-dot" />
                            {on ? 'On' : 'Off'}
                        </span>
                    </div>

                    <p>
                        {on
                            ? 'Patients signed in to your clinic’s app are notified on their phone by the rules you switch on.'
                            : 'Switched off — no patient gets a notification until you switch it on.'}
                    </p>
                </div>

                <div className="ph-hero-actions">
                    {onTest && (
                        <>
                            {templates && templates.length > 0 && (
                                <select
                                    className="form-select ph-test-template"
                                    aria-label="Template to test"
                                    disabled={testing}
                                    value={templateId ?? ''}
                                    onChange={(event) =>
                                        onTemplateChange?.(event.target.value === '' ? null : Number(event.target.value))
                                    }
                                >
                                    <option value="">Plain test (no template)</option>
                                    {templates.map((template) => (
                                        <option key={template.id} value={template.id}>
                                            {template.name}
                                        </option>
                                    ))}
                                </select>
                            )}

                            <Button
                                variant="light"
                                icon="ti ti-device-mobile-message"
                                onClick={onTest}
                                loading={testing}
                                disabled={!on}
                                title={on ? 'Sends to your own phone, never a patient’s' : 'Switch the channel on first'}
                            >
                                Send a test to my phone
                            </Button>
                        </>
                    )}

                    {onToggle ? (
                        <button
                            type="button"
                            role="switch"
                            aria-checked={on}
                            className={`ph-switch${on ? ' is-on' : ''}`}
                            disabled={toggling}
                            onClick={onToggle}
                        >
                            <span className="ph-switch-track" aria-hidden="true">
                                <span className="ph-switch-thumb" />
                            </span>
                            {on ? 'Switched on' : 'Switch on'}
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="ph-ready">
                <div className="ph-ready-head">
                    <span>
                        <b>{ready ? 'Ready to notify patients' : 'Getting ready'}</b>
                        <small>
                            {done} of {steps.length} done · {rulesOn} of {rulesTotal} automations on
                        </small>
                    </span>

                    <span className="ph-ready-bar" aria-hidden="true">
                        <span style={{ width: `${steps.length ? Math.round((done / steps.length) * 100) : 0}%` }} />
                    </span>
                </div>

                <ol className="ph-steps">
                    {steps.map((step) => (
                        <li key={step.key} className={step.done ? 'is-done' : undefined}>
                            <span className="ph-step-icon" aria-hidden="true">
                                <i className={step.done ? 'ti ti-check' : (STEP_ICON[step.key] ?? 'ti ti-circle')} />
                            </span>

                            <span className="ph-step-text">
                                <b>{step.label}</b>
                                <small>{step.state}</small>
                            </span>
                        </li>
                    ))}
                </ol>
            </div>

            <p className="ph-hero-foot">
                <i className="ti ti-cloud" aria-hidden="true" />
                Delivered by Firebase Cloud Messaging
                <span aria-hidden="true">·</span>
                Free to send
                <span aria-hidden="true">·</span>
                Only patients signed in to your app
            </p>
        </Card>
    );
}
