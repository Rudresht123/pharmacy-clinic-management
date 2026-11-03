import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { formatDate } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';
import { useOrganizationModules, useSaveOrganizationModules } from '../api';
import type { ModuleCapability, ModuleState, OrganizationModule } from '../types';

const GROUP_LABELS: Record<string, string> = {
    foundation: 'Foundation',
    billing: 'Billing',
    clinical: 'Clinical',
    pharmacy: 'Pharmacy',
    communication: 'Communication',
    commerce: 'Commerce',
};

const STATE_LABEL: Record<ModuleState, string> = {
    // Only ever shown on a switch just turned on, before it is saved.
    unbound: 'Not saved yet',
    active: 'Active',
    scheduled: 'Starts later',
    expired: 'Expired',
    revoked: 'Switched off',
};

/**
 * The words the Roles screen reads a role in, so a capability is described the
 * same way from both sides. The patient app's come apart from staff's because
 * no staff role can hold them.
 */
const PERMISSION_GROUPS: Array<{
    key: string;
    label: string;
    icon: string;
    has: (capability: ModuleCapability) => boolean;
}> = [
    {
        key: 'open',
        label: 'Can open',
        icon: 'ti ti-layout-sidebar',
        has: (capability) => capability.audience !== 'patient' && Boolean(capability.screen),
    },
    {
        key: 'do',
        label: 'Can do',
        icon: 'ti ti-hand-finger',
        has: (capability) => capability.audience !== 'patient' && !capability.screen,
    },
    {
        key: 'patient',
        label: 'In the patient app',
        icon: 'ti ti-device-mobile',
        has: (capability) => capability.audience === 'patient',
    },
];

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
        assigned: module.state !== 'unbound',
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

/** The organization has this from its subscription, with no row of its own here. */
function fromSubscription(module: OrganizationModule): boolean {
    return module.source === 'plan' || module.source === 'addon';
}

/**
 * What a module lets somebody do, read the way the Roles screen reads a role.
 *
 * Tiles in a grid rather than a list: names run from two words to a sentence,
 * and the grid keeps each row one height whatever a neighbour says. The key goes
 * under the name instead of beside it, so a long name is not squeezed by it.
 */
