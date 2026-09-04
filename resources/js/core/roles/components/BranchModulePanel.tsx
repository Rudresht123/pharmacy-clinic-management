import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useBranchModules, useSaveBranchModules } from '../api';

/**
 * Level two of the permission flow: which of the organization's modules this
 * branch actually runs.
 *
 * Only modules the organization holds appear, and core ones never do — a
 * branch with no customers or no staff is not a smaller branch, it is a
 * broken one.
 *
 * Everything starts switched on. The table behind this is a sparse override
 * storing only what was turned off, so a branch nobody has configured keeps
 * inheriting whatever the organization buys next, instead of silently missing
 * it.
 */
export function BranchModulePanel({ locationId }: { locationId: number }) {
    const { data: modules, isLoading } = useBranchModules(locationId);
    const save = useSaveBranchModules(locationId);

    const [on, setOn] = useState<string[]>([]);
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (modules) {
            setOn(modules.filter((module) => module.is_enabled).map((module) => module.key));
            setDirty(false);
        }
    }, [modules]);

    function toggle(key: string) {
        setDirty(true);
        setOn((current) =>
            current.includes(key) ? current.filter((held) => held !== key) : [...current, key],
        );
    }

    async function onSave() {
        try {
            await save.mutateAsync(on);
            setDirty(false);
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Loading modules…" />;
    }

    if ((modules ?? []).length === 0) {
        return (
            <div className="rp-panel">
                <div className="org-pending">
                    <i className="ti ti-puzzle" />
                    <h6>Nothing to choose from</h6>
                    <p>
                        Your organization has only the modules every organization gets, and those
                        run at every branch. Optional modules appear here once you have them.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="rp-panel">
            <header className="rp-head">
                <div className="rp-head-top">
                    <div className="rp-head-text">
                        <h5>Modules at this branch</h5>

                        <span className="rp-head-meta">
                            <em>{on.length}</em> of {(modules ?? []).length} running here
                        </span>
                    </div>

                    <div className="rp-head-actions">
                        <Button
                            variant={dirty ? undefined : 'light'}
                            icon={dirty ? 'ti ti-device-floppy' : 'ti ti-circle-check'}
                            loading={save.isPending}
                            disabled={!dirty}
                            onClick={onSave}
                        >
                            {dirty ? 'Save changes' : 'Saved'}
                        </Button>
                    </div>
                </div>
            </header>

            <div className="rp-body">
                <div className="bm-list">
                    {(modules ?? []).map((module) => {
                        const enabled = on.includes(module.key);

                        return (
                            <label
                                className={`bm-item${enabled ? ' is-on' : ''}`}
                                key={module.key}
                            >
                                <span className="rp-module-icon is-slate" aria-hidden="true">
                                    <i className={module.icon ?? 'ti ti-puzzle'} />
                                </span>

                                <span className="bm-text">
                                    <b>{module.name}</b>
                                    <small>{module.description}</small>
                                </span>

                                <span className="ma-switch">
                                    <input
                                        type="checkbox"
                                        checked={enabled}
                                        aria-label={`Run ${module.name} at this branch`}
                                        onChange={() => toggle(module.key)}
                                    />
                                    <span className="ma-track" aria-hidden="true" />
                                </span>
                            </label>
                        );
                    })}
                </div>

                <p className="bm-note">
                    <i className="ti ti-info-circle" />
                    <span>
                        Switching one off hides it for everybody who works at this branch, and the
                        API refuses it too. The owner works across the whole network, so it does
                        not limit them.
                    </span>
                </p>
            </div>
        </div>
    );
}
