import type { ReactNode } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { STEP_LEAD, STEP_META, type SetupStatus, type SetupStep, type SetupStepKey } from '../types';

/** Moving between steps. `force` skips the unsaved-changes question, for a save that just succeeded. */
export interface SectionNav {
    prev: SetupStepKey | null;
    next: SetupStepKey | null;
    /** Where this step sits, for "Step 2 of 7". */
    index: number;
    total: number;
    go: (key: SetupStepKey, force?: boolean) => void;
}

/** What every step is handed by the page. */
export interface SectionProps {
    status: SetupStatus;
    step: SetupStep;
    /** Tell the page whether leaving now would lose something typed. */
    onDirty: (dirty: boolean) => void;
    nav: SectionNav;
}

/** The status a step is in, in words — for the final review. */
export function StepPill({ step }: { step: SetupStep }) {
    if (step.completed) {
        return (
            <span className="su-pill is-done">
                <i className="ti ti-check" aria-hidden="true" />
                Completed
            </span>
        );
    }

    return step.mandatory ? (
        <span className="su-pill is-required">
            <i className="ti ti-alert-triangle" aria-hidden="true" />
            Required
        </span>
    ) : (
        <span className="su-pill">Optional</span>
    );
}

/** A titled group inside a step — "Basic Details", "Address". */
export function SetupPanel({
    icon,
    title,
    aside,
    children,
}: {
    icon: string;
    title: string;
    /** Something beside the title: a count, a button. */
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="su-panel">
            <div className="su-panel-head">
                <span className="su-panel-icon" aria-hidden="true">
                    <i className={icon} />
                </span>
                <b>{title}</b>
                {aside && <div className="su-panel-aside">{aside}</div>}
            </div>

            {children}
        </div>
    );
}

/**
 * One step's frame: "Step 2 of 7", its title and purpose, what is still
 * missing, the content, and the buttons — Back on the left, the step's own
 * actions and its main button on the right.
 */
export function SectionShell({
    step,
    nav,
    actions,
    primary,
    children,
}: {
    step: SetupStep;
    nav: SectionNav;
    /** Secondary buttons, before the main one: Save, Add a branch. */
    actions?: ReactNode;
    /** The main button. Left out, it is "Continue"; null for none. */
    primary?: ReactNode;
    children: ReactNode;
}) {
    const meta = STEP_META[step.key];

    const continueButton = nav.next && (
        <Button onClick={() => nav.go(nav.next!)}>
            {step.mandatory || step.completed ? 'Continue' : 'Skip for now'}
            <i className="ti ti-arrow-right ms-1" aria-hidden="true" />
        </Button>
    );

    return (
        <section className="su-card" aria-labelledby="su-section-title">
            <header className="su-card-head">
                <span className="su-step-pill">
                    Step {nav.index + 1} of {nav.total}
                </span>
                <h2 id="su-section-title" tabIndex={-1}>
                    {meta.title}
                </h2>
                <p>{STEP_LEAD[step.key]}</p>
            </header>

            {/*
                Said in words, not only by a mark on the steps: this is what
                stands between the step and "completed".
            */}
            {!step.completed && step.missing.length > 0 && (
                <div className={`su-missing${step.mandatory ? ' is-required' : ''}`} role="status">
                    <i
                        className={step.mandatory ? 'ti ti-alert-triangle' : 'ti ti-info-circle'}
                        aria-hidden="true"
                    />
                    <ul>
                        {step.missing.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="su-card-body">{children}</div>

            <footer className="su-card-foot">
                <div>
                    {nav.prev && (
                        <Button variant="light" icon="ti ti-arrow-left" onClick={() => nav.go(nav.prev!)}>
                            Back
                        </Button>
                    )}
                </div>

                <div className="su-foot-acts">
                    {actions}
                    {primary === undefined ? continueButton : primary}
                </div>
            </footer>
        </section>
    );
}

export type { SetupStatus };
