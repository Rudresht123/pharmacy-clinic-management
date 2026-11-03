import { Fragment, useEffect, useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { notify } from '@/shared/utils/notify';
import { useConfigurableEntities } from '@/core/field-settings/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { tenantNavigation } from '@/app/tenant-navigation';
import { rolesHooks, useGrantable, useRoleMembers, useRoleTemplates } from '../api';
import { toModuleSections } from '../moduleSections';
import { ROLE_ICONS, type GrantableModule, type Role, type RoleScope } from '../types';

/**
 * A module's colour, keyed by the module itself.
 *
 * Never by position in the list. A colour that follows rank repaints every
 * module the moment one is bought or withdrawn, and somebody who has learnt
 * that green means the diary has to learn it again.
 */
const MODULE_TONE: Record<string, string> = {
    branches: 'sky',
    people: 'violet',
    customers: 'indigo',
    settings: 'slate',
    appointments: 'emerald',
    prescriptions: 'amber',
};

/** What the sidebar files under "My work": a doctor's own screens. */
const MY_WORK = [
    'appointments.my_day',
    'appointments.my_queue',
    'appointments.my_schedule',
    'appointments.my_patients',
    'queue.my_console',
];

/** A role being written, before it is a role. */
const BLANK = {
    id: 0,
    name: '',
    description: '',
    icon: ROLE_ICONS[0] as string,
    capabilities: [] as string[],

    /*
     * The subset of those a branch may not take away for itself. A second list
     * rather than a flag per capability, because that is the shape the server
     * takes and the shape every other permission list in this screen has.
     */
    locked: [] as string[],

    /*
     * Where it may be given — chosen once, when the role is created, and
     * fixed after (the server never changes a live role's scope). Branch by
     * default: that is what most roles are, and all a non-owner may write.
     */
    scope: 'branch' as RoleScope,
};

type Draft = typeof BLANK;
type Tab = 'overview' | 'permissions' | 'members';

function toDraft(role: Role): Draft {
    return {
        id: role.id,
        name: role.name,
        description: role.description ?? '',
        icon: role.icon || ROLE_ICONS[0],
        capabilities: [...role.capabilities],
        locked: [...(role.locked ?? [])],
        scope: role.scope,
    };
}

/**
 * Level three of the permission flow — what a person may do.
 *
 * EACH BRANCH SETS ITS OWN ROLES. A branch admin sees and writes their
 * branch's; the owner sees the one role set for the whole organisation, the
 * patient app's, and otherwise decides which modules each branch runs.
 *
 * A split view rather than a list and a separate form: a role is only
 * meaningful next to the others, since "who can delete a patient" is answered
 * by reading down the list rather than by opening one row. Editing happens in
 * place beside it.
 *
 * Only capabilities from modules the organization actually holds are offered.
 * Something it was never sold is not shown greyed out — it does not exist
 * here, and pretending otherwise invites somebody to tick it and wonder why
 * nothing happened.
 */
export default function RolesPage() {
    const confirm = useConfirm();

    const { data: roles, isLoading, isError, refetch } = rolesHooks.useList();

    const create = rolesHooks.useCreate();
    const update = rolesHooks.useUpdate();
    const remove = rolesHooks.useRemove();

    const [selected, setSelected] = useState<number | 'new' | null>(null);
    const [draft, setDraft] = useState<Draft>(BLANK);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [tab, setTab] = useState<Tab>('permissions');
    const [search, setSearch] = useState('');
    /** Which module's table is open on the right — one at a time. */
    const [focusedModule, setFocusedModule] = useState<string | null>(null);
    const [editingMeta, setEditingMeta] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);

    const current = useMemo(
        () => (typeof selected === 'number' ? roles?.find((role) => role.id === selected) : null),
        [roles, selected],
    );

    /*
     * The pool follows the role's scope. An EXISTING role's is its own — an
     * organization-wide role (Head office, Doctor) may hold branch
     * capabilities too and must not have those hidden. A NEW role's is the
     * scope chosen for it above the list, which only an owner may set to
     * organization-wide; everybody else writes branch roles
     * (RoleController::store).
     */
    const grantScope: RoleScope = selected === 'new' ? draft.scope : (current?.scope ?? 'branch');
    const { data: pool, refetch: refetchGrantable } = useGrantable(grantScope);

    /*
     * Staff permissions for a staff role; the patient app's for the patient
     * role. The two never mix: "Patients: book for themselves" on a
     * receptionist's role means nothing, and listing it beside "Book
     * appointments" invites somebody to tick the wrong one.
     */
    const forPatients = selected !== 'new' && current?.audience === 'patient';

    const modules = useMemo(
        () =>
            (pool ?? [])
                .map((module) => ({
                    ...module,
                    capabilities: module.capabilities.filter(
                        (capability) => (capability.audience === 'patient') === forPatients,
                    ),
                }))
                .filter((module) => module.capabilities.length > 0),
        [pool, forPatients],
    );

    /*
     * Switching a new role to branch-specific takes away what a branch role
     * cannot hold, rather than leaving ticks the save would refuse.
     */
    useEffect(() => {
        if (selected !== 'new' || !pool) return;

        const allowed = new Set(modules.flatMap((module) => module.capabilities.map((c) => c.key)));

        if (draft.capabilities.some((key) => !allowed.has(key))) {
            setDraft((value) => ({
                ...value,
                capabilities: value.capabilities.filter((key) => allowed.has(key)),
                locked: value.locked.filter((key) => allowed.has(key)),
            }));
        }
        // Only when the pool itself changes — not on every tick.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pool, selected]);

    const { data: members, isLoading: membersLoading } = useRoleMembers(
        current?.id,
        tab === 'members',
    );

    /** How many there are to hold here — "4 of 9" says more than "4". */
    const totalCapabilities = useMemo(
        () => modules.reduce((sum, module) => sum + module.capabilities.length, 0),
        [modules],
    );

    const totalMembers = useMemo(
        () => (roles ?? []).reduce((sum, role) => sum + (role.users_count ?? 0), 0),
        [roles],
    );

    /*
     * What is unsaved, derived rather than flagged.
     *
     * A boolean set by every handler drifts the moment one forgets to set it,
     * and it can never say how much is pending. Diffing against the saved row
     * cannot drift, and ticking a box then unticking it correctly returns to
     * "no changes" instead of leaving the save button lit.
     */
    const baseline = useMemo(() => (current ? toDraft(current) : BLANK), [current]);

    const changes = useMemo(() => {
        const added = draft.capabilities.filter((key) => !baseline.capabilities.includes(key));
        const removed = baseline.capabilities.filter((key) => !draft.capabilities.includes(key));

        const meta =
            (draft.name.trim() !== baseline.name ? 1 : 0) +
            (draft.description.trim() !== baseline.description ? 1 : 0) +
            (draft.icon !== baseline.icon ? 1 : 0);

        return added.length + removed.length + meta;
    }, [draft, baseline]);

    const dirty = changes > 0;

    /*
     * What this role would actually see, generated by the SAME function that
     * builds the real sidebar. Not a hand-written list of "this unlocks X" —
     * that would drift the first time a screen moved, and a preview that lies
     * is worse than none.
     */
    const { modules: orgModules, user, can, ownerRestricted } = useTenantAuth();
    const { data: entities } = useConfigurableEntities();

    const labels = useMemo(
        () => Object.fromEntries((entities ?? []).map((entity) => [entity.entity, entity.label])),
        [entities],
    );

    const preview = useMemo(
        () => tenantNavigation('staff', labels, orgModules, draft.capabilities),
        [labels, orgModules, draft.capabilities],
    );

    /** Modules with at least one capability matching the search box. */
    const visibleModules = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return modules ?? [];
        }

        return (modules ?? [])
            .map((module) => ({
                ...module,
                capabilities: module.capabilities.filter(
                    (capability) =>
                        capability.name.toLowerCase().includes(term) ||
                        capability.key.toLowerCase().includes(term) ||
                        module.name.toLowerCase().includes(term),
                ),
            }))
            .filter((module) => module.capabilities.length > 0);
    }, [modules, search]);

    /*
     * The module whose table is actually on screen.
     *
     * Falls back to the first visible one rather than staying on a module a
     * search just filtered out, or one this role's scope no longer grants —
     * an empty right-hand panel reads as broken, not as "nothing matched".
     */
    const activeModule =
        visibleModules.find((module) => module.key === focusedModule) ??
        toModuleSections(visibleModules)[0]?.modules[0] ??
        null;

    function choose(role: Role) {
        setSelected(role.id);
        setDraft(toDraft(role));
        setErrors({});
        setEditingMeta(false);
        setMenuOpen(false);
    }

    function startNew(from?: Role) {
        setSelected('new');
        setDraft(
            from
                ? {
                      ...toDraft(from),
                      id: 0,
                      name: `${from.name} copy`,
                      scope: 'branch',
                  }
                : {
                      ...BLANK,
                      name: '',
                      scope: 'branch',
                  },
        );
        setErrors({});
        setTab('permissions');
        setEditingMeta(true);
        setMenuOpen(false);
    }

    function patch(changes: Partial<Draft>) {
        setDraft((value) => ({ ...value, ...changes }));
    }

    /**
     * Tick or untick one, keeping the module coherent.
     *
     * A module's actions rest on its list: "add and edit customers" without
     * the customer list is not a narrower permission, it is a broken one. So
     * ticking an action brings the list with it, and unticking the list takes
     * the actions done from it — not the module's other screens.
     *
     * OPD is the exception: its actions (book, check in, cancel) are done from
     * several screens, and a role made only for chat must not be handed the
     * appointments list by ticking it.
     */
    function toggle(key: string) {
        const module = (modules ?? []).find((entry) =>
            entry.capabilities.some((capability) => capability.key === key),
        );

        const viewKey =
            module && module.key !== 'appointments'
                ? module.capabilities.find((capability) => capability.key === `${module.key}.view`)?.key
                : undefined;

        const isScreen = Boolean(module?.capabilities.find((capability) => capability.key === key)?.screen);

        if (draft.capabilities.includes(key)) {
            const drop =
                key === viewKey && module
                    ? module.capabilities
                          .filter((capability) => capability.key === key || !capability.screen)
                          .map((capability) => capability.key)
                    : [key];

            patch({
                capabilities: draft.capabilities.filter((held) => !drop.includes(held)),
                // A lock on something the role no longer holds is a rule about
                // nothing, waiting to surprise whoever grants it again later.
                locked: draft.locked.filter((key) => !drop.includes(key)),
            });

            return;
        }

        const add = viewKey && key !== viewKey && !isScreen ? [key, viewKey] : [key];

        patch({ capabilities: [...new Set([...draft.capabilities, ...add])] });
    }

    function toggleModule(module: GrantableModule) {
        const keys = module.capabilities.map((capability) => capability.key);
        const all = keys.every((key) => draft.capabilities.includes(key));

        patch({
            capabilities: all
                ? draft.capabilities.filter((key) => !keys.includes(key))
                : [...new Set([...draft.capabilities, ...keys])],
            locked: all ? draft.locked.filter((key) => !keys.includes(key)) : draft.locked,
        });
    }

    function discard() {
        setDraft(baseline);
        setErrors({});
        setEditingMeta(false);
    }

    async function onSave() {
        setErrors({});

        const payload = {
            name: draft.name.trim(),
            description: draft.description.trim() || null,
            icon: draft.icon,
            capabilities: draft.capabilities,
        };

        try {
            if (selected === 'new') {
                const created = (await create.mutateAsync(payload)) as unknown as Role;

                setSelected(created.id);
                setDraft(toDraft(created));
            } else {
                await update.mutateAsync({ id: draft.id, payload });
            }

            setEditingMeta(false);
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(validation).map(([key, value]) => [key, value[0]]),
                    ),
                );

                /*
                 * A capability error is indexed by position in the array we
                 * sent, which means nothing on screen — so it is surfaced as a
                 * message instead of a field error nobody can see.
                 *
                 * It always means this page is out of date: either a release
                 * renamed the key, or the organization lost the module while
                 * the page sat open. Refetching the pool makes the screen
                 * correct itself, and dropping what the server refused means
                 * the next save goes through instead of failing the same way.
                 */
                const stale = Object.keys(validation).find((key) =>
                    key.startsWith('capabilities.'),
                );

                if (stale) {
                    notify.error(validation[stale][0]);

                    const refreshed = await refetchGrantable();
                    const pool = (refreshed.data ?? []).flatMap((module) =>
                        module.capabilities.map((capability) => capability.key),
                    );

                    patch({
                        capabilities: draft.capabilities.filter((key) => pool.includes(key)),
                    });
                }

                // About the role as a whole — "you hold this role yourself" —
                // so it is said aloud rather than parked on a field.
                if (validation.capabilities) {
                    notify.error(validation.capabilities[0]);
                }

                if (validation.name) {
                    setEditingMeta(true);
                }

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    async function onDelete(role: Role) {
        const confirmed = await confirm({
            title: `Delete ${role.name}?`,
            message: 'The permissions it holds go with it. Nobody may be holding it.',
            confirmLabel: 'Delete',
            danger: true,
        });

        if (!confirmed) {
            return;
        }

        try {
            await remove.mutateAsync(role.id);

            setSelected(null);
            setDraft(BLANK);
        } catch (error) {
            // "Three people hold this role" arrives as a plain message, not a
            // field error — it is about the world, not about the form.
            notify.error(resolveErrorMessage(error));
        }
    }

    const saving = create.isPending || update.isPending;
    const editing = selected !== null;

    const isOwner = user?.role === 'owner';

    /*
     * Whether this person may write a role AT ALL, regardless of which one.
     *
     * An owner used to be assumed yes, unconditionally — `isOwner` below is
     * what every write check here was built on. A restricted owner breaks
     * that assumption: they still hold `people.view` (they have to, to pick
     * a role when adding a Branch manager) but not `people.roles`, so every
     * write surface on this screen — New Role, Edit, Delete, Copy to this
     * branch, the permission toggles, both Save bars — is gated on this
     * first, same as it would refuse a member of staff who held only
     * `people.view`.
     */
    const mayWriteRoles = can('people.roles');

    /*
     * The list. The owner sees the one role set for the whole organisation,
     * the patient app's — every staff role is a branch's to set. Anybody else
     * sees their own branch's roles, which is all the server sends them.
     */
    const railGroups = useMemo(() => {
        const list = roles ?? [];

        return isOwner
            ? [
                  {
                      key: 'patient',
                      title: 'Patient app',
                      note: 'What patients can do in the app. Each branch sets its own staff roles.',
                      empty: 'No patient app role yet.',
                      roles: list.filter((role) => role.audience === 'patient'),
                  },
              ]
            : [
                  {
                      key: 'branch',
                      title: 'This branch’s roles',
                      note: 'Your branch sets its own. Give one to somebody from their page under Staff.',
                      empty: 'No roles yet — start one with New Role.',
                      roles: list.filter((role) => role.audience !== 'patient'),
                  },
              ];
    }, [roles, isOwner]);

    const railRoles = useMemo(() => railGroups.flatMap((group) => group.roles), [railGroups]);

    // Land on the first role this person can see rather than an empty pane,
    // which reads as an error when there is nothing wrong.
    useEffect(() => {
        if (selected === null && railRoles.length) {
            setSelected(railRoles[0].id);
            setDraft(toDraft(railRoles[0]));
        }
    }, [railRoles, selected]);

    /*
     * Whether this person may change THIS role. A branch admin writes their
     * branch's; the owner writes the patient app's.
     */
    const canWrite = mayWriteRoles && (!current || isOwner || current.location_id !== null);

    function railItem(role: Role) {
        return (
            <button
                type="button"
                key={role.id}
                className={`rp-role${selected === role.id ? ' is-active' : ''}`}
                onClick={() => choose(role)}
            >
                <span className="rp-role-icon">
                    <i className={role.icon} />
                </span>

                <span className="rp-role-text">
                    <b className="ras-name">
                        <span>{role.name}</span>
                        {role.audience === 'patient' && (
                            <span className="ras-badge is-patient">
                                <i className="ti ti-device-mobile" aria-hidden="true" />
                                Patients
                            </span>
                        )}
                    </b>

                    <small>
                        {role.capabilities.length} permission
                        {role.capabilities.length === 1 ? '' : 's'} · {role.users_count ?? 0}{' '}
                        {role.users_count === 1 ? 'member' : 'members'}
                    </small>
                </span>

                {/* Marks the row being edited while it has changes pending, so
                    switching away is a visible decision rather than a
                    surprise. */}
                {selected === role.id && dirty && <span className="rp-role-dot" />}
            </button>
        );
    }

    return (
        <>
            <PageHeader
                title="Roles & Permissions"
                subtitle={isOwner ? 'What patients can do in the app. Each branch sets its own staff roles.' : 'Your branch sets its own roles, and what each one can open and do.'}
                icon="ti ti-shield-lock"
                tone="violet"
                crumbs={[{ label: 'Roles & Permissions' }]}
                actions={
                    mayWriteRoles && !isOwner ? (
                        <Button icon="ti ti-plus" onClick={() => startNew()}>
                            New Role
                        </Button>
                    ) : undefined
                }
            />

            {isLoading ? (
                <div className="card">
                    <LoadingBlock label="Loading roles…" />
                </div>
            ) : isError ? (
                <div className="card">
                    <ErrorState onRetry={() => refetch()} />
                </div>
            ) : (
                <>
                    {/* The three numbers that describe the whole screen. */}
                    <div className="rp-stats">
                        <span>
                            <i className="ti ti-shield-lock" />
                            <b>{(roles ?? []).length}</b> Roles
                        </span>
                        <span>
                            <i className="ti ti-users" />
                            <b>{totalMembers}</b> Members
                        </span>
                        <span>
                            <i className="ti ti-key" />
                            <b>{totalCapabilities}</b> Permissions
                        </span>
                    </div>

                    <div className="rp-split">
                        {/* --- the roles -------------------------------- */}
                        <aside className="rp-rail">
                            <header className="rp-rail-head">
                                <h6>Roles</h6>
                                {mayWriteRoles && !isOwner && (
                                    <button
                                        type="button"
                                        className="rp-rail-add"
                                        aria-label="New role"
                                        onClick={() => startNew()}
                                    >
                                        <i className="ti ti-plus" />
                                    </button>
                                )}
                            </header>

                            <div className="rp-rail-list">
                                {selected === 'new' && (
                                    <>
                                        <p className="rp-rail-group">Being created</p>
                                        <button type="button" className="rp-role is-active">
                                            <span className="rp-role-icon">
                                                <i className={draft.icon} />
                                            </span>
                                            <span className="rp-role-text">
                                                <b>{draft.name.trim() || 'New role'}</b>
                                                <small>Not saved yet</small>
                                            </span>
                                            <span className="rp-role-dot" />
                                        </button>
                                    </>
                                )}

                                {railGroups.map((group) => (
                                    <section key={group.key} className="ras-group">
                                        {/* The tabs above already name the view; a
                                            heading repeating them is only noise.
                                            Without tabs, or for the patient app's
                                            role, it is what says what the list is. */}
                                        <p className="rp-rail-group">{group.title}</p>
                                        <p className="ras-group-note">{group.note}</p>

                                        {group.roles.length > 0 ? (
                                            group.roles.map(railItem)
                                        ) : (
                                            <p className="rp-rail-empty">{group.empty}</p>
                                        )}
                                    </section>
                                ))}
                            </div>
                        </aside>

                        {/* --- what it may do --------------------------- */}
                        <div className="rp-panel">
                            {!editing ? (
                                <div className="org-pending">
                                    <i className="ti ti-shield-lock" />
                                    <h6>Pick a role</h6>
                                    <p>Choose one on the left, or start a new one.</p>
                                </div>
                            ) : (
                                <>
                                    <header className="rp-head">
                                        <div className="rp-head-top">
                                            <span className="rp-head-icon">
                                                <i className={draft.icon} />
                                            </span>

                                            <div className="rp-head-text">
                                                <h5 className="ras-name">
                                                    <span>
                                                        {selected === 'new'
                                                            ? draft.name.trim() || 'New role'
                                                            : (current?.name ?? draft.name)}
                                                    </span>
                                                    {forPatients && (
                                                        <span className="ras-badge is-patient">
                                                            <i className="ti ti-device-mobile" aria-hidden="true" />
                                                            Patients
                                                        </span>
                                                    )}
                                                </h5>

                                                <p>
                                                    <b>{draft.capabilities.length}</b> of{' '}
                                                    {totalCapabilities} permissions
                                                    <i className="ti ti-point-filled" />
                                                    <b>{current?.users_count ?? 0}</b>{' '}
                                                    {current?.users_count === 1
                                                        ? 'member'
                                                        : 'members'}
                                                </p>
                                            </div>

                                            {/*
                                                The patient app's role is predefined:
                                                only its ticks change — never its name,
                                                and it is never deleted or copied.
                                            */}
                                            {!forPatients && (
                                                <div className="rp-head-actions">
                                                    {canWrite && (
                                                        <button
                                                            type="button"
                                                            className="rp-act"
                                                            onClick={() => setEditingMeta((on) => !on)}
                                                        >
                                                            <i className="ti ti-pencil" />
                                                            Edit
                                                        </button>
                                                    )}

                                                    {canWrite && current && (
                                                        <button
                                                            type="button"
                                                            className="rp-act is-danger"
                                                            onClick={() => onDelete(current)}
                                                        >
                                                            <i className="ti ti-trash" />
                                                            Delete
                                                        </button>
                                                    )}

                                                    {canWrite && current && (
                                                        <div className="rp-menu">
                                                            <button
                                                                type="button"
                                                                className="rp-act is-icon"
                                                                aria-label="More"
                                                                aria-expanded={menuOpen}
                                                                onClick={() => setMenuOpen((open) => !open)}
                                                            >
                                                                <i className="ti ti-dots-vertical" />
                                                            </button>

                                                            {menuOpen && (
                                                                <div className="rp-menu-list">
                                                                    {/* The fastest way to a role
                                                                        that is "the receptionist,
                                                                        but without deletions". */}
                                                                    <button type="button" onClick={() => startNew(current)}>
                                                                        <i className="ti ti-copy" />
                                                                        Duplicate role
                                                                    </button>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {forPatients && (
                                            <p className="ras-scope-line">
                                                <i className="ti ti-info-circle" aria-hidden="true" />
                                                Patients get this role automatically when they sign in to
                                                the app. It is set once, for the whole organisation, and
                                                holds only what patients can do in the app.
                                            </p>
                                        )}

                                        {editingMeta ? (
                                            <div className="rp-edit">
                                                <label className="rp-field">
                                                    <span>
                                                        Name <b>*</b>
                                                    </span>
                                                    <input
                                                        type="text"
                                                        className={`form-control${
                                                            errors.name ? ' is-invalid' : ''
                                                        }`}
                                                        placeholder="Receptionist"
                                                        maxLength={60}
                                                        value={draft.name}
                                                        onChange={(event) =>
                                                            patch({ name: event.target.value })
                                                        }
                                                    />
                                                    {errors.name && <em>{errors.name}</em>}
                                                </label>

                                                <label className="rp-field">
                                                    <span>Description</span>
                                                    <input
                                                        type="text"
                                                        className="form-control"
                                                        placeholder="Front desk — books patients and keeps the queue moving"
                                                        maxLength={255}
                                                        value={draft.description}
                                                        onChange={(event) =>
                                                            patch({
                                                                description: event.target.value,
                                                            })
                                                        }
                                                    />
                                                </label>

                                                <div className="rp-field rp-icons">
                                                    <span>Icon</span>
                                                    <div>
                                                        {ROLE_ICONS.map((icon) => (
                                                            <button
                                                                type="button"
                                                                key={icon}
                                                                className={`rp-icon${
                                                                    draft.icon === icon
                                                                        ? ' is-on'
                                                                        : ''
                                                                }`}
                                                                aria-label={icon}
                                                                onClick={() => patch({ icon })}
                                                            >
                                                                <i className={icon} />
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <>
                                                {draft.description && (
                                                    <p className="rp-desc">{draft.description}</p>
                                                )}

                                                {/*
                                                    Says why the controls are
                                                    missing, and what to do
                                                    instead. Refusing without
                                                    offering the way out is
                                                    where somebody gets stuck.
                                                */}
                                                {!canWrite && (
                                                    <p className="rp-shared">
                                                        <i className="ti ti-lock" />
                                                        {!mayWriteRoles && ownerRestricted ? (
                                                            <>
                                                                Read-only: this organization&rsquo;s
                                                                owner account is set to restricted
                                                                (reports and administration only), so
                                                                roles cannot be changed from it. A
                                                                platform administrator can lift the
                                                                restriction, or a staff account that
                                                                may write roles can change them.
                                                            </>
                                                        ) : !mayWriteRoles ? (
                                                            <>
                                                                Your role does not include writing
                                                                roles. You can read this one, not
                                                                change it.
                                                            </>
                                                        ) : (
                                                            <>
                                                                This role is not one you can change
                                                                here.
                                                            </>
                                                        )}
                                                    </p>
                                                )}

                                            </>
                                        )}

                                        <nav className="rp-tabs" role="tablist">
                                            {(
                                                [
                                                    ['overview', 'Overview'],
                                                    ['permissions', 'Permissions'],
                                                    ['members', 'Members'],
                                                ] as [Tab, string][]
                                            ).map(([value, label]) => (
                                                <button
                                                    key={value}
                                                    type="button"
                                                    role="tab"
                                                    aria-selected={tab === value}
                                                    className={`rp-tab${
                                                        tab === value ? ' is-active' : ''
                                                    }`}
                                                    onClick={() => setTab(value)}
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </nav>
                                    </header>

                                    <div className="rp-body">
                                        {tab === 'overview' && (
                                            <Overview
                                                preview={preview}
                                                roles={railRoles}
                                                selectedId={current?.id}
                                                total={totalCapabilities}
                                                onPick={choose}
                                            />
                                        )}

                                        {tab === 'members' && (
                                            <Members
                                                loading={membersLoading}
                                                members={members ?? []}
                                            />
                                        )}

                                        {tab === 'permissions' && (
                                            <>
                                                <div className="rp-toolbar">
                                                    <div className="rp-search">
                                                        <i className="ti ti-search" />
                                                        <input
                                                            type="search"
                                                            placeholder="Search permissions…"
                                                            value={search}
                                                            onChange={(event) =>
                                                                setSearch(event.target.value)
                                                            }
                                                        />
                                                    </div>

                                                    <span className="rp-enabled">
                                                        {draft.capabilities.length} of{' '}
                                                        {totalCapabilities} permissions enabled
                                                    </span>
                                                </div>

                                                {draft.capabilities.length === 0 && !search && canWrite && (
                                                    <Templates
                                                        scope={grantScope}
                                                        onApply={(capabilities, icon) =>
                                                            patch({ capabilities, icon })
                                                        }
                                                    />
                                                )}

                                                {visibleModules.length === 0 ? (
                                                    <p className="rp-none">
                                                        Nothing matches “{search}”.
                                                    </p>
                                                ) : (
                                                    <PermissionMatrix
                                                        modules={visibleModules}
                                                        active={activeModule}
                                                        onFocus={setFocusedModule}
                                                        held={draft.capabilities}
                                                        canWrite={canWrite}
                                                        onToggle={toggle}
                                                        onToggleModule={toggleModule}
                                                    />
                                                )}
                                            </>
                                        )}
                                    </div>

                                    {/*
                                        Pinned rather than riding the header:
                                        the change somebody just made is at the
                                        bottom of a long list, and the count
                                        says what is pending without them
                                        having to remember.
                                    */}
                                    <footer
                                        className={`rp-foot${dirty ? ' is-dirty' : ''}`}
                                        hidden={!canWrite}
                                    >
                                        <span className="rp-foot-state">
                                            {dirty ? (
                                                <>
                                                    <i className="ti ti-point-filled" />
                                                    {changes} unsaved change
                                                    {changes === 1 ? '' : 's'}
                                                </>
                                            ) : (
                                                <>
                                                    <i className="ti ti-circle-check" />
                                                    All changes saved
                                                </>
                                            )}
                                        </span>

                                        <Button
                                            variant="light"
                                            disabled={!dirty || saving}
                                            onClick={discard}
                                        >
                                            Discard Changes
                                        </Button>

                                        <Button
                                            loading={saving}
                                            disabled={!dirty || !draft.name.trim()}
                                            onClick={onSave}
                                        >
                                            Save Changes
                                        </Button>
                                    </footer>
                                </>
                            )}
                        </div>
                    </div>
                </>
            )}
        </>
    );
}

/* -------------------------------------------------------------------------- */

/**
 * A module list on the left, one module's permission table on the right.
 *
 * Rows are the module's REAL capabilities, exactly as ModuleRegistry names
 * them — never a fixed View/Create/Edit/Delete grid. Most modules do not fit
 * one: `pharmacy` has ten differently-named rights, `documents` has a
 * clinical/administrative split with no "delete" at all. A grid with columns
 * that stay empty for module after module — a fabricated "Export" nothing in
 * the system can do — would be exactly the kind of UI this codebase spends
 * its effort not building: a checkbox that promises a capability that does
 * not exist.
 *
 * `modules` arrives already narrowed to what THIS role's scope may hold (see
 * `grantScope` in RolesPage) — a branch role's left-hand list never shows an
 * organization-only module heading with nothing valid inside it.
 */
function PermissionMatrix({
    modules,
    active,
    onFocus,
    held,
    canWrite,
    onToggle,
    onToggleModule,
}: {
    modules: GrantableModule[];
    /** The module whose table is on screen; null only when the list is empty. */
    active: GrantableModule | null;
    onFocus: (key: string) => void;
    held: string[];
    canWrite: boolean;
    onToggle: (key: string) => void;
    onToggleModule: (module: GrantableModule) => void;
}) {
    if (!active) {
        return null;
    }

    const activeKeys = active.capabilities.map((capability) => capability.key);
    const activeHeld = activeKeys.filter((key) => held.includes(key)).length;
    const activeAll = activeHeld === activeKeys.length;

    return (
        <div className="rp-matrix">
            <aside className="rp-matrix-nav" role="tablist" aria-label="Modules">
                {toModuleSections(modules).map((section) => (
                    <Fragment key={section.title || 'top'}>
                        {section.title && (
                            <p className="rp-matrix-heading" role="presentation">
                                {section.title}
                            </p>
                        )}

                        {section.modules.map((module) => {
                            const keys = module.capabilities.map((capability) => capability.key);
                            const count = keys.filter((key) => held.includes(key)).length;
                            const tone = MODULE_TONE[module.key] ?? 'slate';

                            return (
                                <button
                                    type="button"
                                    role="tab"
                                    key={module.key}
                                    aria-selected={active.key === module.key}
                                    className={`rp-matrix-item${active.key === module.key ? ' is-active' : ''}${count > 0 ? ' is-on' : ''}`}
                                    onClick={() => onFocus(module.key)}
                                >
                                    <span className={`rp-module-icon is-${tone}`}>
                                        <i className={module.icon ?? 'ti ti-puzzle'} />
                                    </span>

                                    <span className="rp-matrix-item-text">
                                        <b>{module.name}</b>
                                        <small>
                                            {count} / {keys.length}
                                        </small>
                                    </span>

                                    <i className="ti ti-chevron-right" aria-hidden="true" />
                                </button>
                            );
                        })}
                    </Fragment>
                ))}
            </aside>

            <section className="rp-matrix-detail">
                <header className="rp-matrix-head">
                    <span className={`rp-module-icon is-lg is-${MODULE_TONE[active.key] ?? 'slate'}`}>
                        <i className={active.icon ?? 'ti ti-puzzle'} />
                    </span>

                    <div className="rp-matrix-head-text">
                        <h6>{active.name}</h6>
                        <p>
                            {activeHeld} of {activeKeys.length} permissions enabled
                        </p>
                    </div>

                    {/*
                        Ticks or clears the whole module in one move — the same
                        `toggleModule` a select-all checkbox called before.
                        Framed as a switch because that is what it reads as:
                        this module, on or off for this role.
                    */}
                    <div className="form-check form-switch rp-matrix-switch">
                        <input
                            type="checkbox"
                            className="form-check-input"
                            role="switch"
                            id={`rp-matrix-all-${active.key}`}
                            checked={activeAll}
                            disabled={!canWrite}
                            onChange={() => onToggleModule(active)}
                        />
                        <label htmlFor={`rp-matrix-all-${active.key}`}>Enable all</label>
                    </div>
                </header>

                <div className="pf-table-wrap">
                    <table className="pf-table rp-matrix-table">
                        <thead>
                            <tr>
                                <th>Permission</th>
                                <th className="rp-matrix-grant-col">Grant</th>
                            </tr>
                        </thead>

                        <tbody>
                            {/*
                                The screens it opens, then what it can do on
                                them — the two questions somebody setting a
                                role is actually asking. A doctor's own screens
                                come apart, as they do in the sidebar.
                            */}
                            {(
                                [
                                    [
                                        'Can open',
                                        active.capabilities.filter(
                                            (capability) => capability.screen && !MY_WORK.includes(capability.key),
                                        ),
                                    ],
                                    [
                                        'My work (doctors)',
                                        active.capabilities.filter((capability) => MY_WORK.includes(capability.key)),
                                    ],
                                    ['Can do', active.capabilities.filter((capability) => !capability.screen)],
                                ] as [string, typeof active.capabilities][]
                            )
                                .filter(([, rows]) => rows.length > 0)
                                .map(([label, rows]) => [
                                    <tr key={label} className="rp-matrix-group">
                                        <th colSpan={2}>{label}</th>
                                    </tr>,
                                    ...rows.map((capability) => {
                                        const on = held.includes(capability.key);

                                        /*
                                         * Says why this one cannot be unticked
                                         * alone: the module's actions rest on it.
                                         */
                                        const required =
                                            active.key !== 'appointments' &&
                                            capability.key === `${active.key}.view` &&
                                            activeHeld > 1 &&
                                            on;

                                        return (
                                            <tr key={capability.key} className={on ? 'is-on' : undefined}>
                                                <td>
                                                    <b>{capability.name}</b>
                                                    {required && (
                                                        <div className="rp-matrix-key">
                                                            <em>needed by the rest</em>
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="rp-matrix-grant-col">
                                                    <div className="form-check form-switch m-0">
                                                        <input
                                                            type="checkbox"
                                                            className="form-check-input"
                                                            role="switch"
                                                            aria-label={capability.name}
                                                            checked={on}
                                                            disabled={!canWrite}
                                                            onChange={() => onToggle(capability.key)}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    }),
                                ])}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}

/**
 * Somewhere to start, offered only while nothing is ticked.
 *
 * The list comes from the server — the same library a new organization is
 * seeded from — and arrives already narrowed to what is grantable here, so
 * nothing has to be filtered on the way in.
 */
function Templates({
    scope,
    onApply,
}: {
    scope: RoleScope;
    onApply: (capabilities: string[], icon: string) => void;
}) {
    const { data: templates } = useRoleTemplates(scope);

    if ((templates ?? []).length === 0) {
        return null;
    }

    return (
        <section className="rp-templates">
            <header>
                <i className="ti ti-sparkles" />
                <b>Start from a job somebody actually does</b>
                <span>or tick your own below</span>
            </header>

            <div>
                {(templates ?? []).map((template) => (
                    <button
                        type="button"
                        key={template.key}
                        className="rp-template"
                        onClick={() => onApply(template.capabilities, template.icon)}
                    >
                        <i className={template.icon} />
                        <b>{template.name}</b>
                        <small>{template.summary}</small>
                        <em>
                            {template.capabilities.length} permission
                            {template.capabilities.length === 1 ? '' : 's'}
                        </em>
                    </button>
                ))}
            </div>
        </section>
    );
}

/**
 * What this role means, rather than what it contains.
 *
 * The menu preview answers "what does this actually do", and the spread
 * answers "is this the powerful one" — both questions the checkbox list can
 * only answer by being read in full.
 */
function Overview({
    preview,
    roles,
    selectedId,
    total,
    onPick,
}: {
    preview: ReturnType<typeof tenantNavigation>;
    roles: Role[];
    selectedId: number | undefined;
    total: number;
    onPick: (role: Role) => void;
}) {
    return (
        <div className="rp-overview">
            <section className="rp-card">
                <header>
                    <i className="ti ti-eye" />
                    <b>What they will see</b>
                    <span>Their menu, as this role stands</span>
                </header>

                {preview.length === 0 ? (
                    <p className="rp-none">
                        Nothing yet — with no permissions they can sign in and reach only their own
                        dashboard.
                    </p>
                ) : (
                    <div className="rp-preview">
                        {preview.map((section) => (
                            <div key={section.title}>
                                <h6>{section.title}</h6>
                                <ul>
                                    {section.items.map((item) => (
                                        <li key={item.to}>
                                            <i className={item.icon} />
                                            {item.label}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {total > 0 && roles.length > 0 && (
                <section className="rp-card">
                    <header>
                        <i className="ti ti-chart-bar" />
                        <b>How much each role holds</b>
                        <span>of {total} permissions</span>
                    </header>

                    {/*
                        Shares of the TOTAL, not of the largest row — which is
                        why this is not a BarList. That component scales to its
                        peak, correctly, for comparing categories to each
                        other; here the denominator is fixed and meaningful,
                        and a role holding five of nine must not draw as full
                        merely because nothing else is bigger.
                    */}
                    <div className="rp-spread">
                        {roles.map((role) => {
                            const share = Math.round((role.capabilities.length / total) * 100);

                            return (
                                <button
                                    type="button"
                                    key={role.id}
                                    className={`rp-spread-row${
                                        selectedId === role.id ? ' is-active' : ''
                                    }`}
                                    onClick={() => onPick(role)}
                                >
                                    <span title={role.name}>{role.name}</span>

                                    <span className="rp-spread-track">
                                        <span
                                            className={`rp-spread-fill${
                                                share >= 75 ? ' is-broad' : ''
                                            }`}
                                            // A 0% role still needs to read as
                                            // a row rather than a missing one.
                                            style={{ width: `${Math.max(share, 2)}%` }}
                                        />
                                    </span>

                                    <b>{role.capabilities.length}</b>
                                </button>
                            );
                        })}
                    </div>
                </section>
            )}
        </div>
    );
}

/** Who holds this role — the names behind the count. */
function Members({
    loading,
    members,
}: {
    loading: boolean;
    members: { id: number; name: string; email: string; is_active: boolean; location: string | null }[];
}) {
    if (loading) {
        return <LoadingBlock label="Loading members…" />;
    }

    if (members.length === 0) {
        return (
            <div className="org-pending">
                <i className="ti ti-users" />
                <h6>Nobody holds this role</h6>
                <p>Assign it to somebody from their entry under People.</p>
            </div>
        );
    }

    return (
        <ul className="rp-members">
            {members.map((member) => (
                <li key={member.id}>
                    <span className="rp-member-avatar">
                        {member.name.charAt(0).toUpperCase()}
                    </span>

                    <span className="rp-member-text">
                        <b>{member.name}</b>
                        <small>{member.email}</small>
                    </span>

                    {member.location && <span className="rp-member-at">{member.location}</span>}

                    <span
                        className={`rp-member-state is-${member.is_active ? 'on' : 'off'}`}
                    >
                        {member.is_active ? 'Active' : 'Inactive'}
                    </span>
                </li>
            ))}
        </ul>
    );
}
