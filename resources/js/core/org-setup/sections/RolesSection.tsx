import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { rolesHooks } from '@/core/roles/api';
import { useConfirmSetupStep } from '../api';
import { SectionShell, type SectionProps } from '../components/SectionShell';

/**
 * What each kind of job may do.
 *
 * Read here; written in the roles editor, which already handles scopes,
 * branch roles and the capability grid — a second copy of it would drift.
 * Reviewing is a sign-off the admin gives once they have looked.
 */
export function RolesSection({ step, nav }: SectionProps) {
    const { data: roles, isLoading } = rolesHooks.useList();
    const confirm = useConfirmSetupStep();
    const [problem, setProblem] = useState<string | null>(null);

    async function review(then?: () => void) {
        setProblem(null);

        try {
            await confirm.mutateAsync('roles');
            notify.success('Roles marked as reviewed');
            then?.();
        } catch (error) {
            setProblem(resolveErrorMessage(error));
        }
    }

    return (
        <SectionShell
            step={step}
            nav={nav}
            actions={
                <Link to="/roles" className="btn btn-light">
                    <i className="ti ti-external-link me-1" aria-hidden="true" />
                    Open roles editor
                </Link>
            }
            primary={
                <Button loading={confirm.isPending} onClick={() => void review(() => nav.next && nav.go(nav.next, true))}>
                    {step.completed ? 'Continue' : 'Mark Reviewed & Continue'}
                    <i className="ti ti-arrow-right ms-1" aria-hidden="true" />
                </Button>
            }
        >
            <p className="su-lead">
                Every organisation starts with a Staff role, and doctor logins get a Doctor role of their
                own. Add a role for each kind of job — receptionist, pharmacist, manager — and give it only
                what that job needs. The owner is never limited by a role.
            </p>

            {isLoading ? (
                <LoadingBlock label="Loading roles…" />
            ) : (roles ?? []).length === 0 ? (
                <EmptyState icon="ti ti-shield-lock" title="No roles yet" description="Create the first one in the roles editor." />
            ) : (
                <div className="su-table">
                    <table className="table align-middle">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Applies to</th>
                                <th className="text-end">Permissions</th>
                                <th className="text-end">People</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(roles ?? []).map((role) => (
                                <tr key={role.id}>
                                    <td>
                                        <span className="fw-semibold">
                                            <i className={`${role.icon} me-2`} aria-hidden="true" />
                                            {role.name}
                                        </span>
                                        {role.description && (
                                            <small className="d-block text-muted">{role.description}</small>
                                        )}
                                    </td>
                                    <td>
                                        {role.scope === 'organization'
                                            ? 'Whole organisation'
                                            : (role.location ?? 'One branch')}
                                    </td>
                                    <td className="text-end">{role.capabilities.length}</td>
                                    <td className="text-end">{role.users_count ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {problem && (
                <p className="su-error" role="alert">
                    {problem}
                </p>
            )}
        </SectionShell>
    );
}
