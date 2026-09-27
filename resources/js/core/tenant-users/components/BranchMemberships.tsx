import { useState, type FormEvent } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SearchableSelect, type SelectOption } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { locationsHooks } from '@/core/locations/api';
import { rolesHooks, useSaveUserBranches } from '@/core/roles/api';

interface Row {
    location_id: number;
    role_id: number | null;
    is_primary: boolean;
}

/** Which modal is open, and — for editing — which branch it is about. */
type ModalState = { mode: 'add' } | { mode: 'edit'; locationId: number } | null;

/**
 * What somebody does at each branch they work at.
 *
 * Deliberately not the same screen as the role they hold across the whole
 * organization (see the Organization Role card on the person's own form) —
 * a role there and a responsibility here answer two different questions,
 * and a person can have either without the other. A card per branch, so
 * "receptionist at Lucknow, manager at Delhi" reads as two separate facts
 * rather than two rows of one table.
 *
 * Every add, edit and remove sends the WHOLE set in one write — the same
 * `PUT /branches` the row-table version of this screen always used — so a
 * card here is a view onto one row of that set, not a resource with an id
 * of its own to save piecemeal.
 *
 * Saved as its own permission: `people.assign_branch` is organization-scoped,
 * so a branch manager who may edit this person may not move them.
 */
