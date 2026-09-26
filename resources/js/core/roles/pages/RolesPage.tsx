import { useEffect, useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { notify } from '@/shared/utils/notify';
import { useConfigurableEntities } from '@/core/field-settings/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { tenantNavigation } from '@/app/tenant-navigation';
import {
    rolesHooks,
    useBranchRolePermissions,
    useGrantable,
    useRoleMembers,
    useRoleTemplates,
    useSaveBranchRolePermissions,
} from '../api';
import { ROLE_ICONS, type GrantableModule, type Role } from '../types';

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
    };
}

/**
 * Level three of the permission flow — what a person may do.
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

    // Land on the first role rather than an empty pane, which reads as an
    // error when there is nothing wrong.
    useEffect(() => {
        if (selected === null && roles?.length) {
            setSelected(roles[0].id);
            setDraft(toDraft(roles[0]));
        }
    }, [roles, selected]);

    const current = useMemo(
        () => (typeof selected === 'number' ? roles?.find((role) => role.id === selected) : null),
        [roles, selected],
    );

    /*
     * Every role written from this screen comes out branch-scoped — the
     * payload has no scope field, so the server defaults it to `branch`
     * whoever is asking (RoleController::store). So a NEW role's pool is
     * branch-only; an EXISTING role's pool follows its own scope, because an
     * organization-wide role (Head office, Doctor) may hold a branch
     * capability too and must not have those hidden.
     */
    const grantScope = current?.scope ?? 'branch';
    const { data: modules, refetch: refetchGrantable } = useGrantable(grantScope);

    /*
     * The WHOLE registry, unscoped — never what is rendered as ticks, only
     * what "X of Y" and the cross-role spread chart divide by. Those compare
     * roles of possibly different scopes against one shared ceiling; dividing
     * by the SELECTED role's own (smaller, scope-filtered) pool would make
     * that ceiling jump every time a different role was clicked, and would
     * make an organization role's bar look artificially short next to a
     * branch role's.
     */
    const { data: allModules } = useGrantable();

    const { data: members, isLoading: membersLoading } = useRoleMembers(
        current?.id,
        tab === 'members',
    );

    /** How many there are, in total, to hold — "4 of 9" says more than "4". */
    const totalCapabilities = useMemo(
        () => (allModules ?? []).reduce((sum, module) => sum + module.capabilities.length, 0),
        [allModules],
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
    const { modules: orgModules, user, activeBranch, refreshSession } = useTenantAuth();
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
        visibleModules.find((module) => module.key === focusedModule) ?? visibleModules[0] ?? null;

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
                ? { ...toDraft(from), id: 0, name: `${from.name} copy` }
                : { ...BLANK, name: '' },
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
     * Every module's `.view` capability is implied by the others in it:
     * "add and edit customers" without "view customers" is not a narrower
     * permission, it is a broken one — the list would answer 403 while the
     * save button worked. So anything ticked brings view with it, and
     * unticking view takes its whole module away rather than leaving that
     * state reachable by a different route.
     */
    function toggle(key: string) {
        const module = (modules ?? []).find((entry) =>
            entry.capabilities.some((capability) => capability.key === key),
        );

        const viewKey = module?.capabilities.find((capability) =>
            capability.key.endsWith('.view'),
        )?.key;

        if (draft.capabilities.includes(key)) {
            const drop =
                key === viewKey && module
                    ? module.capabilities.map((capability) => capability.key)
                    : [key];

            patch({
                capabilities: draft.capabilities.filter((held) => !drop.includes(held)),
                // A lock on something the role no longer holds is a rule about
                // nothing, waiting to surprise whoever grants it again later.
                locked: draft.locked.filter((key) => !drop.includes(key)),
            });

            return;
        }

        const add = viewKey && key !== viewKey ? [key, viewKey] : [key];

        patch({ capabilities: [...new Set([...draft.capabilities, ...add])] });
    }

    /** Whether a branch may take this one away — the organization's answer. */
    function toggleLock(key: string) {
        patch({
            locked: draft.locked.includes(key)
                ? draft.locked.filter((entry) => entry !== key)
                : [...draft.locked, key],
        });
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

            /*
             * Omitted entirely rather than sent empty when this screen is not
             * in a position to set locks. The server reads a missing `locked`
             * as "leave them alone" and an empty one as "unlock everything" —
             * so a branch manager editing a role must say nothing, or they
             * would silently undo the organization's locks by saving a name.
             */
            ...(canLock ? { locked: draft.locked } : {}),
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

    /*
     * Whether this person may change THIS role, rather than roles in general.
     *
     * The owner may change any. Anybody else may change only their own
     * branch's: an organization role is shared by every branch, so editing one
     * here would quietly change what a receptionist may do everywhere — which
     * is the whole reason a branch writes its own instead.
     */
    const isOwner = user?.role === 'owner';
    const canWrite = !current || isOwner || current.location_id !== null;

    /*
     * Whether the lock column means anything here.
     *
     * A lock is the organization telling BRANCHES "not this one", so it needs
     * both a role branches share and somebody speaking for the organization.
     * A branch's own role has no third party to be locked against, and a new
     * role written here is organization-wide and branch-assigned, which is
     * exactly the kind that does.
     */
    const canLock = isOwner && (current?.is_customisable_by_branch ?? true);

    /*
     * The other way to change what a role means here.
     *
     * A branch manager cannot edit the organization's role — that would change
     * it at every branch — but may take capabilities off it FOR THEIR OWN
     * BRANCH. Before this level existed the only answer was "copy it to this
     * branch", which is how a chain ends up with five Receptionists that drift
     * apart; the copy is still offered, for when a branch genuinely wants a
     * different job rather than a narrower version of the same one.
     */
    const branchCustomising = !canWrite && (current?.is_customisable_by_branch ?? false);

    const { data: branchPermissions } = useBranchRolePermissions(
        branchCustomising ? activeBranch : undefined,
        branchCustomising ? current?.id : undefined,
    );

    const saveBranch = useSaveBranchRolePermissions(activeBranch, current?.id);

    /*
     * What this role actually grants HERE: the organization's set, minus what
     * this branch has already taken off it. Held in its own state because it
     * is a different thing being edited from `draft` — the role itself is not
     * changing at all.
     */
    const [branchAllowed, setBranchAllowed] = useState<string[] | null>(null);

    useEffect(() => {
        setBranchAllowed(
            branchPermissions
                ? branchPermissions.inherited.filter(
                      (key) => !branchPermissions.removed.includes(key),
                  )
                : null,
        );
    }, [branchPermissions]);

    const branchLocked = branchPermissions?.locked ?? [];
    const branchDirty =
        branchAllowed !== null &&
        branchPermissions !== undefined &&
        [...branchAllowed].sort().join() !==
            branchPermissions.inherited
                .filter((key) => !branchPermissions.removed.includes(key))
                .sort()
                .join();

    function toggleForBranch(key: string) {
        if (branchAllowed === null || branchLocked.includes(key)) {
            return;
        }

        setBranchAllowed(
            branchAllowed.includes(key)
                ? branchAllowed.filter((entry) => entry !== key)
                : [...branchAllowed, key],
        );
    }

    /** The same "all of this module" switch, bounded by what a branch may touch. */
    function toggleModuleForBranch(module: GrantableModule) {
        if (branchAllowed === null) return;

        const mine = module.capabilities
            .map((capability) => capability.key)
            .filter(
                (key) =>
                    (branchPermissions?.inherited ?? []).includes(key) &&
                    !branchLocked.includes(key),
            );

        const all = mine.every((key) => branchAllowed.includes(key));

        setBranchAllowed(
            all
                ? branchAllowed.filter((key) => !mine.includes(key))
                : [...new Set([...branchAllowed, ...mine])],
        );
    }

    async function saveForBranch() {
        if (branchAllowed === null) return;

        try {
            await saveBranch.mutateAsync(branchAllowed);

            // This may have just changed what the person saving it may do.
            await refreshSession();
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        }
    }

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
                    <b>{role.name}</b>

                    <small>
                        {role.capabilities.length} permission
                        {role.capabilities.length === 1 ? '' : 's'} · {role.users_count ?? 0}{' '}
                        {role.users_count === 1 ? 'member' : 'members'}
                    </small>
                </span>

                {/*
                    Who wrote it — shown to everybody except the owner, for
                    whom it is noise because every role here is theirs. For a
                    branch it is the difference between a role they may change
                    and one they may only copy, and reading that off the list
                    is faster than clicking each to find out.
                */}
                {!isOwner && (
                    <span className={`rp-owner${role.location_id === null ? ' is-shared' : ''}`}>
                        {role.location_id === null ? 'Organization' : (role.location ?? 'Branch')}
                    </span>
                )}

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
                subtitle="Manage roles and control access across your organization."
                icon="ti ti-shield-lock"
                tone="violet"
                crumbs={[{ label: 'Roles & Permissions' }]}
                actions={
                    <Button icon="ti ti-plus" onClick={() => startNew()}>
                        New Role
                    </Button>
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
                                <button
                                    type="button"
                                    className="rp-rail-add"
                                    aria-label="New role"
                                    onClick={() => startNew()}
                                >
                                    <i className="ti ti-plus" />
                                </button>
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

                                {(roles ?? []).map(railItem)}

                                {(roles ?? []).length === 0 && selected !== 'new' && (
                                    <p className="rp-rail-empty">No roles yet.</p>
                                )}
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
                                                <h5>
                                                    {selected === 'new'
                                                        ? draft.name.trim() || 'New role'
                                                        : (current?.name ?? draft.name)}
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

                                            <div className="rp-head-actions">
                                                {/*
                                                    An organization role is shared by
                                                    every branch. Rather than only
                                                    refusing the edit, offer the way
                                                    out: a copy this branch owns and
                                                    may change freely.
                                                */}
                                                {!canWrite && current && (
                                                    <button
                                                        type="button"
                                                        className="rp-act"
                                                        onClick={() => startNew(current)}
                                                    >
                                                        <i className="ti ti-copy" />
                                                        Copy to this branch
                                                    </button>
                                                )}

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

                                                {current && (
                                                    <div className="rp-menu">
                                                        <button
                                                            type="button"
                                                            className="rp-act is-icon"
                                                            aria-label="More"
                                                            aria-expanded={menuOpen}
                                                            onClick={() =>
                                                                setMenuOpen((open) => !open)
                                                            }
                                                        >
                                                            <i className="ti ti-dots-vertical" />
                                                        </button>

                                                        {menuOpen && (
                                                            <div className="rp-menu-list">
                                                                {/* The fastest way to a role
                                                                    that is "the receptionist,
                                                                    but without deletions". */}
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        startNew(current)
                                                                    }
                                                                >
                                                                    <i className="ti ti-copy" />
                                                                    Duplicate role
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

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
                                                        {branchCustomising ? (
                                                            <>
                                                                These permissions come from your
                                                                organisation. You can switch them
                                                                off for this branch alone — every
                                                                other branch keeps its own. A
                                                                padlock means your organisation
                                                                does not allow that one to be
                                                                changed here.
                                                            </>
                                                        ) : (
                                                            <>
                                                                This role belongs to the whole
                                                                organization, so changing it here
                                                                would change it at every branch.
                                                                Copy it to this branch to make your
                                                                own version.
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
                                                roles={roles ?? []}
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
                                                        held={
                                                            branchCustomising
                                                                ? (branchAllowed ?? [])
                                                                : draft.capabilities
                                                        }
                                                        locked={
                                                            branchCustomising
                                                                ? branchLocked
                                                                : draft.locked
                                                        }
                                                        lockMode={
                                                            branchCustomising
                                                                ? 'show'
                                                                : canLock
                                                                  ? 'edit'
                                                                  : 'none'
                                                        }
                                                        onToggleLock={toggleLock}
                                                        /*
                                                         * In branch mode only what the
                                                         * organization already grants
                                                         * this role can be touched —
                                                         * everything else is not a
                                                         * choice this branch has.
                                                         */
                                                        toggleable={
                                                            branchCustomising
                                                                ? (branchPermissions?.inherited ??
                                                                  [])
                                                                : null
                                                        }
                                                        canWrite={branchCustomising || canWrite}
                                                        onToggle={
                                                            branchCustomising
                                                                ? toggleForBranch
                                                                : toggle
                                                        }
                                                        onToggleModule={
                                                            branchCustomising
                                                                ? toggleModuleForBranch
                                                                : toggleModule
                                                        }
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
                                    {/*
                                        The branch's own footer. Says whose
                                        change this is — the organization's role
                                        is not being touched — because the same
                                        table above edits two different things
                                        depending on who is looking at it.
                                    */}
                                    {branchCustomising ? (
                                        <footer
                                            className={`rp-foot${branchDirty ? ' is-dirty' : ''}`}
                                        >
                                            <span className="rp-foot-state">
                                                {branchDirty ? (
                                                    <>
                                                        <i className="ti ti-point-filled" />
                                                        Unsaved changes for this branch
                                                    </>
                                                ) : (
                                                    <>
                                                        <i className="ti ti-building-store" />
                                                        {(branchPermissions?.removed.length ?? 0) >
                                                        0
                                                            ? `${branchPermissions?.removed.length} switched off for this branch`
                                                            : 'Using your organisation’s settings'}
                                                    </>
                                                )}
                                            </span>

                                            {/*
                                                Back to the organisation's own
                                                answer — every customisation
                                                this branch has made, dropped.
                                                Offered only when there is
                                                something to drop.
                                            */}
                                            {(branchPermissions?.removed.length ?? 0) > 0 && (
                                                <Button
                                                    variant="light"
                                                    icon="ti ti-rotate"
                                                    disabled={saveBranch.isPending}
                                                    onClick={() =>
                                                        setBranchAllowed(
                                                            branchPermissions?.inherited ?? [],
                                                        )
                                                    }
                                                >
                                                    Use organisation settings
                                                </Button>
                                            )}

                                            <Button
                                                variant="light"
                                                disabled={!branchDirty || saveBranch.isPending}
                                                onClick={() =>
                                                    setBranchAllowed(
                                                        branchPermissions
                                                            ? branchPermissions.inherited.filter(
                                                                  (key) =>
                                                                      !branchPermissions.removed.includes(
                                                                          key,
                                                                      ),
                                                              )
                                                            : null,
                                                    )
                                                }
                                            >
                                                Discard Changes
                                            </Button>

                                            <Button
                                                loading={saveBranch.isPending}
                                                disabled={!branchDirty}
                                                onClick={saveForBranch}
                                            >
                                                Save for this branch
                                            </Button>
                                        </footer>
                                    ) : (
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
                                    )}
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
    locked,
    lockMode,
    onToggleLock,
    toggleable,
    canWrite,
    onToggle,
    onToggleModule,
}: {
    modules: GrantableModule[];
    /** The module whose table is on screen; null only when the list is empty. */
    active: GrantableModule | null;
    onFocus: (key: string) => void;
    held: string[];
    /** Which of those a branch may not take away for itself. */
    locked: string[];
    /**
     * What the lock column is for here, if anything.
     *
     *   edit  the organization deciding what branches may not remove
     *   show  a branch being told which of those it may not remove
     *   none  neither applies, and the column is absent rather than empty —
     *         a row of padlocks nobody can press promises a control that is
     *         not there
     */
    lockMode: 'edit' | 'show' | 'none';
    onToggleLock: (key: string) => void;
    /**
     * Which capabilities may be switched at all, or null for "any of them".
     *
     * A branch customising the organization's role may only turn OFF what it
     * already has; a capability the organization never granted is not a choice
     * this screen has, so its switch is dead rather than misleading.
     */
    toggleable: string[] | null;
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
                {modules.map((module) => {
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
                                {lockMode !== 'none' && (
                                    <th
                                        className="rp-matrix-grant-col"
                                        title="Branches cannot remove a locked permission"
                                    >
                                        Locked
                                    </th>
                                )}
                            </tr>
                        </thead>

                        <tbody>
                            {active.capabilities.map((capability) => {
                                const on = held.includes(capability.key);
                                const isLocked = locked.includes(capability.key);

                                // Null means every capability is this screen's
                                // to switch; a list means only those are.
                                const mine = toggleable === null || toggleable.includes(capability.key);

                                /*
                                 * Says why this one cannot be unticked alone:
                                 * the rest of the module rests on it.
                                 */
                                const required = capability.key.endsWith('.view') && activeHeld > 1 && on;

                                return (
                                    <tr key={capability.key} className={on ? 'is-on' : undefined}>
                                        <td>
                                            <b>{capability.name}</b>
                                            <div className="rp-matrix-key">
                                                <code>{capability.key}</code>
                                                {required && <em>needed by the rest</em>}
                                            </div>
                                        </td>

                                        <td className="rp-matrix-grant-col">
                                            <div className="form-check form-switch m-0">
                                                <input
                                                    type="checkbox"
                                                    className="form-check-input"
                                                    role="switch"
                                                    aria-label={capability.name}
                                                    checked={on}
                                                    disabled={
                                                        !canWrite ||
                                                        !mine ||
                                                        (lockMode === 'show' && isLocked)
                                                    }
                                                    onChange={() => onToggle(capability.key)}
                                                />
                                            </div>
                                        </td>

                                        {lockMode !== 'none' && (
                                            <td className="rp-matrix-grant-col">
                                                {/*
                                                    A padlock only where it says
                                                    something: on what the role
                                                    actually holds for an owner
                                                    setting them, and only on
                                                    what is actually locked for
                                                    a branch being told.
                                                */}
                                                {lockMode === 'edit' && on && (
                                                    <button
                                                        type="button"
                                                        className={`rp-lock${isLocked ? ' is-on' : ''}`}
                                                        aria-pressed={isLocked}
                                                        aria-label={`Lock ${capability.name} against branch changes`}
                                                        title={
                                                            isLocked
                                                                ? 'Branches cannot remove this'
                                                                : 'Branches may remove this'
                                                        }
                                                        disabled={!canWrite}
                                                        onClick={() => onToggleLock(capability.key)}
                                                    >
                                                        <i
                                                            className={
                                                                isLocked
                                                                    ? 'ti ti-lock'
                                                                    : 'ti ti-lock-open'
                                                            }
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                )}

                                                {lockMode === 'show' && isLocked && (
                                                    <span
                                                        className="rp-lock is-on"
                                                        title="Locked by your organisation"
                                                    >
                                                        <i className="ti ti-lock" aria-hidden="true" />
                                                    </span>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
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
function Templates({ onApply }: { onApply: (capabilities: string[], icon: string) => void }) {
    const { data: templates } = useRoleTemplates();

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
