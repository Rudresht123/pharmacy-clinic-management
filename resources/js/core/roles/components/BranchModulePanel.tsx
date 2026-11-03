import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useBranchModules, useSaveBranchModules } from '../api';
import { toModuleSections } from '../moduleSections';

/**
 * Level two of the permission flow: which of the organization's modules this
 * branch actually runs.
 *
 * Every module the organization holds appears, filed the way the sidebar is.
 * Branches and Configuration show with a padlock: they belong to the
 * organization, so there is no such thing as switching them off at one branch.
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
        // Only what has a switch here: the rest is not this branch's to say.
        const fixed = (modules ?? []).filter((module) => module.is_org_level).map((module) => module.key);

        try {
            await save.mutateAsync(on.filter((key) => !fixed.includes(key)));
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
                        Your organization has no modules yet. They appear here as soon as your ZEVRO
                        administrator grants them.
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
                {toModuleSections(modules ?? []).map((section) => (
                    <section className="bm-section" key={section.title || 'top'}>
                        {section.title && <h6 className="bm-heading">{section.title}</h6>}

                        <div className="bm-list">
                            {section.modules.map((module) => {
                                const enabled = on.includes(module.key);
                                const offForOrganization = module.organization_enabled === false;

                                return (
                                    <label
                                        className={`bm-item${enabled ? ' is-on' : ''}${module.is_locked || module.is_org_level || offForOrganization ? ' is-locked' : ''}`}
                                        key={module.key}
                                    >
                                        <span className="rp-module-icon is-slate" aria-hidden="true">
                                            <i className={module.icon ?? 'ti ti-puzzle'} />
                                        </span>

                                        <span className="bm-text">
                                            <b>{module.name}</b>
                                            <small>{module.description}</small>
                                        </span>

                                        {/*
                                            A padlock instead of a switch wherever
                                            this branch has no say: a control that
                                            is always refused on save is worse than
                                            no control. In the off position when
                                            the whole organisation switched it off.
                                        */}
                                        {offForOrganization ? (
                                            <span
                                                className="rp-lock"
                                                title="Switched off for your organisation"
                                            >
                                                <i className="ti ti-lock-off" aria-hidden="true" />
                                            </span>
                                        ) : module.is_org_level ? (
                                            <span
                                                className="rp-lock is-on"
                                                title="Belongs to the whole organisation, not to one branch"
                                            >
                                                <i className="ti ti-lock" aria-hidden="true" />
                                            </span>
                                        ) : module.is_locked ? (
                                            <span
                                                className="rp-lock is-on"
                                                title="Required by your organisation"
                                            >
                                                <i className="ti ti-lock" aria-hidden="true" />
                                            </span>
                                        ) : (
                                            <span className="ma-switch">
                                                <input
                                                    type="checkbox"
                                                    checked={enabled}
                                                    aria-label={`Run ${module.name} at this branch`}
                                                    onChange={() => toggle(module.key)}
                                                />
                                                <span className="ma-track" aria-hidden="true" />
                                            </span>
                                        )}
                                    </label>
                                );
                            })}
                        </div>
                    </section>
                ))}

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
