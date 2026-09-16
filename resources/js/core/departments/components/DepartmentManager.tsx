import { Fragment, useEffect, useState, type CSSProperties } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { FormModal } from '@/shared/components/ui/FormModal';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { EmptyState, ErrorState, LoadingBlock, StatusBadge } from '@/shared/components/ui/Feedback';
import { FormError, TextareaField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { departmentsHooks, useRemoveDepartment } from '../api';
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
function DepartmentList({ departments, actions }: { departments: Department[]; actions: Actions }) {
    const [query, setQuery] = useState('');
    const [collapsed, setCollapsed] = useState<number[]>([]);

    const needle = query.trim().toLowerCase();
    const hit = (department: Department) =>
        department.name.toLowerCase().includes(needle) || (department.code ?? '').toLowerCase().includes(needle);

    const rows = departments
        .map((department) => {
            const children = department.children ?? [];

            if (!needle || hit(department)) return { department, children };

            const found = children.filter(hit);

            return found.length ? { department, children: found } : null;
        })
        .filter((row): row is { department: Department; children: Department[] } => row !== null);

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
                            <th>Department</th>
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
                                                        <span className="dpt-caret-space" />
                                                        <span className="dpt-elbow" aria-hidden="true" />
                                                        <span className="dpt-dot" aria-hidden="true" />
                                                        <span className="dpt-label">
                                                            <b>{child.name}</b>
                                                            <small>{child.description ?? `Under ${department.name}`}</small>
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

                {rows.length === 0 && <p className="dpt-none">Nothing matches &ldquo;{query}&rdquo;.</p>}
            </div>
        </>
    );
}

/**
 * The chart view: the organisation at the top, a card per department below
 * it, and each department's sub-departments stacked beneath its card.
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
    return (
        <div className="dc-scroll">
            <div className="dc">
                <div className="dc-root">
                    <span className="dc-root-icon" aria-hidden="true">
                        <i className="ti ti-building-hospital" />
                    </span>
                    <span>
                        <b>{organization}</b>
                        <small>{plural(departments.length, 'department')}</small>
                    </span>
                </div>

                <div className="dc-row">
                    {departments.map((department) => {
                        const children = department.children ?? [];

                        return (
                            <div className="dc-branch" key={department.id} style={tone(department.name)}>
                                <div className={`dc-card${department.is_active ? '' : ' is-inactive'}`}>
                                    <div className="dc-card-head">
                                        <span className="dpt-tile" aria-hidden="true">
                                            {initials(department.name)}
                                        </span>
                                        <span className="dc-card-name">
                                            <b title={department.name}>{department.name}</b>
                                            <small>{department.is_active ? 'Department' : 'Inactive'}</small>
                                        </span>
                                    </div>

                                    <div className="dc-card-counts">
                                        <span>
                                            <i className="ti ti-stethoscope" aria-hidden="true" />
                                            {department.doctors_count ?? 0}
                                        </span>
                                        <span>
                                            <i className="ti ti-users" aria-hidden="true" />
                                            {department.staff_count ?? 0}
                                        </span>
                                        <span>
                                            <i className="ti ti-subtask" aria-hidden="true" />
                                            {children.length}
                                        </span>
                                    </div>

                                    <RowActions department={department} actions={actions} />
                                </div>

                                {children.length > 0 && (
                                    <div className="dc-subs">
                                        {children.map((child) => (
                                            <div className={`dc-sub${child.is_active ? '' : ' is-inactive'}`} key={child.id}>
                                                <span className="dpt-dot" aria-hidden="true" />
                                                <span className="dc-sub-name">
                                                    <b title={child.name}>{child.name}</b>
                                                    <small>
                                                        {child.doctors_count ?? 0} doctors · {child.staff_count ?? 0} staff
                                                    </small>
                                                </span>

                                                {actions.editable && (
                                                    <button
                                                        type="button"
                                                        className="dc-sub-edit"
                                                        title="Edit"
                                                        aria-label={`Edit ${child.name}`}
                                                        onClick={() => actions.edit(child)}
                                                    >
                                                        <i className="ti ti-pencil" aria-hidden="true" />
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
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

    const departments = tree ?? [];
    const everything = departments.flatMap((department) => [department, ...(department.children ?? [])]);

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

            {departments.length === 0 ? (
                <EmptyState
                    icon="ti ti-layout-grid"
                    title="No departments yet"
                    description="Add the departments your doctors and staff belong to, then any sub-departments under them."
                />
            ) : view === 'list' ? (
                <DepartmentList departments={departments} actions={actions} />
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
        </div>
    );
}
