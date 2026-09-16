import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, LoadingBlock, StatusBadge } from '@/shared/components/ui/Feedback';
import LocationFormPage from '@/core/locations/pages/LocationFormPage';
import { locationsHooks } from '@/core/locations/api';
import type { Location, LocationType } from '@/core/locations/types';
import { SectionShell, type SectionProps } from '../components/SectionShell';

const TYPE_LABELS: Record<LocationType, string> = {
    CLINIC: 'Clinic',
    RETAIL_STORE: 'Retail store',
    WHOLESALE_STORE: 'Wholesale store',
    WAREHOUSE: 'Warehouse',
    DOCTOR_VISITING_LOCATION: 'Visiting location',
};

/**
 * The clinics and branches, with the branch form itself opening in place —
 * the same form, fields and rules as Branches, not a second copy of them.
 */
export function BranchesSection({ step, onDirty, nav }: SectionProps) {
    const { data: branches, isLoading } = locationsHooks.useList({ per_page: 100 });
    const [editing, setEditing] = useState<'new' | number | null>(null);

    // An open form is work in progress.
    useEffect(() => onDirty(editing !== null), [editing, onDirty]);

    return (
        <SectionShell
            step={step}
            nav={nav}
            actions={
                // With none yet, the empty state carries the one "Add a branch".
                editing === null && (branches ?? []).length > 0 && (
                    <Button icon="ti ti-building-plus" onClick={() => setEditing('new')}>
                        Add a branch
                    </Button>
                )
            }
        >
            {editing !== null ? (
                <div className="su-embed">
                    <div className="su-embed-head">
                        <b>{editing === 'new' ? 'Add a clinic or branch' : 'Edit branch'}</b>
                    </div>

                    <LocationFormPage
                        id={editing === 'new' ? undefined : String(editing)}
                        onDone={() => setEditing(null)}
                    />
                </div>
            ) : isLoading ? (
                <LoadingBlock label="Loading branches…" />
            ) : (branches ?? []).length === 0 ? (
                <EmptyState
                    icon="ti ti-building-hospital"
                    title="No clinics or branches yet"
                    description="Add the first place you see patients or keep stock. You need at least one active branch to finish setup."
                    action={
                        <Button icon="ti ti-building-plus" onClick={() => setEditing('new')}>
                            Add a branch
                        </Button>
                    }
                />
            ) : (
                <>
                    <p className="su-lead">
                        Every appointment, patient registration and stock record belongs to one of these.
                        Switch a branch off rather than removing it once it has history.
                    </p>

                    <div className="su-table">
                        <table className="table align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th>City</th>
                                    <th>Status</th>
                                    <th aria-label="Actions" />
                                </tr>
                            </thead>
                            <tbody>
                                {(branches ?? []).map((branch: Location) => (
                                    <tr key={branch.id}>
                                        <td className="fw-semibold">{branch.name}</td>
                                        <td>{branch.code}</td>
                                        <td>{TYPE_LABELS[branch.type] ?? branch.type}</td>
                                        <td>{branch.city ?? '—'}</td>
                                        <td>
                                            <StatusBadge active={branch.is_active} />
                                        </td>
                                        <td className="text-end">
                                            <Button
                                                variant="light"
                                                size="sm"
                                                icon="ti ti-edit"
                                                onClick={() => setEditing(branch.id)}
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
