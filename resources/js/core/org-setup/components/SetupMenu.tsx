import { STEP_META, type SetupStep, type SetupStepKey } from '../types';

/**
 * The steps down the left.
 *
 * Every step can be opened at any time — finishing is what the required ones
 * gate, not visiting. Done steps carry a green tick, the open one is
 * highlighted with a chevron, a required step still to do carries a warning
 * mark, and a line joins each step to the next.
 */
export function SetupMenu({
    id,
    steps,
    active,
    onSelect,
}: {
    id: string;
    steps: SetupStep[];
    active: SetupStepKey;
    onSelect: (key: SetupStepKey) => void;
}) {
    return (
        <nav id={id} className="su-menu" aria-label="Setup steps">
            <ol>
                {steps.map((step, index) => {
                    const meta = STEP_META[step.key];
                    const isActive = step.key === active;
                    const warn = !step.completed && step.mandatory && !isActive;

                    return (
                        <li key={step.key} className={step.completed ? 'is-done' : undefined}>
                            <button
                                type="button"
                                className={`su-item is-${step.status}${isActive ? ' is-active' : ''}`}
                                aria-current={isActive ? 'step' : undefined}
                                onClick={() => onSelect(step.key)}
                            >
                                <span className="su-mark" aria-hidden="true">
                                    {step.completed && !isActive ? <i className="ti ti-check" /> : index + 1}
                                </span>

                                <span className="su-item-text">
                                    <b>{meta.title}</b>
                                    <small>{meta.description}</small>
                                </span>

                                <span className="su-item-state">
                                    {isActive ? (
                                        <i className="ti ti-chevron-right" aria-hidden="true" />
                                    ) : warn ? (
                                        <i
                                            className="ti ti-alert-triangle is-warn"
                                            title="Required — not finished yet"
                                            aria-label="Required, not finished yet"
                                        />
                                    ) : step.completed ? (
                                        <span className="visually-hidden">Completed</span>
                                    ) : null}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
