import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, LoadingBlock, StatusBadge } from '@/shared/components/ui/Feedback';
import TenantUserFormPage from '@/core/tenant-users/pages/TenantUserFormPage';
import { tenantUsersHooks } from '@/core/tenant-users/api';
import { SectionShell, type SectionProps } from '../components/SectionShell';

/**
 * The people who sign in, with the staff form opening in place. Optional:
 * an owner running the organization alone is a real organization.
 */
export function StaffSection({ step, onDirty, nav }: SectionProps) {
    const { data: people, isLoading } = tenantUsersHooks.useList({ per_page: 100 });
    const [editing, setEditing] = useState<'new' | number | null>(null);

    useEffect(() => onDirty(editing !== null), [editing, onDirty]);

    return (
        <SectionShell
            step={step}
            nav={nav}
            actions={
                editing === null && (
                    <Button icon="ti ti-user-plus" onClick={() => setEditing('new')}>
                        Add a person
                    </Button>
                )
            }
        >
            {editing !== null ? (
                <div className="su-embed">
                    <div className="su-embed-head">
                        <b>{editing === 'new' ? 'Add a person' : 'Edit person'}</b>
                    </div>

                    <TenantUserFormPage
                        id={editing === 'new' ? undefined : String(editing)}
                        onDone={() => setEditing(null)}
                    />
                </div>
            ) : isLoading ? (
                <LoadingBlock label="Loading people…" />
            ) : (people ?? []).length === 0 ? (
                <EmptyState
                    icon="ti ti-users-group"
                    title="Nobody else signs in yet"
                    description="Add receptionists, pharmacists and managers when they need their own login."
                />
            ) : (
                <>
                    <p className="su-lead">
                        Each person signs in with their own email and gets a role that decides what they may
                        do. Give someone a branch from their record once they are added.
                    </p>

                    <div className="su-table">
                        <table className="table align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th aria-label="Actions" />
                                </tr>
                            </thead>
                            <tbody>
                                {(people ?? []).map((person) => (
                                    <tr key={person.id}>
                                        <td className="fw-semibold">{person.name}</td>
                                        <td>{person.email}</td>
                                        <td>{person.role === 'owner' ? 'Owner' : (person.role_name ?? '—')}</td>
                                        <td>
                                            <StatusBadge active={person.is_active} />
                                        </td>
                                        <td className="text-end">
                                            <Button
                                                variant="light"
                                                size="sm"
                                                icon="ti ti-edit"
                                                onClick={() => setEditing(person.id)}
                                            >
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </SectionShell>
    );
}
