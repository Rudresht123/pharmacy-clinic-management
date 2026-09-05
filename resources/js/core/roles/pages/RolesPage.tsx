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
import { rolesHooks, useGrantable, useRoleMembers } from '../api';
import { ROLE_TEMPLATES, resolveTemplate } from '../templates';
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
    const { data: modules, refetch: refetchGrantable } = useGrantable();

    const create = rolesHooks.useCreate();
    const update = rolesHooks.useUpdate();
    const remove = rolesHooks.useRemove();

    const [selected, setSelected] = useState<number | 'new' | null>(null);
    const [draft, setDraft] = useState<Draft>(BLANK);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [tab, setTab] = useState<Tab>('permissions');
    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<string[]>([]);
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

    const { data: members, isLoading: membersLoading } = useRoleMembers(
        current?.id,
        tab === 'members',
    );

    /** How many there are to hold — "4 of 9" says more than "4". */
    const totalCapabilities = useMemo(
        () => (modules ?? []).reduce((sum, module) => sum + module.capabilities.length, 0),
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
    const { modules: orgModules, user } = useTenantAuth();
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

            patch({ capabilities: draft.capabilities.filter((held) => !drop.includes(held)) });

            return;
        }

        const add = viewKey && key !== viewKey ? [key, viewKey] : [key];

        patch({ capabilities: [...new Set([...draft.capabilities, ...add])] });
    }

    function toggleModule(module: GrantableModule) {
        const keys = module.capabilities.map((capability) => capability.key);
        const all = keys.every((key) => draft.capabilities.includes(key));

        patch({
            capabilities: all
                ? draft.capabilities.filter((key) => !keys.includes(key))
                : [...new Set([...draft.capabilities, ...keys])],
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
                                                        This role belongs to the whole
                                                        organization, so changing it here would
                                                        change it at every branch. Copy it to this
                                                        branch to make your own version.
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

                                                    <button
                                                        type="button"
                                                        className="rp-act"
                                                        onClick={() => setCollapsed([])}
                                                    >
                                                        <i className="ti ti-chevrons-up" />
                                                        Expand All
                                                    </button>

                                                    <button
                                                        type="button"
                                                        className="rp-act"
                                                        onClick={() =>
                                                            setCollapsed(
                                                                (modules ?? []).map((m) => m.key),
                                                            )
                                                        }
                                                    >
                                                        <i className="ti ti-chevrons-down" />
                                                        Collapse All
                                                    </button>
                                                </div>

                                                {draft.capabilities.length === 0 && !search && canWrite && (
                                                    <Templates
                                                        modules={modules ?? []}
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
                                                    visibleModules.map((module) => {
                                                        const keys = module.capabilities.map(
                                                            (c) => c.key,
                                                        );
                                                        const held = keys.filter((key) =>
                                                            draft.capabilities.includes(key),
                                                        ).length;
                                                        const all = held === keys.length;
                                                        const shut = collapsed.includes(
                                                            module.key,
                                                        );
                                                        const tone =
                                                            MODULE_TONE[module.key] ?? 'slate';

                                                        return (
                                                            <section
                                                                className={`rp-module${
                                                                    held > 0 ? ' is-on' : ''
                                                                }`}
                                                                key={module.key}
                                                            >
                                                                <header>
                                                                    <span
                                                                        className={`rp-module-icon is-${tone}`}
                                                                    >
                                                                        <i
                                                                            className={
                                                                                module.icon ??
                                                                                'ti ti-puzzle'
                                                                            }
                                                                        />
                                                                    </span>

                                                                    <span className="rp-module-text">
                                                                        <b>{module.name}</b>
                                                                        <small>
                                                                            {held} / {keys.length}{' '}
                                                                            permissions enabled
                                                                        </small>
                                                                    </span>

                                                                    <label className="rp-selectall">
                                                                        <input
                                                                            type="checkbox"
                                                                            className="form-check-input"
                                                                            checked={all}
                                                                            disabled={!canWrite}
                                                                            onChange={() =>
                                                                                toggleModule(
                                                                                    module,
                                                                                )
                                                                            }
                                                                        />
                                                                        Select all
                                                                    </label>

                                                                    <button
                                                                        type="button"
                                                                        className="rp-collapse"
                                                                        aria-expanded={!shut}
                                                                        aria-label={
                                                                            shut
                                                                                ? 'Expand'
                                                                                : 'Collapse'
                                                                        }
                                                                        onClick={() =>
                                                                            setCollapsed((list) =>
                                                                                shut
                                                                                    ? list.filter(
                                                                                          (k) =>
                                                                                              k !==
                                                                                              module.key,
                                                                                      )
                                                                                    : [
                                                                                          ...list,
                                                                                          module.key,
                                                                                      ],
                                                                            )
                                                                        }
                                                                    >
                                                                        <i
                                                                            className={
                                                                                shut
                                                                                    ? 'ti ti-chevron-down'
                                                                                    : 'ti ti-chevron-up'
                                                                            }
                                                                        />
                                                                    </button>
                                                                </header>

                                                                {!shut && (
                                                                    <div className="rp-caps">
                                                                        {module.capabilities.map(
                                                                            (capability) => {
                                                                                const on =
                                                                                    draft.capabilities.includes(
                                                                                        capability.key,
                                                                                    );

                                                                                /*
                                                                                 * Says why this one
                                                                                 * cannot be unticked
                                                                                 * alone: the rest of
                                                                                 * the module rests
                                                                                 * on it.
                                                                                 */
                                                                                const required =
                                                                                    capability.key.endsWith(
                                                                                        '.view',
                                                                                    ) && held > 1;

                                                                                return (
                                                                                    <label
                                                                                        className={`rp-cap${
                                                                                            on
                                                                                                ? ' is-on'
                                                                                                : ''
                                                                                        }`}
                                                                                        key={
                                                                                            capability.key
                                                                                        }
                                                                                        title={
                                                                                            capability.key
                                                                                        }
                                                                                    >
                                                                                        <input
                                                                                            type="checkbox"
                                                                                            className="form-check-input"
                                                                                            checked={
                                                                                                on
                                                                                            }
                                                                                            disabled={
                                                                                                !canWrite
                                                                                            }
                                                                                            onChange={() =>
                                                                                                toggle(
                                                                                                    capability.key,
                                                                                                )
                                                                                            }
                                                                                        />
                                                                                        <span>
                                                                                            {
                                                                                                capability.name
                                                                                            }
                                                                                        </span>
                                                                                        {required && (
                                                                                            <em>
                                                                                                needed
                                                                                                by
                                                                                                the
                                                                                                rest
                                                                                            </em>
                                                                                        )}
                                                                                    </label>
                                                                                );
                                                                            },
                                                                        )}
                                                                    </div>
                                                                )}
                                                            </section>
                                                        );
                                                    })
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

/** Somewhere to start, offered only while nothing is ticked. */
function Templates({
    modules,
    onApply,
}: {
    modules: GrantableModule[];
    onApply: (capabilities: string[], icon: string) => void;
}) {
    return (
        <section className="rp-templates">
            <header>
                <i className="ti ti-sparkles" />
                <b>Start from a job somebody actually does</b>
                <span>or tick your own below</span>
            </header>

            <div>
                {ROLE_TEMPLATES.map((template) => {
                    const capabilities = resolveTemplate(template, modules);

                    if (capabilities.length === 0) {
                        return null;
                    }

                    return (
                        <button
                            type="button"
                            key={template.key}
                            className="rp-template"
                            onClick={() => onApply(capabilities, template.icon)}
                        >
                            <i className={template.icon} />
                            <b>{template.name}</b>
                            <small>{template.summary}</small>
                            <em>
                                {capabilities.length} permission
                                {capabilities.length === 1 ? '' : 's'}
                            </em>
                        </button>
                    );
                })}
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