export function BranchMemberships({
    userId,
    initial,
    canAssign,
}: {
    userId: number;
    initial: { location_id: number; role_id: number | null; is_primary: boolean }[];
    /** Whether this person may change it at all — the panel is read-only otherwise. */
    canAssign: boolean;
}) {
    const { data: branches } = locationsHooks.useList({ all: 1 });
    const { data: roles } = rolesHooks.useList();
    const save = useSaveUserBranches(userId);
    const confirm = useConfirm();

    const rows: Row[] = initial;

    const [modal, setModal] = useState<ModalState>(null);
    const [modalBranch, setModalBranch] = useState<number | ''>('');
    const [modalRole, setModalRole] = useState<number | ''>('');
    const [modalError, setModalError] = useState<string | null>(null);
    const [modalBusy, setModalBusy] = useState(false);
    const [rowBusy, setRowBusy] = useState<number | null>(null);

    const branchName = (locationId: number) =>
        (branches ?? []).find((branch) => branch.id === locationId)?.name ?? 'Unknown branch';

    const roleName = (roleId: number | null) =>
        roleId ? (roles ?? []).find((role) => role.id === roleId)?.name ?? null : null;

    const unused = (branches ?? []).filter(
        (branch) => !rows.some((row) => row.location_id === branch.id),
    );

    /*
     * Only branch-scoped roles apply here — an organization role sits on the
     * person, not the membership. Ownership decides how each one reads:
     * written by the organization (`location_id` null) it is offered at
     * every branch as "Shared role"; written by one branch it is offered
     * only there, labelled with that branch's own name. `SearchableSelect`
     * has no notion of a grouped list, so the hint line is what tells the
     * two apart — shared roles sort first so the common case is on top.
     */
    function roleOptions(locationId: number | ''): SelectOption[] {
        if (!locationId) return [];

        return (roles ?? [])
            .filter((role) => role.scope === 'branch' && (role.location_id === null || role.location_id === locationId))
            .sort(
                (a, b) =>
                    Number(a.location_id !== null) - Number(b.location_id !== null) ||
                    a.name.localeCompare(b.name),
            )
            .map((role) => ({
                value: String(role.id),
                label: role.name,
                hint: role.location_id === null ? 'Shared role' : `${role.location ?? 'This branch'} role`,
            }));
    }

    function openAdd() {
        setModalBranch('');
        setModalRole('');
        setModalError(null);
        setModal({ mode: 'add' });
    }

    function openEdit(row: Row) {
        setModalBranch(row.location_id);
        setModalRole(row.role_id ?? '');
        setModalError(null);
        setModal({ mode: 'edit', locationId: row.location_id });
    }

    function closeModal() {
        if (modalBusy) return;
        setModal(null);
    }

    /** Read off a thrown validation error what to show beside the field that caused it. */
    function firstValidationMessage(error: unknown): string {
        const validation = getValidationErrors(error);

        if (validation) {
            const first = Object.values(validation)[0]?.[0];

            if (first) return first;
        }

        return resolveErrorMessage(error);
    }

    async function submitModal(event: FormEvent) {
        event.preventDefault();

        if (!modal) return;

        if (!modalBranch) {
            setModalError('Choose a branch.');

            return;
        }

        const next: Row[] =
            modal.mode === 'edit'
                ? rows.map((row) =>
                      row.location_id === modal.locationId
                          ? { ...row, role_id: modalRole ? Number(modalRole) : null }
                          : row,
                  )
                : [
                      ...rows,
                      {
                          location_id: Number(modalBranch),
                          role_id: modalRole ? Number(modalRole) : null,
                          // The first branch somebody is given is where their workspace opens.
                          is_primary: rows.length === 0,
                      },
                  ];

        setModalError(null);
        setModalBusy(true);

        try {
            await save.mutateAsync(next);
            setModal(null);
        } catch (error) {
            setModalError(firstValidationMessage(error));
        } finally {
            setModalBusy(false);
        }
    }

    async function removeBranch(row: Row) {
        const ok = await confirm({
            title: `Remove ${branchName(row.location_id)}?`,
            message:
                'They lose access to this branch and whatever responsibility they held there. This does not touch their organization role.',
            confirmLabel: 'Remove',
            danger: true,
        });

        if (!ok) return;

        setRowBusy(row.location_id);

        try {
            await save.mutateAsync(rows.filter((r) => r.location_id !== row.location_id));
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        } finally {
            setRowBusy(null);
        }
    }

    async function makePrimary(row: Row) {
        if (row.is_primary) return;

        setRowBusy(row.location_id);

        try {
            await save.mutateAsync(
                rows.map((r) => ({ ...r, is_primary: r.location_id === row.location_id })),
            );
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        } finally {
            setRowBusy(null);
        }
    }

    const editingRow = modal?.mode === 'edit' ? rows.find((row) => row.location_id === modal.locationId) : undefined;
    const modalTitle = modal?.mode === 'edit' ? `Edit role at ${branchName(modal.locationId)}` : 'Add Branch Access';

    return (
        <>
            <Card
                title="Branch Access"
                icon="ti ti-map-pin"
                description="What they do at each branch they work at."
                actions={
                    canAssign &&
                    unused.length > 0 && (
                        <Button size="sm" icon="ti ti-plus" onClick={openAdd}>
                            Add Branch
                        </Button>
                    )
                }
            >
                {rows.length === 0 ? (
                    <p className="db-quiet">
                        <i className="ti ti-building" />
                        Not at any branch — they work for the organization itself, and their
                        organization role (if they have one) applies across the network.
                    </p>
                ) : (
                    <ul className="ba-list">
                        {rows.map((row) => (
                            <li className="ba-card" key={row.location_id}>
                                <span className="ba-card-icon" aria-hidden="true">
                                    <i className="ti ti-building-store" />
                                </span>

                                <span className="ba-card-body">
                                    <b>
                                        {branchName(row.location_id)}
                                        {row.is_primary && (
                                            <span className="ba-primary">
                                                <i className="ti ti-star-filled" aria-hidden="true" />
                                                Primary
                                            </span>
                                        )}
                                    </b>
                                    <span className="ba-role">
                                        {roleName(row.role_id) ?? 'No responsibility set yet'}
                                    </span>
                                </span>

                                {canAssign && (
                                    <span className="ba-card-acts">
                                        {!row.is_primary && (
                                            <button
                                                type="button"
                                                className="ba-mini"
                                                disabled={rowBusy === row.location_id}
                                                onClick={() => void makePrimary(row)}
                                                title="Make this their default branch"
                                            >
                                                <i className="ti ti-star" aria-hidden="true" />
                                                Make primary
                                            </button>
                                        )}

                                        <Button variant="light" size="sm" onClick={() => openEdit(row)}>
                                            Edit Role
                                        </Button>

                                        <Button
                                            variant="light"
                                            size="sm"
                                            loading={rowBusy === row.location_id}
                                            onClick={() => void removeBranch(row)}
                                        >
                                            Remove
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canAssign && rows.length > 0 && unused.length === 0 && (
                    <p className="form-hint mb-0 mt-2">They already have access at every branch.</p>
                )}
            </Card>

            <FormModal
                open={modal !== null}
                onClose={closeModal}
                title={modalTitle}
                size="sm"
                icon={<i className="ti ti-map-pin" />}
                onSubmit={submitModal}
                submitting={modalBusy}
                submitLabel={modal?.mode === 'edit' ? 'Save' : 'Add Branch'}
            >
                {modalError && <p className="invalid-feedback d-block mb-3">{modalError}</p>}

                <div className="form-group">
                    <label className="form-label" htmlFor="ba-branch">
                        Branch
                    </label>

                    {modal?.mode === 'edit' ? (
                        // The branch itself is not editable here — moving somebody
                        // is Remove, then Add Branch again, so it is never
                        // ambiguous which membership just changed.
                        <input
                            id="ba-branch"
                            className="form-control"
                            value={editingRow ? branchName(editingRow.location_id) : ''}
                            disabled
                            readOnly
                        />
                    ) : (
                        <SearchableSelect
                            id="ba-branch"
                            value={modalBranch === '' ? '' : String(modalBranch)}
                            onChange={(next) => {
                                setModalBranch(next ? Number(next) : '');
                                // A role valid at the old branch may not be at the new one.
                                setModalRole('');
                            }}
                            placeholder="Select branch…"
                            options={unused.map((branch) => ({
                                value: String(branch.id),
                                label: branch.name,
                            }))}
                        />
                    )}
                </div>

                <div className="form-group mb-0">
                    <label className="form-label" htmlFor="ba-role">
                        Responsibility
                    </label>

                    <SearchableSelect
                        id="ba-role"
                        value={modalRole === '' ? '' : String(modalRole)}
                        onChange={(next) => setModalRole(next ? Number(next) : '')}
                        disabled={!modalBranch}
                        clearable
                        placeholder={modalBranch ? 'Nothing yet' : 'Choose a branch first'}
                        options={roleOptions(modalBranch)}
                    />

                    <p className="form-hint">Choose what this person does at this branch.</p>
                </div>
            </FormModal>
        </>
    );
}
