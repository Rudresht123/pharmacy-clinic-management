import { useEffect, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { locationsHooks } from '@/core/locations/api';
import { BranchModulePanel } from '../components/BranchModulePanel';
import { ModuleLockPanel } from '../components/ModuleLockPanel';
import { OrganizationModulePanel } from '../components/OrganizationModulePanel';

/**
 * Which modules run where — every level the owner decides, on one screen.
 *
 *   1. The organisation: which of the modules it was sold it uses at all.
 *      Off here is off at every branch.
 *   2. Each branch: which of those it runs. Only ever narrows.
 *   3. Compulsory: which of them no branch may switch off.
 *
 * The super admin's level — what was sold — is not here: it is a commercial
 * decision made on the platform, and only modules that were sold appear.
 *
 * These panels also sit inside organisation setup, where the owner first meets
 * them. This page is where they are found afterwards, from Settings, without
 * walking back through a setup that is already finished.
 */
export default function ModuleSettingsPage() {
    const { data: branches, isLoading } = locationsHooks.useList({ per_page: 100 });
    const active = (branches ?? []).filter((branch) => branch.is_active);
    const [branchId, setBranchId] = useState<number | null>(null);

    useEffect(() => {
        if (branchId === null && active.length > 0) setBranchId(active[0].id);
    }, [active, branchId]);

    return (
        <>
            <PageHeader
                title="Modules"
                icon="ti ti-puzzle"
                tone="indigo"
                crumbs={[{ label: 'Settings' }, { label: 'Modules' }]}
                subtitle="What your organisation uses, and where. A branch can only choose among what the organisation itself uses."
            />

            <div className="d-flex flex-column gap-3">
                <OrganizationModulePanel />

                <Card
                    title="Modules at each branch"
                    icon="ti ti-building-store"
                    description="Everything your organisation uses is on at every branch until you switch it off here."
                >
                    {isLoading ? (
                        <LoadingBlock />
                    ) : active.length === 0 ? (
                        <p className="mb-0">Add an active branch first — then choose what it runs here.</p>
                    ) : (
                        <>
                            <div className="mb-3" style={{ maxWidth: 320 }}>
                                <label className="form-label" htmlFor="ms-branch">
                                    Branch
                                </label>
                                <select
                                    id="ms-branch"
                                    className="form-select"
                                    value={branchId ?? ''}
                                    onChange={(event) => setBranchId(Number(event.target.value))}
                                >
                                    {active.map((branch) => (
                                        <option key={branch.id} value={branch.id}>
                                            {branch.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {branchId !== null && <BranchModulePanel key={branchId} locationId={branchId} />}
                        </>
                    )}
                </Card>

                <ModuleLockPanel />
            </div>
        </>
    );
}