function ModulePermissions({
    capabilities,
    granted,
}: {
    capabilities: ModuleCapability[];
    granted: boolean;
}) {
    return (
        <section className={`ma-perms${granted ? '' : ' is-preview'}`}>
            <header className="ma-perms-head">
                <h6>{granted ? 'Grants the right to' : 'Would grant the right to'}</h6>
                <span>{capabilities.length}</span>
            </header>

            {PERMISSION_GROUPS.map((group) => {
                const members = capabilities.filter(group.has);

                if (members.length === 0) {
                    return null;
                }

                return (
                    <div className="ma-perm-group" key={group.key}>
                        <span className="ma-perm-label">
                            <i className={group.icon} aria-hidden="true" />
                            {group.label}
                        </span>

                        <ul className="ma-perm-grid">
                            {members.map((capability) => (
                                <li className="ma-perm" key={capability.key}>
                                    <i className="ti ti-check ma-perm-tick" aria-hidden="true" />

                                    <div className="ma-perm-text">
                                        <b>{capability.name}</b>

                                        <div className="ma-perm-meta">
                                            <code>{capability.key}</code>

                                            {capability.scope === 'organization' &&
                                                capability.audience !== 'patient' && (
                                                    <span
                                                        className="ma-perm-scope"
                                                        title="Applies across every branch, so a branch role cannot hold it."
                                                    >
                                                        Org-wide
                                                    </span>
                                                )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                );
            })}
        </section>
    );
}

/**
 * Give one organization the modules it has paid for.
 *
 * Shared by the Modules screen, where it sits beside a list of
 * organizations, and by an organization's own Modules tab — one arrangement,
 * edited the same way from either direction.
 *
 * Nothing is included by default: every module here is a decision, and an
 * organization that was given nothing has nothing.
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
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (data) {
            setDrafts(Object.fromEntries(data.modules.map((m) => [m.key, toDraft(m)])));
            setExpanded(null);
            setDirty(false);
            setReason('');
        }
    }, [data]);

    // The server refuses anything shorter, so the button says so first.
    const needsReason = Boolean(data?.reason_required) && reason.trim().length < 5;

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
            .filter((module) => drafts[module.key]?.assigned)
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
            await save.mutateAsync({ modules: payload, reason: reason.trim() });
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

                /*
                 * Errors that belong to no row.
                 *
                 * "Pharmacy needs Medicines" is reported against
                 * `modules`, with no index — there is no single row at fault.
                 * It used to be dropped on the floor here and announced as
                 * "Some dates need attention", which named the wrong problem
                 * and hid the only sentence that said how to fix it.
                 */
                const general: string[] = [];

                for (const [field, messages] of Object.entries(validation)) {
                    const module = payload[Number(field.split('.')[1])];

                    if (module) {
                        byModule[module.key] = messages[0];

                        continue;
                    }

                    general.push(messages[0]);
                }

                setErrors(byModule);
                setExpanded(Object.keys(byModule)[0] ?? null);

                // Say what actually went wrong, not what usually does.
                notify.error(general[0] ?? 'Some dates need attention.');

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Loading modules…" />;
    }

    const modules = data?.modules ?? [];
    const onCount = modules.filter(
        (module) => drafts[module.key]?.assigned || fromSubscription(module),
    ).length;

    return (
        <div className="ma-panel">
            <header className="ma-head">
                <div className="ma-head-text">
                    {heading}

                    <p>
                        <b>{onCount}</b> of {modules.length} module
                        {modules.length === 1 ? '' : 's'} · <b>{data?.capabilities.length ?? 0}</b>{' '}
                        permissions this organization can grant
                    </p>
                </div>

                <Button
                    icon="ti ti-device-floppy"
                    loading={save.isPending}
                    disabled={!dirty || needsReason}
                    onClick={onSave}
                >
                    {dirty ? 'Save changes' : 'Saved'}
                </Button>
            </header>

            {/*
                This organization's modules come from its plan, so a change here
                overrides that plan, and somebody will later ask why.
            */}
            {data?.reason_required && dirty && (
                <label className="ma-reason">
                    <span>This organization is on a plan, so this overrides it. Say why.</span>
                    <input
                        className="form-control"
                        maxLength={500}
                        value={reason}
                        placeholder="For example: sold Smart Queue as an add-on"
                        onChange={(event) => setReason(event.target.value)}
                    />
                </label>
            )}

            {grouped.map(([group, members]) => (
                <section className="ma-group" key={group}>
                    <h6>{GROUP_LABELS[group] ?? group}</h6>

                    <div className="ma-list">
                        {members.map((module) => {
                            const draft = drafts[module.key];
                            const open = expanded === module.key;
                            const error = errors[module.key];

                            if (!draft) {
                                return null;
                            }

                            const planned = !draft.assigned && fromSubscription(module);
                            const on = draft.assigned || planned;

                            return (
                                <article
                                    className={`ma-item${on ? ' is-on' : ''}${
                                        error ? ' has-error' : ''
                                    }`}
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
                                    </div>

                                    <div className="ma-meta">
                                        {draft.assigned && (
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

                                        {/*
                                            On without a row here: the switch only
                                            ever says "granted by hand", so it stays
                                            off and this says why the module runs.
                                        */}
                                        {planned && (
                                            <span className="ma-state is-live">
                                                {module.source === 'addon' ? 'Add-on' : 'From plan'}
                                            </span>
                                        )}

                                        <button
                                            type="button"
                                            className="ma-toggle"
                                            aria-expanded={open}
                                            onClick={() => setExpanded(open ? null : module.key)}
                                        >
                                            {!draft.assigned
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
                                            {draft.assigned && (
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

                                            <ModulePermissions
                                                capabilities={module.capabilities}
                                                granted={on}
                                            />
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
