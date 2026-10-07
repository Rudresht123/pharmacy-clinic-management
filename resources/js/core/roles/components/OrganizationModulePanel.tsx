import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useModuleSettings, useSaveModuleSettings } from '../api';

/**
 * Which of the modules it was sold the organization actually uses.
 *
 * The level above the branch screen. The super admin decides what the
 * organization may have; this decides whether it uses it at all. Off here is
 * off at every branch — including one that had it deliberately on, and every
 * branch opened afterwards — and no branch can switch it back on.
 *
 * Only ever narrows: a module the organization was not sold is not listed, and
 * nothing here can make one run. Turning a module back on restores whatever
 * each branch had chosen for itself, because those choices are never touched.
 */
export function OrganizationModulePanel() {
    const { data: modules, isLoading } = useModuleSettings();
    const save = useSaveModuleSettings();
    const { refreshSession } = useTenantAuth();

    const [on, setOn] = useState<string[]>([]);
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (modules) {
            setOn(modules.filter((module) => module.is_enabled).map((module) => module.key));
            setDirty(false);
        }
    }, [modules]);

    const names = useMemo(
        () => Object.fromEntries((modules ?? []).map((module) => [module.key, module.name])),
        [modules],
    );

    /*
     * "Pharmacy needs Medicines" before Save rather than after it. The server
     * refuses the same thing; saying so here stops somebody switching three
     * things off and losing all three to one 422. A requirement on a module
     * not in this list is a core one, which is always there.
     */
    const unmet = useMemo(
        () =>
            (modules ?? [])
                .filter((module) => on.includes(module.key))
                .flatMap((module) =>
                    module.requires
                        .filter((needed) => needed in names && !on.includes(needed))
                        .map((needed) => `${module.name} needs ${names[needed]}.`),
                ),
        [modules, on, names],
    );

    function toggle(key: string) {
        setDirty(true);
        setOn((current) =>
            current.includes(key) ? current.filter((held) => held !== key) : [...current, key],
        );
    }

    async function onSave() {
        // Only what changed — the server leaves every module not named alone.
        const changes = Object.fromEntries(
            (modules ?? [])
                .filter((module) => on.includes(module.key) !== module.is_enabled)
                .map((module) => [module.key, on.includes(module.key)]),
        );

        try {
            await save.mutateAsync(changes);
            setDirty(false);

            // The menu is built from the session's modules; without this the
            // sidebar would go on offering what was just switched off.
            await refreshSession();
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
                    <h6>Nothing to switch</h6>
                    <p>
                        Your organization has only the modules every organization gets, and those
                        always run. Optional modules appear here once you have them.
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
                        <h5>Modules your organisation uses</h5>

                        <span className="rp-head-meta">
                            <em>{on.length}</em> of {(modules ?? []).length} switched on
                        </span>
                    </div>

                    <div className="rp-head-actions">
                        <Button
                            variant={dirty ? undefined : 'light'}
                            icon={dirty ? 'ti ti-device-floppy' : 'ti ti-circle-check'}
                            loading={save.isPending}
                            disabled={!dirty || unmet.length > 0}
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
                                className={`bm-item${enabled ? ' is-on' : ''}${module.is_locked ? ' is-locked' : ''}`}
                                key={module.key}
                            >
                                <span className="rp-module-icon is-slate" aria-hidden="true">
                                    <i className={module.icon ?? 'ti ti-puzzle'} />
                                </span>

                                <span className="bm-text">
                                    <b>{module.name}</b>
                                    <small>
                                        {module.description}
                                        {module.requires.some((needed) => needed in names) && (
                                            <>
                                                {' '}
                                                Needs{' '}
                                                {module.requires
                                                    .filter((needed) => needed in names)
                                                    .map((needed) => names[needed])
                                                    .join(' and ')}
                                                .
                                            </>
                                        )}
                                    </small>
                                </span>

                                {/*
                                    Compulsory at every branch, so it cannot be
                                    switched off for the organization until the
                                    lock goes — a padlock, not a switch that is
                                    always refused on save.
                                */}
                                {module.is_locked && enabled ? (
                                    <span
                                        className="rp-lock is-on"
                                        title="Required at every branch — unlock it below to switch it off"
                                    >
                                        <i className="ti ti-lock" aria-hidden="true" />
                                    </span>
                                ) : (
                                    <span className="ma-switch">
                                        <input
                                            type="checkbox"
                                            checked={enabled}
                                            aria-label={`Use ${module.name} in this organisation`}
                                            onChange={() => toggle(module.key)}
                                        />
                                        <span className="ma-track" aria-hidden="true" />
                                    </span>
                                )}
                            </label>
                        );
                    })}
                </div>

                {unmet.length > 0 && (
                    <p className="su-error" role="alert">
                        {unmet.join(' ')} Switch both off together, or keep both on.
                    </p>
                )}

                <p className="bm-note">
                    <i className="ti ti-info-circle" />
                    <span>
                        Switching one off stops it at every branch — including branches you add later
                        — and the API refuses it for everybody, you included. Switching it back on
                        restores whatever each branch had chosen.
                    </span>
                </p>
            </div>
        </div>
    );
}
