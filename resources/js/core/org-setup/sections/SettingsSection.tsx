import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useFieldSettings, useSaveFieldSettings } from '@/core/field-settings/api';
import { BranchModulePanel } from '@/core/roles/components/BranchModulePanel';
import { locationsHooks } from '@/core/locations/api';
import { useConfirmSetupStep } from '../api';
import { toFieldInputs } from '../fieldInputs';
import { SectionShell, type SectionProps } from '../components/SectionShell';

const PRESETS = [
    { singular: 'Patient', plural: 'Patients' },
    { singular: 'Customer', plural: 'Customers' },
    { singular: 'Client', plural: 'Clients' },
];

/**
 * How the clinic runs: what it calls the people it serves, and which modules
 * each branch switches on. Both are settings that already exist and are
 * saved through their own endpoints; this section gathers them.
 */
export function SettingsSection({ step, onDirty, nav }: SectionProps) {
    const customer = useFieldSettings('customer');
    const saveCustomer = useSaveFieldSettings('customer');
    const confirm = useConfirmSetupStep();
    const { data: branches, isLoading: branchesLoading } = locationsHooks.useList({ per_page: 100 });

    const [singular, setSingular] = useState('');
    const [plural, setPlural] = useState('');
    const [loaded, setLoaded] = useState(false);
    const [branchId, setBranchId] = useState<number | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => {
        if (customer.data && !loaded) {
            setSingular(customer.data.singular);
            setPlural(customer.data.label);
            setLoaded(true);
        }
    }, [customer.data, loaded]);

    const active = (branches ?? []).filter((branch) => branch.is_active);

    useEffect(() => {
        if (branchId === null && active.length > 0) setBranchId(active[0].id);
    }, [active, branchId]);

    const changed =
        loaded && (singular !== customer.data?.singular || plural !== customer.data?.label);

    useEffect(() => onDirty(changed), [changed, onDirty]);

    async function persist(then?: () => void) {
        setProblem(null);

        const one = singular.trim();
        const many = plural.trim();

        if (!one || !many) {
            setProblem('Say what you call one of them and several of them.');

            return;
        }

        try {
            if (changed && customer.data) {
                await saveCustomer.mutateAsync({
                    fields: toFieldInputs(customer.data.fields),
                    label: { singular: one, plural: many },
                });
            }

            await confirm.mutateAsync('settings');

            if (!changed) notify.success('Clinic settings confirmed');

            setSingular(one);
            setPlural(many);
            then?.();
        } catch (error) {
            const found = getValidationErrors(error);

            setProblem(found ? Object.values(found).flat()[0] : resolveErrorMessage(error));
        }
    }

    const busy = saveCustomer.isPending || confirm.isPending;

    return (
        <SectionShell
            step={step}
            nav={nav}
            actions={
                <Button variant="light" icon="ti ti-device-floppy" loading={busy} onClick={() => void persist()}>
                    Save
                </Button>
            }
            primary={
                <Button loading={busy} onClick={() => void persist(() => nav.next && nav.go(nav.next, true))}>
                    Save &amp; Continue
                    <i className="ti ti-arrow-right ms-1" aria-hidden="true" />
                </Button>
            }
        >
            <div className="su-block">
                <h3>What you call the people you serve</h3>
                <p>Used across every screen, menu and list — clinics usually say patient, pharmacies customer.</p>

                {customer.isLoading ? (
                    <LoadingBlock />
                ) : (
                    <>
                        <div className="su-chips">
                            {PRESETS.map((preset) => (
                                <Button
                                    key={preset.singular}
                                    variant="light"
                                    size="sm"
                                    onClick={() => {
                                        setSingular(preset.singular);
                                        setPlural(preset.plural);
                                    }}
                                >
                                    {preset.singular} / {preset.plural}
                                </Button>
                            ))}
                        </div>

                        <div className="su-pair">
                            <div>
                                <label className="form-label" htmlFor="su-singular">
                                    One <span className="text-danger">*</span>
                                </label>
                                <input
                                    id="su-singular"
                                    type="text"
                                    className="form-control"
                                    maxLength={60}
                                    value={singular}
                                    onChange={(event) => setSingular(event.target.value)}
                                />
                            </div>
                            <div>
                                <label className="form-label" htmlFor="su-plural">
                                    Several <span className="text-danger">*</span>
                                </label>
                                <input
                                    id="su-plural"
                                    type="text"
                                    className="form-control"
                                    maxLength={60}
                                    value={plural}
                                    onChange={(event) => setPlural(event.target.value)}
                                />
                            </div>
                        </div>
                    </>
                )}

                {problem && (
                    <p className="su-error" role="alert">
                        {problem}
                    </p>
                )}
            </div>

            <div className="su-block">
                <h3>Modules at each branch</h3>
                <p>
                    Everything your organisation has is on at every branch until you switch it off. A
                    pharmacy counter might not run OPD; a clinic might not keep stock. Saved per branch.
                </p>

                {branchesLoading ? (
                    <LoadingBlock />
                ) : active.length === 0 ? (
                    <p className="su-lead">Add an active branch first — then choose what it runs here.</p>
                ) : (
                    <>
                        <div style={{ maxWidth: 320 }}>
                            <label className="form-label" htmlFor="su-branch">
                                Branch
                            </label>
                            <select
                                id="su-branch"
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
            </div>
        </SectionShell>
    );
}
