import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { formatDate } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';
import { useOrganizationModules, useSaveOrganizationModules } from '../api';
import type { ModuleState, OrganizationModule } from '../types';

const GROUP_LABELS: Record<string, string> = {
    foundation: 'Included with every organization',
    pharmacy: 'Pharmacy',
    commerce: 'Commerce',
    clinical: 'Clinical',
};

const STATE_LABEL: Record<ModuleState, string> = {
    core: 'Included',
    unbound: 'Not assigned',
    active: 'Active',
    scheduled: 'Starts later',
    expired: 'Expired',
    revoked: 'Switched off',
};

/** One row's editable state; mirrors what the API accepts. */
interface Draft {
    assigned: boolean;
    is_enabled: boolean;
    starts_at: string;
    expires_at: string;
    note: string;
}

function toDraft(module: OrganizationModule): Draft {
    return {
        assigned: module.state !== 'unbound' && module.state !== 'core',
        // A module that has never been bound starts as "on" the moment it is
        // switched on, which is what assigning one almost always means.
        is_enabled: module.state === 'unbound' ? true : module.state !== 'revoked',
        starts_at: module.starts_at ?? '',
        expires_at: module.expires_at ?? '',
        note: module.note ?? '',
    };
}

/** "Now → 30 Jun 2027", or "Open-ended" when neither date is set. */
function describeTerm(draft: Draft): string {
    if (!draft.starts_at && !draft.expires_at) {
        return 'Open-ended';
    }

    const from = draft.starts_at ? formatDate(draft.starts_at) : 'Now';
    const to = draft.expires_at ? formatDate(draft.expires_at) : 'no end';

    return `${from} → ${to}`;
}

/**
 * Give one organization the modules it has paid for.
 *
 * Shared by the Modules screen, where it sits beside a list of
 * organizations, and by an organization's own Modules tab — one arrangement,
 * edited the same way from either direction.
 *
 * A switch per module rather than a table of ticks: this is a small, grouped
 * set of decisions about one customer, not a list to be sorted and scanned.
 * The terms open in place under the module they belong to, so the answer to
 * "until when?" never leaves the question.
 */
