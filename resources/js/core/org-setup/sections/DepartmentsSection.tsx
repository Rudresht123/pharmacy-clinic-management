import { useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { DepartmentManager } from '@/core/departments/components/DepartmentManager';
import { useConfirmSetupStep } from '../api';
import { SectionShell, type SectionProps } from '../components/SectionShell';

/**
 * The departments doctors are grouped by, and their sub-departments — the
 * same tree as the Departments page. Each change saves as it is made;
 * confirming signs the list off for setup.
 */
export function DepartmentsSection({ step, nav }: SectionProps) {
    const confirm = useConfirmSetupStep();
    const [problem, setProblem] = useState<string | null>(null);

    async function review(then?: () => void) {
        setProblem(null);

        try {
            await confirm.mutateAsync('departments');
            notify.success('Departments confirmed');
            then?.();
        } catch (error) {
            setProblem(resolveErrorMessage(error));
        }
    }

    return (
        <SectionShell
            step={step}
            nav={nav}
            primary={
                <Button loading={confirm.isPending} onClick={() => void review(() => nav.next && nav.go(nav.next, true))}>
                    {step.completed ? 'Continue' : 'Confirm & Continue'}
                    <i className="ti ti-arrow-right ms-1" aria-hidden="true" />
                </Button>
            }
        >
            <DepartmentManager />

            {problem && (
                <p className="su-error" role="alert">
                    {problem}
                </p>
            )}
        </SectionShell>
    );
}
