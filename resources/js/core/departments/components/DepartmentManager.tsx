import { Fragment, useEffect, useRef, useState, type CSSProperties } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Button } from '@/shared/components/ui/Button';
import { FormModal } from '@/shared/components/ui/FormModal';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { EmptyState, ErrorState, LoadingBlock, StatusBadge } from '@/shared/components/ui/Feedback';
import { FormError, TextareaField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { http, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { departmentsApi, departmentsHooks, useRemoveDepartment } from '../api';
import type { Department, DepartmentPayload } from '../types';

type View = 'list' | 'chart';

const VIEWS: TabItem<View>[] = [
    { value: 'list', label: 'List', icon: 'ti ti-list-tree' },
    { value: 'chart', label: 'Chart', icon: 'ti ti-sitemap' },
];

type FormState = { department?: Department; parentId?: number | null } | null;

type Values = {
    name: string;
    code: string;
    description: string;
    parent_id: string;
    is_active: boolean;
};

/** What a row can do, the same in both views. */
interface Actions {
    editable: boolean;
    edit: (department: Department) => void;
    addChild: (department: Department) => void;
    toggleActive: (department: Department) => void;
    remove: (department: Department) => void;
}

/** One of the category colours the OPD board uses, the same one for the same name every time. */
function tone(name: string): CSSProperties {
    let sum = 0;

    for (let at = 0; at < name.length; at++) {
        sum = (sum * 31 + name.charCodeAt(at)) % 997;
    }

    return { '--dpt-hue': `var(--cat-${(sum % 6) + 1}, rgb(var(--brand)))` } as CSSProperties;
}

/** "General Medicine" → "GM" */
function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');
}

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** The header checkbox: selects/clears every id currently on screen, indeterminate while some but not all are picked. */
function SelectAllCheckbox({
    ids,
    selected,
    onToggleAll,
}: {
    ids: number[];
    selected: Set<number>;
    onToggleAll: (ids: number[]) => void;
}) {
    const ref = useRef<HTMLInputElement>(null);
    const checkedCount = ids.filter((id) => selected.has(id)).length;
    const all = ids.length > 0 && checkedCount === ids.length;

    useEffect(() => {
        if (ref.current) ref.current.indeterminate = checkedCount > 0 && !all;
    }, [checkedCount, all]);

    return (
        <input
            ref={ref}
            type="checkbox"
            className="dpt-check"
            checked={all}
            onChange={() => onToggleAll(ids)}
            aria-label={all ? 'Deselect all departments' : 'Select all departments'}
        />
    );
}

/**
 * Adding or editing one department.
 *
 * The parent list holds departments only — a sub-department never has
 * children — and leaves out the one being edited. A department that already
 * has sub-departments stays a department, so its parent is fixed.
 */
function DepartmentForm({ state, tree, onClose }: { state: FormState; tree: Department[]; onClose: () => void }) {
    const create = departmentsHooks.useCreate();
    const update = departmentsHooks.useUpdate();

    const editing = state?.department;
    const hasChildren = (editing?.children?.length ?? 0) > 0;
    const parents = tree.filter((department) => department.id !== editing?.id);

    const {
        register,
        handleSubmit,
        reset,
        submit,
        watch,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({
        defaultValues: { name: '', code: '', description: '', parent_id: '', is_active: true },
    });

    useEffect(() => {
        if (!state) return;

        reset({
            name: editing?.name ?? '',
            code: editing?.code ?? '',
            description: editing?.description ?? '',
            parent_id: String(editing?.parent_id ?? state.parentId ?? ''),
            is_active: editing?.is_active ?? true,
        });
    }, [state, editing, reset]);

    const parentId = watch('parent_id');
    const parentName = tree.find((department) => String(department.id) === parentId)?.name;

    const onSubmit = handleSubmit(async (values) => {
        const payload: DepartmentPayload = {
            name: values.name.trim(),
            code: values.code.trim() || null,
            description: values.description.trim() || null,
            parent_id: values.parent_id ? Number(values.parent_id) : null,
            is_active: values.is_active,
        };

        // The form's values go to submit (for mapping field errors); the cleaned payload goes to the API.
        const result = await submit(values, () =>
            editing ? update.mutateAsync({ id: editing.id, payload }) : create.mutateAsync(payload),
        );

        if (result) onClose();
    });

    const title = editing ? `Edit ${editing.name}` : state?.parentId ? 'Add sub-department' : 'Add department';

    return (
        <FormModal
            open={state !== null}
            onClose={onClose}
            title={title}
            subtitle={parentName ? `Under ${parentName}` : 'A top-level department'}
            icon={<i className="ti ti-layout-grid" />}
            size="md"
            onSubmit={onSubmit}
            submitting={isSubmitting}
            submitLabel={editing ? 'Save changes' : 'Add'}
        >
            <FormError message={errors.root?.message} />

            <TextField
                name="name"
                label="Name"
                required
                register={register}
                errors={errors}
                placeholder={parentName ? 'Interventional Cardiology' : 'Cardiology'}
                autoFocus
            />

            <div className="row">
                <div className="col-md-6">
                    <div className="mb-3">
                        <label className="form-label" htmlFor="dpt-parent">
                            Parent department
                        </label>
                        <select
                            id="dpt-parent"
                            className={`form-select${errors.parent_id ? ' is-invalid' : ''}`}
                            disabled={hasChildren}
                            {...register('parent_id')}
                        >
                            <option value="">None — this is a department</option>
                            {parents.map((department) => (
                                <option key={department.id} value={department.id}>
                                    {department.name}
                                </option>
                            ))}
                        </select>
                        {errors.parent_id ? (
                            <div className="invalid-feedback d-block">{String(errors.parent_id.message ?? '')}</div>
                        ) : (
                            <small className="text-muted d-block mt-1">
                                {hasChildren
                                    ? 'It has sub-departments, so it stays a department.'
                                    : 'Choose one to make this a sub-department.'}
                            </small>
                        )}
                    </div>
                </div>

                <div className="col-md-6">
                    <TextField name="code" label="Code" register={register} errors={errors} placeholder="CARD" />
                </div>
            </div>

            <TextareaField name="description" label="Description" rows={2} register={register} errors={errors} />

            <div className="form-check form-switch">
                <input id="dpt-active" type="checkbox" className="form-check-input" {...register('is_active')} />
                <label className="form-check-label" htmlFor="dpt-active">
                    Active — offered when choosing a doctor&rsquo;s or a staff member&rsquo;s department
                </label>
            </div>
        </FormModal>
    );
}

/** The small icon buttons on a row or a card. */
function RowActions({ department, child, actions }: { department: Department; child?: boolean; actions: Actions }) {
    if (!actions.editable) return null;

    return (
        <div className="dpt-acts">
            {!child && (
                <button
                    type="button"
                    title="Add sub-department"
                    aria-label={`Add a sub-department to ${department.name}`}
                    onClick={() => actions.addChild(department)}
                >
                    <i className="ti ti-subtask" aria-hidden="true" />
                </button>
            )}
            <button type="button" title="Edit" aria-label={`Edit ${department.name}`} onClick={() => actions.edit(department)}>
                <i className="ti ti-pencil" aria-hidden="true" />
            </button>
            <button
                type="button"
                title={department.is_active ? 'Deactivate' : 'Activate'}
                aria-label={`${department.is_active ? 'Deactivate' : 'Activate'} ${department.name}`}
                onClick={() => actions.toggleActive(department)}
            >
                <i className={department.is_active ? 'ti ti-eye-off' : 'ti ti-eye'} aria-hidden="true" />
            </button>
            <button
                type="button"
                className="is-danger"
                title="Remove"
                aria-label={`Remove ${department.name}`}
                onClick={() => actions.remove(department)}
            >
                <i className="ti ti-trash" aria-hidden="true" />
            </button>
        </div>
    );
}

/**
 * The list view: departments with their sub-departments indented beneath,
 * as rows with the counts in columns. Searching opens every department that
 * holds a match.
 */
function DepartmentList({
    departments,
    actions,
    selected,
    onToggle,
    onToggleAll,
}: {
    departments: Department[];
    actions: Actions;
    selected: Set<number>;
    onToggle: (id: number) => void;
    onToggleAll: (ids: number[]) => void;
}) {
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState<number[]>([]);
    // Off by default: a deactivated department stopped being offered
    // elsewhere, so a list that keeps showing it looks like it did nothing.
    const [showInactive, setShowInactive] = useState(false);

    const inactiveCount = departments.reduce(
        (sum, department) =>
            sum + (department.is_active ? 0 : 1) + (department.children ?? []).filter((child) => !child.is_active).length,
        0,
    );

    const needle = query.trim().toLowerCase();
    const hit = (department: Department) =>
        department.name.toLowerCase().includes(needle) || (department.code ?? '').toLowerCase().includes(needle);

    const rows = departments
        .filter((department) => showInactive || department.is_active)
        .map((department) => {
            const children = (department.children ?? []).filter((child) => showInactive || child.is_active);

            if (!needle || hit(department)) return { department, children };

            const found = children.filter(hit);

            return found.length ? { department, children: found } : null;
        })
        .filter((row): row is { department: Department; children: Department[] } => row !== null);

    const visibleIds = rows.flatMap(({ department, children }) => [
        department.id,
        ...children.map((child) => child.id),
    ]);

    return (
        <>
            <div className="dpt-tools">
                <div className="dpt-search">
                    <i className="ti ti-search" aria-hidden="true" />
                    <input
                        type="search"
                        className="form-control"
                        placeholder="Search departments…"
                        aria-label="Search departments"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>

                <div className="dpt-tools-acts">
                    {inactiveCount > 0 && (
                        <Button
                            variant="light"
                            size="sm"
                            icon={showInactive ? 'ti ti-eye-off' : 'ti ti-eye'}
                            onClick={() => setShowInactive((was) => !was)}
                        >
                            {showInactive ? 'Hide inactive' : `Show inactive (${inactiveCount})`}
                        </Button>
                    )}
                    <Button variant="light" size="sm" icon="ti ti-arrows-maximize" onClick={() => setCollapsed([])}>
                        Expand all
                    </Button>
                    <Button
                        variant="light"
                        size="sm"
                        icon="ti ti-arrows-minimize"
                        onClick={() => setCollapsed(departments.map((department) => department.id))}
                    >
                        Collapse all
                    </Button>
                </div>
            </div>

            <div className="dpt-frame">
                <table className="dpt-table">
                    <thead>
                        <tr>
                            <th>
                                <span className="dpt-th-select">
                                    {actions.editable && (
                                        <SelectAllCheckbox ids={visibleIds} selected={selected} onToggleAll={onToggleAll} />
                                    )}
                                    Department
                                </span>
                            </th>
                            <th>Code</th>
                            <th className="text-end">Sub-departments</th>
                            <th className="text-end">Doctors</th>
                            <th className="text-end">Staff</th>
                            <th>Status</th>
                            <th aria-label="Actions" />
                        </tr>
                    </thead>

                    <tbody>
                        {rows.map(({ department, children }) => {
                            const total = department.children?.length ?? 0;
                            const open = needle !== '' || !collapsed.includes(department.id);

                            return (
                                <Fragment key={department.id}>
                                    <tr className={`dpt-parent${department.is_active ? '' : ' is-inactive'}`} style={tone(department.name)}>
                                        <td>
                                            <div className="dpt-cell">
                                                {actions.editable && (
                                                    <input
                                                        type="checkbox"
                                                        className="dpt-check"
                                                        checked={selected.has(department.id)}
                                                        onChange={() => onToggle(department.id)}
                                                        aria-label={`Select ${department.name}`}
                                                    />
                                                )}

                                                {total > 0 ? (
                                                    <button
                                                        type="button"
                                                        className="dpt-caret"
                                                        aria-expanded={open}
                                                        aria-label={`${open ? 'Collapse' : 'Expand'} ${department.name}`}
                                                        onClick={() =>
                                                            setCollapsed((was) =>
                                                                open
                                                                    ? [...was, department.id]
                                                                    : was.filter((id) => id !== department.id),
                                                            )
                                                        }
                                                    >
                                                        <i className={open ? 'ti ti-chevron-down' : 'ti ti-chevron-right'} aria-hidden="true" />
                                                    </button>
                                                ) : (
                                                    <span className="dpt-caret-space" />
                                                )}

                                                <span className="dpt-tile" aria-hidden="true">
                                                    {initials(department.name)}
                                                </span>

                                                <span className="dpt-label">
                                                    <b>{department.name}</b>
                                                    <small>{department.description ?? 'Department'}</small>
                                                    {/* The columns don't fit on a phone, so the counts come here instead. */}
                                                    <span className="dpt-mini">
                                                        {plural(department.doctors_count ?? 0, 'doctor')} ·{' '}
                                                        {department.staff_count ?? 0} staff · {plural(total, 'sub')}
                                                    </span>
                                                </span>
                                            </div>
                                        </td>
                                        <td>{department.code ? <span className="dpt-code">{department.code}</span> : '—'}</td>
                                        <td className="text-end dpt-num">{total}</td>
                                        <td className="text-end dpt-num">{department.doctors_count ?? 0}</td>
                                        <td className="text-end dpt-num">{department.staff_count ?? 0}</td>
                                        <td>
                                            <StatusBadge active={department.is_active} />
                                        </td>
                                        <td className="text-end">
                                            <RowActions department={department} actions={actions} />
                                        </td>
                                    </tr>

                                    {open &&
                                        children.map((child) => (
                                            <tr
                                                key={child.id}
                                                className={`dpt-child${child.is_active ? '' : ' is-inactive'}`}
                                                style={tone(department.name)}
                                            >
                                                <td>
                                                    <div className="dpt-cell">
                                                        {actions.editable && (
                                                            <input
                                                                type="checkbox"
                                                                className="dpt-check"
                                                                checked={selected.has(child.id)}
                                                                onChange={() => onToggle(child.id)}
                                                                aria-label={`Select ${child.name}`}
                                                            />
                                                        )}
                                                        <span className="dpt-caret-space" />
                                                        <span className="dpt-elbow" aria-hidden="true" />
                                                        <span className="dpt-dot" aria-hidden="true" />
                                                        <span className="dpt-label">
                                                            <b>{child.name}</b>
                                                            <small>{child.description ?? `Under ${department.name}`}</small>
                                                            <span className="dpt-mini">
                                                                {plural(child.doctors_count ?? 0, 'doctor')} ·{' '}
                                                                {child.staff_count ?? 0} staff
                                                            </span>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>{child.code ? <span className="dpt-code">{child.code}</span> : '—'}</td>
                                                <td className="text-end dpt-num">—</td>
                                                <td className="text-end dpt-num">{child.doctors_count ?? 0}</td>
                                                <td className="text-end dpt-num">{child.staff_count ?? 0}</td>
                                                <td>
                                                    <StatusBadge active={child.is_active} />
                                                </td>
                                                <td className="text-end">
                                                    <RowActions department={child} child actions={actions} />
                                                </td>
                                            </tr>
                                        ))}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </table>

                {rows.length === 0 && (
                    <p className="dpt-none">
                        {needle ? (
                            <>Nothing matches &ldquo;{query}&rdquo;.</>
                        ) : (
                            <>Everything here is deactivated — turn on &ldquo;Show inactive&rdquo; above to see it.</>
                        )}
                    </p>
                )}
            </div>
        </>
    );
}

/** One count totalled across the whole tree, departments and sub-departments alike. */
function everyone(departments: Department[], key: 'doctors_count' | 'staff_count'): number {
    return departments.reduce(
        (sum, department) =>
            sum +
            (department[key] ?? 0) +
            (department.children ?? []).reduce((inner, child) => inner + (child[key] ?? 0), 0),
        0,
    );
}

/**
 * One box on the chart. The whole card opens the department for editing —
 * there are no buttons on it, so the chart stays a picture of the
 * organisation rather than another list of controls.
 */
function ChartCard({
    department,
    subs,
    child,
    actions,
}: {
    department: Department;
    /** How many sub-departments hang off it. Absent on a sub-department itself. */
    subs?: number;
    child?: boolean;
    actions: Actions;
}) {
    const counts: [string, number, string][] = [
        ['ti ti-stethoscope', department.doctors_count ?? 0, 'doctors'],
        ['ti ti-users', department.staff_count ?? 0, 'staff'],
    ];

    if (!child) {
        counts.push(['ti ti-subtask', subs ?? 0, 'sub-departments']);
    }

    const inside = (
        <>
            <span className={child ? 'dc-pip' : 'dpt-tile'} aria-hidden="true">
                {child ? null : initials(department.name)}
            </span>

            <span className="dc-card-name">
                <b>{department.name}</b>
                <small>
                    {department.code ? `${department.code} · ` : ''}
                    {department.is_active ? (child ? 'Sub-department' : 'Department') : 'Inactive'}
                </small>
            </span>

            <span className="dc-card-counts">
                {counts.map(([icon, value, label]) => (
                    <span key={label} title={`${value} ${label}`}>
                        <i className={icon} aria-hidden="true" />
                        {value}
                    </span>
                ))}
            </span>
        </>
    );

    const className = [
        'dc-card',
        child ? 'is-sub' : '',
        department.is_active ? '' : 'is-inactive',
    ]
        .filter(Boolean)
        .join(' ');

    if (!actions.editable) {
        return <div className={className}>{inside}</div>;
    }

    return (
        <button type="button" className={className} onClick={() => actions.edit(department)}>
            {inside}
            <i className="ti ti-pencil dc-card-hint" aria-hidden="true" />
            <span className="visually-hidden">Edit {department.name}</span>
        </button>
    );
}

/**
 * The chart view: the organisation at the top, every department hanging off
 * its trunk, and each one's sub-departments branching off it in turn.
 */
function DepartmentChart({
    departments,
    organization,
    actions,
}: {
    departments: Department[];
    organization: string;
    actions: Actions;
}) {
    const doctors = everyone(departments, 'doctors_count');
    const staff = everyone(departments, 'staff_count');

    return (
        <div className="dc">
            <div className="dc-root">
                <span className="dc-root-icon" aria-hidden="true">
                    <i className="ti ti-building-hospital" />
                </span>

                <span className="dc-root-name">
                    <b>{organization}</b>
                    <small>
                        {plural(departments.length, 'department')} · {plural(doctors, 'doctor')} ·{' '}
                        {staff} staff
                    </small>
                </span>
            </div>

            <ul className="dc-tree">
                {departments.map((department) => {
                    const children = department.children ?? [];

                    return (
                        <li className="dc-node" key={department.id} style={tone(department.name)}>
                            <ChartCard department={department} subs={children.length} actions={actions} />

                            {children.length > 0 && (
                                <ul className="dc-subs">
                                    {children.map((child) => (
                                        <li key={child.id}>
                                            <ChartCard department={child} child actions={actions} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * Departments and their sub-departments — on the Departments page and inside
 * organisation setup. Two views of the same tree: a list to work in, and a
 * chart to see the shape of the organisation at a glance.
 *
 * Deactivating is the everyday tool: the department stops being offered and
 * every record that names it stays. Removing needs a reason and is refused
 * while anything is still in it.
 */
export function DepartmentManager() {
    const { data: tree, isLoading, isError, refetch } = departmentsHooks.useList();
    const update = departmentsHooks.useUpdate();
    const remove = useRemoveDepartment();
    const { can, organization } = useTenantAuth();

    const [view, setView] = useState<View>('list');
    const [form, setForm] = useState<FormState>(null);
    const [removing, setRemoving] = useState<Department | null>(null);
    const [removeError, setRemoveError] = useState<string | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [bulkRemoveOpen, setBulkRemoveOpen] = useState(false);
    const [bulkRemoveError, setBulkRemoveError] = useState<string | null>(null);

    const departments = tree ?? [];
    const everything = departments.flatMap((department) => [department, ...(department.children ?? [])]);
    const selectedDepartments = everything.filter((department) => selected.has(department.id));

    const totals = {
        departments: departments.length,
        subs: everything.length - departments.length,
        doctors: everything.reduce((sum, department) => sum + (department.doctors_count ?? 0), 0),
        staff: everything.reduce((sum, department) => sum + (department.staff_count ?? 0), 0),
    };

    const actions: Actions = {
        editable: can('settings.manage'),
        edit: (department) => setForm({ department }),
        addChild: (department) => setForm({ parentId: department.id }),
        toggleActive: (department) =>
            update.mutate({
                id: department.id,
                payload: {
                    name: department.name,
                    code: department.code,
                    description: department.description,
                    parent_id: department.parent_id,
                    is_active: !department.is_active,
                },
            }),
        remove: (department) => {
            setRemoveError(null);
            setRemoving(department);
        },
    };

    async function confirmRemove(reason: string) {
        if (!removing) return;

        setRemoveError(null);

        try {
            await remove.mutateAsync({ id: removing.id, reason });
            setRemoving(null);
        } catch (error) {
            setRemoveError(resolveErrorMessage(error));
        }
    }

    function toggleSelected(id: number) {
        setSelected((was) => {
            const next = new Set(was);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }

    function toggleAllSelected(ids: number[]) {
        setSelected((was) => {
            const allSelected = ids.length > 0 && ids.every((id) => was.has(id));
            const next = new Set(was);

            ids.forEach((id) => (allSelected ? next.delete(id) : next.add(id)));

            return next;
        });
    }

    /**
     * Deactivating already-off departments would be a no-op the count still
     * has to explain, so only the ones actually on are sent.
     */
    const bulkDeactivate = useMutation({
        mutationFn: async (targets: Department[]) => {
            const results = await Promise.allSettled(
                targets.map((department) =>
                    departmentsApi.update(department.id, {
                        name: department.name,
                        code: department.code,
                        description: department.description,
                        parent_id: department.parent_id,
                        is_active: false,
                    }),
                ),
            );

            return { total: targets.length, failed: results.filter((r) => r.status === 'rejected').length };
        },
        onSuccess: ({ total, failed }) => {
            const done = total - failed;

            notify.success(
                failed > 0
                    ? `${done} of ${total} deactivated — ${failed} failed.`
                    : `${plural(done, 'department')} deactivated`,
            );
            setSelected(new Set());
        },
    });

    /**
     * Loops the same single-reason delete the row action uses. A department
     * still holding doctors, staff or sub-departments is refused by the
     * server (409) — that failure is expected here, not exceptional, so the
     * count is reported rather than thrown.
     */
    const bulkRemove = useMutation({
        mutationFn: async ({ targets, reason }: { targets: Department[]; reason: string }) => {
            const results = await Promise.allSettled(
                targets.map((department) =>
                    http.delete(`/tenant/departments/${department.id}`, { data: { reason }, silent: true }),
                ),
            );

            return { total: targets.length, failed: results.filter((r) => r.status === 'rejected').length };
        },
        onSuccess: ({ total, failed }) => {
            const done = total - failed;

            if (failed > 0) {
                setBulkRemoveError(
                    `${done} of ${total} removed. ${failed} still have doctors, staff or sub-departments in them — deactivate those instead.`,
                );

                return;
            }

            notify.success(`${plural(done, 'department')} removed`);
            setBulkRemoveOpen(false);
            setSelected(new Set());
        },
    });

    async function confirmBulkRemove(reason: string) {
        setBulkRemoveError(null);
        await bulkRemove.mutateAsync({ targets: selectedDepartments, reason });
    }

    if (isLoading) {
        return <LoadingBlock label="Loading departments…" />;
    }

    if (isError) {
        return <ErrorState onRetry={() => refetch()} />;
    }

    return (
        <div className="dp">
            <div className="dpt-stats">
                {(
                    [
                        ['ti ti-layout-grid', totals.departments, 'Departments'],
                        ['ti ti-subtask', totals.subs, 'Sub-departments'],
                        ['ti ti-stethoscope', totals.doctors, 'Doctors'],
                        ['ti ti-users', totals.staff, 'Staff'],
                    ] as const
                ).map(([icon, value, label]) => (
                    <div className="dpt-stat" key={label}>
                        <i className={icon} aria-hidden="true" />
                        <span>
                            <b>{value}</b>
                            <small>{label}</small>
                        </span>
                    </div>
                ))}
            </div>

            <div className="dpt-top">
                <Tabs tabs={VIEWS} value={view} onChange={setView} label="Department views" />

                {actions.editable && (
                    <Button icon="ti ti-plus" onClick={() => setForm({ parentId: null })}>
                        Add department
                    </Button>
                )}
            </div>

            {actions.editable && selected.size > 0 && (
                <div className="dpt-bulkbar" role="toolbar" aria-label="Bulk actions">
                    <span className="dpt-bulk-count">{selected.size} selected</span>

                    <div className="dpt-bulk-acts">
                        <Button variant="light" size="sm" onClick={() => setSelected(new Set())}>
                            Clear
                        </Button>
                        <Button
                            variant="light"
                            size="sm"
                            icon="ti ti-eye-off"
                            disabled={!selectedDepartments.some((department) => department.is_active)}
                            loading={bulkDeactivate.isPending}
                            onClick={() =>
                                bulkDeactivate.mutate(
                                    selectedDepartments.filter((department) => department.is_active),
                                )
                            }
                        >
                            Deactivate
                        </Button>
                        <Button
                            variant="light"
                            size="sm"
                            icon="ti ti-trash"
                            onClick={() => {
                                setBulkRemoveError(null);
                                setBulkRemoveOpen(true);
                            }}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            )}

            {departments.length === 0 ? (
                <EmptyState
                    icon="ti ti-layout-grid"
                    title="No departments yet"
                    description="Add the departments your doctors and staff belong to, then any sub-departments under them."
                />
            ) : view === 'list' ? (
                <DepartmentList
                    departments={departments}
                    actions={actions}
                    selected={selected}
                    onToggle={toggleSelected}
                    onToggleAll={toggleAllSelected}
                />
            ) : (
                <DepartmentChart
                    departments={departments}
                    organization={organization?.name ?? 'Your organisation'}
                    actions={actions}
                />
            )}

            <DepartmentForm state={form} tree={departments} onClose={() => setForm(null)} />

            <ReasonDialog
                open={removing !== null}
                title={`Remove ${removing?.name ?? ''}`}
                subtitle="Only an empty department can be removed. One still in use can be deactivated instead."
                submitLabel="Remove"
                danger
                submitting={remove.isPending}
                error={removeError}
                onClose={() => setRemoving(null)}
                onSubmit={(reason) => void confirmRemove(reason)}
            />

            <ReasonDialog
                open={bulkRemoveOpen}
                title={`Remove ${selected.size} department${selected.size === 1 ? '' : 's'}?`}
                subtitle="Only empty departments can be removed. Ones still in use are skipped and can be deactivated instead."
                submitLabel="Remove"
                danger
                submitting={bulkRemove.isPending}
                error={bulkRemoveError}
                onClose={() => setBulkRemoveOpen(false)}
                onSubmit={(reason) => void confirmBulkRemove(reason)}
            />
        </div>
    );
}
