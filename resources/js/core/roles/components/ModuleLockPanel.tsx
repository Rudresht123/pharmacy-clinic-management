import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useModuleLocks, useSaveModuleLocks } from '../api';

/**
 * Which modules every branch must run, whatever a branch manager thinks.
 *
 * The other half of the branch module screen. That one lets each branch opt
 * out of what the organization holds; this is the organization saying a module
 * is not optional — billing runs at every site, or the numbers the
 * organization reports are made of nothing.
 *
 * Locking one switches it back on wherever a branch had already turned it off,
 * which is said plainly below rather than discovered afterwards. The
 * alternative — refusing until somebody visits each branch by hand — leaves
 * the organization holding two contradictory answers in the meantime.
 */
export function ModuleLockPanel() {
    const { data: modules, isLoading } = useModuleLocks();
    const save = useSaveModuleLocks();

    const [locked, setLocked] = useState<string[]>([]);
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (modules) {
            setLocked(modules.filter((module) => module.is_locked).map((module) => module.key));
            setDirty(false);
        }
    }, [modules]);

    function toggle(key: string) {
        setDirty(true);
        setLocked((current) =>
            current.includes(key) ? current.filter((held) => held !== key) : [...current, key],
        );
    }

    async function onSave() {
        try {
            await save.mutateAsync(locked);
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
                    <i className="ti ti-lock" />
                    <h6>Nothing to make compulsory</h6>
                    <p>
                        Your organization has only the modules every organization gets, and those
                        already run at every branch. Optional modules appear here once you have
                        them.
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
                        <h5>Modules branches cannot switch off</h5>

                        <span className="rp-head-meta">
                            <em>{locked.length}</em> of {(modules ?? []).length} compulsory
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
                        const on = locked.includes(module.key);

                        return (
                            <label className={`bm-item${on ? ' is-on' : ''}`} key={module.key}>
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
                                        checked={on}
                                        aria-label={`Require ${module.name} at every branch`}
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
                        A branch sees a padlock instead of a switch for these, and the API refuses
                        the change too. Making one compulsory switches it back on at any branch
                        that had turned it off.
                    </span>
                </p>
            </div>
        </div>
    );
}
