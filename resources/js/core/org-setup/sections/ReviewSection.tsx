import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/shared/components/ui/Button';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useCompleteSetup } from '../api';
import { SectionShell, StepPill, type SectionProps } from '../components/SectionShell';
import { STEP_META, type SetupStepKey } from '../types';

/** "15 Sep 2026" */
function day(value: string): string {
    return new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Everything at once, and the button that finishes it.
 *
 * The server checks every required section again on completion; what it
 * refuses is shown against the section it names. Finishing opens the rest of
 * the workspace, which stays closed until then.
 */
export function ReviewSection({ status, step, nav }: SectionProps) {
    const complete = useCompleteSetup();
    const navigate = useNavigate();
    const { markSetupCompleted } = useTenantAuth();

    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [problem, setProblem] = useState<string | null>(null);

    const sections = status.steps.filter((entry) => entry.key !== 'review');
    const required = sections.filter((entry) => entry.mandatory);
    const counts = (key: SetupStepKey) => status.steps.find((entry) => entry.key === key)?.counts ?? {};

    async function finish() {
        setErrors({});
        setProblem(null);

        try {
            await complete.mutateAsync();
            markSetupCompleted();
            navigate('/dashboard');
        } catch (error) {
            const found = getValidationErrors(error);

            if (found) setErrors(found);
            else setProblem(resolveErrorMessage(error));
        }
    }

    const figures = [
        { label: 'Active branches', value: counts('branches').active ?? 0, icon: 'ti ti-building-hospital' },
        { label: 'Departments', value: counts('departments').departments ?? 0, icon: 'ti ti-layout-grid' },
        { label: 'Staff', value: counts('users').staff ?? 0, icon: 'ti ti-users-group' },
        { label: 'Roles', value: counts('roles').roles ?? 0, icon: 'ti ti-shield-lock' },
    ];

    return (
        // No main footer button: completing the setup is the button in the body.
        <SectionShell step={step} nav={nav} primary={null}>
            <div className="su-figures">
                {figures.map((figure) => (
                    <div className="su-figure" key={figure.label}>
                        <i className={figure.icon} aria-hidden="true" />
                        <b>{figure.value}</b>
                        <span>{figure.label}</span>
                    </div>
                ))}
            </div>

            <ul className="su-review">
                {sections.map((entry) => {
                    const refused = errors[`steps.${entry.key}`];

                    return (
                        <li key={entry.key} className={refused ? 'is-refused' : undefined}>
                            <span className={`su-mark is-${entry.status}`} aria-hidden="true">
                                {entry.completed ? (
                                    <i className="ti ti-check" />
                                ) : (
                                    <i className={STEP_META[entry.key].icon} />
                                )}
                            </span>

                            <span className="su-review-text">
                                <b>{STEP_META[entry.key].title}</b>
                                <small>
                                    {entry.completed
                                        ? 'Done.'
                                        : (refused ?? entry.missing).join(' ') || 'Not done yet.'}
                                </small>
                            </span>

                            <StepPill step={entry} />

                            <Button variant="light" size="sm" onClick={() => nav.go(entry.key)}>
                                {entry.completed ? 'Open' : 'Finish'}
                            </Button>
                        </li>
                    );
                })}
            </ul>

            {status.completed_at ? (
                <div className="su-finished" role="status">
                    <i className="ti ti-circle-check" aria-hidden="true" />
                    <div>
                        <b>Setup completed on {day(status.completed_at)}</b>
                        <p>You can come back and change any section whenever you need to.</p>
                    </div>
                    <Button icon="ti ti-layout-dashboard" onClick={() => navigate('/dashboard')}>
                        Go to dashboard
                    </Button>
                </div>
            ) : (
                <div className="su-finish">
                    <p>
                        {required.filter((entry) => entry.completed).length} of {required.length} required
                        sections done.
                        {status.can_complete
                            ? ' Everything required is in place. Completing opens the rest of the workspace.'
                            : ' Finish the sections marked Required to complete setup.'}
                    </p>

                    {problem && <p className="su-error">{problem}</p>}

                    <Button icon="ti ti-rosette-discount-check" loading={complete.isPending} onClick={() => void finish()}>
                        Complete Organisation Setup
                    </Button>
                </div>
            )}
        </SectionShell>
    );
}