export function ModuleAssignPanel({
    uuid,
    /** Rendered above the modules — the organization's name in the split view. */
    heading,
}: {
    uuid: string;
    heading?: React.ReactNode;
}) {
    const { data, isLoading } = useOrganizationModules(uuid);
    const save = useSaveOrganizationModules(uuid);

    const [drafts, setDrafts] = useState<Record<string, Draft>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [expanded, setExpanded] = useState<string | null>(null);
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (data) {
            setDrafts(Object.fromEntries(data.modules.map((m) => [m.key, toDraft(m)])));
            setExpanded(null);
            setDirty(false);
        }
    }, [data]);

    const grouped = useMemo(() => {
        const buckets = new Map<string, OrganizationModule[]>();

        for (const module of data?.modules ?? []) {
            buckets.set(module.group, [...(buckets.get(module.group) ?? []), module]);
        }

        return [...buckets.entries()];
    }, [data]);

    function patch(key: string, changes: Partial<Draft>) {
        setDirty(true);
        setDrafts((current) => ({ ...current, [key]: { ...current[key], ...changes } }));
    }

    async function onSave() {
        setErrors({});

        const payload = (data?.modules ?? [])
            .filter((module) => !module.is_core && drafts[module.key]?.assigned)
            .map((module) => {
                const draft = drafts[module.key];

                return {
                    key: module.key,
                    is_enabled: draft.is_enabled,
                    starts_at: draft.starts_at || null,
                    expires_at: draft.expires_at || null,
                    note: draft.note.trim() || null,
                };
            });

        try {
            await save.mutateAsync(payload);
            setDirty(false);
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                /*
                 * The server indexes errors by position in the submitted
                 * array; the screen is keyed by module. Mapped back here so a
                 * bad date lands on the row it belongs to — and that row is
                 * opened, because the field at fault is inside it.
                 */
                const byModule: Record<string, string> = {};

                for (const [field, messages] of Object.entries(validation)) {
                    const module = payload[Number(field.split('.')[1])];

                    if (module) {
                        byModule[module.key] = messages[0];
                    }
                }

                setErrors(byModule);
                setExpanded(Object.keys(byModule)[0] ?? null);
                notify.error('Some dates need attention.');

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Loading modules…" />;
    }

    const optional = (data?.modules ?? []).filter((module) => !module.is_core);
    const assignedCount = optional.filter((module) => drafts[module.key]?.assigned).length;

    return (
        <div className="ma-panel">
            <header className="ma-head">
                <div className="ma-head-text">
                    {heading}

                    <p>
                        <b>{assignedCount}</b> of {optional.length} optional module
                        {optional.length === 1 ? '' : 's'} · <b>{data?.capabilities.length ?? 0}</b>{' '}
                        permissions this organization can grant
                    </p>
                </div>

                <Button
                    icon="ti ti-device-floppy"
                    loading={save.isPending}
                    disabled={!dirty}
                    onClick={onSave}
                >
                    {dirty ? 'Save changes' : 'Saved'}
                </Button>
            </header>

            {grouped.map(([group, modules]) => (
                <section className="ma-group" key={group}>
                    <h6>{GROUP_LABELS[group] ?? group}</h6>

                    <div className="ma-list">
                        {modules.map((module) => {
                            const draft = drafts[module.key];
                            const open = expanded === module.key;
                            const error = errors[module.key];

                            if (!draft) {
                                return null;
                            }

                            const on = module.is_core || draft.assigned;

                            return (
                                <article
                                    className={`ma-item${on ? ' is-on' : ''}${
                                        module.is_core ? ' is-core' : ''
                                    }${error ? ' has-error' : ''}`}
                                    key={module.key}
                                >
                                    <div className="ma-row">
                                        <span className="ma-icon" aria-hidden="true">
                                            <i className={module.icon ?? 'ti ti-puzzle'} />
                                        </span>

                                        <div className="ma-text">
                                            <b>{module.name}</b>
                                            <span>{module.description}</span>
                                        </div>

                                        {module.is_core ? (
                                            <span className="ma-locked">
                                                <i className="ti ti-lock" />
                                                Included
                                            </span>
                                        ) : (
                                            <label className="ma-switch">
                                                <input
                                                    type="checkbox"
                                                    checked={draft.assigned}
                                                    aria-label={`Give this organization ${module.name}`}
                                                    onChange={(event) =>
                                                        patch(module.key, {
                                                            assigned: event.target.checked,
                                                        })
                                                    }
                                                />
                                                <span className="ma-track" aria-hidden="true" />
                                            </label>
                                        )}
                                    </div>

                                    <div className="ma-meta">
                                        {!module.is_core && draft.assigned && (
                                            <>
                                                <span
                                                    className={`ma-state is-${
                                                        module.is_live ? 'live' : module.state
                                                    }`}
                                                >
                                                    {STATE_LABEL[module.state]}
                                                </span>

                                                <span className="ma-term">
                                                    {describeTerm(draft)}
                                                </span>
                                            </>
                                        )}

                                        <button
                                            type="button"
                                            className="ma-toggle"
                                            aria-expanded={open}
                                            onClick={() => setExpanded(open ? null : module.key)}
                                        >
                                            {module.is_core || !draft.assigned
                                                ? `${module.capabilities.length} permissions`
                                                : 'Terms & permissions'}
                                            <i
                                                className={
                                                    open ? 'ti ti-chevron-up' : 'ti ti-chevron-down'
                                                }
                                            />
                                        </button>
                                    </div>

                                    {error && <p className="ma-error">{error}</p>}

                                    {open && (
                                        <div className="ma-detail">
                                            {!module.is_core && draft.assigned && (
                                                <div className="ma-terms">
                                                    <label className="ma-check">
                                                        <input
                                                            type="checkbox"
                                                            className="form-check-input"
                                                            checked={draft.is_enabled}
                                                            onChange={(event) =>
                                                                patch(module.key, {
                                                                    is_enabled:
                                                                        event.target.checked,
                                                                })
                                                            }
                                                        />
                                                        <span>
                                                            Switched on
                                                            <small>
                                                                Turn off to suspend without losing
                                                                the dates.
                                                            </small>
                                                        </span>
                                                    </label>

                                                    <div className="ma-fields">
                                                        <label className="ma-field">
                                                            <span>Starts</span>
                                                            <DatePicker
                                                                id={`${module.key}-starts`}
                                                                label="the start date"
                                                                value={draft.starts_at}
                                                                placeholder="Immediately"
                                                                onChange={(value: string) =>
                                                                    patch(module.key, {
                                                                        starts_at: value,
                                                                    })
                                                                }
                                                            />
                                                        </label>

                                                        <label className="ma-field">
                                                            <span>Ends</span>
                                                            <DatePicker
                                                                id={`${module.key}-expires`}
                                                                label="the end date"
                                                                value={draft.expires_at}
                                                                placeholder="No end date"
                                                                onChange={(value: string) =>
                                                                    patch(module.key, {
                                                                        expires_at: value,
                                                                    })
                                                                }
                                                            />
                                                        </label>

                                                        <label className="ma-field">
                                                            <span>Note</span>
                                                            <input
                                                                type="text"
                                                                className="form-control form-control-sm"
                                                                placeholder="Purchase order, trial terms…"
                                                                value={draft.note}
                                                                maxLength={255}
                                                                onChange={(event) =>
                                                                    patch(module.key, {
                                                                        note: event.target.value,
                                                                    })
                                                                }
                                                            />
                                                        </label>
                                                    </div>
                                                </div>
                                            )}

                                            <span className="ma-caps-title">
                                                {draft.assigned || module.is_core
                                                    ? 'Grants the right to'
                                                    : 'Would grant the right to'}
                                            </span>

                                            <ul className="ma-caps">
                                                {module.capabilities.map((capability) => (
                                                    <li key={capability.key}>
                                                        <i className="ti ti-check" />
                                                        <span>{capability.name}</span>
                                                        <code>{capability.key}</code>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </article>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}
