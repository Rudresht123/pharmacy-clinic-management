import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { locationsHooks } from '@/core/locations/api';
import { rolesHooks, useSaveUserBranches } from '@/core/roles/api';

interface Row {
    location_id: number;
    role_id: number | null;
    is_primary: boolean;
}

/**
 * Where somebody works, and what they hold at each place.
 *
 * The single Branch and Role selects this replaces could describe one
 * placement. A doctor sitting at three clinics could not be entered at all,
 * a transfer overwrote where somebody had always worked, and "receptionist at
 * Lucknow, manager at Delhi" had nowhere to live.
 *
 * One row is still the common case and looks like one row, not a table with a
 * single entry in it.
 *
 * Saved separately from the rest of the form, because it is a different
 * permission: `people.assign_branch` is organization-scoped, so a branch
 * manager who may edit this person may not move them.
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

    const [rows, setRows] = useState<Row[]>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        setRows(initial);
        setDirty(false);
    }, [initial]);

    /* Only branch-scoped roles can be held at a branch — an organization role
       applies everywhere and lives on the person, not the membership. */
    const branchRoles = (roles ?? []).filter((role) => role.scope !== 'organization');

    const unused = (branches ?? []).filter(
        (branch) => !rows.some((row) => row.location_id === branch.id),
    );

    function patch(index: number, changes: Partial<Row>) {
        setDirty(true);
        setRows((current) =>
            current.map((row, position) => (position === index ? { ...row, ...changes } : row)),
        );
    }

    function add() {
        if (unused.length === 0) {
            return;
        }

        setDirty(true);
        setRows((current) => [
            ...current,
            {
                location_id: unused[0].id,
                role_id: null,
                // The first one added is where their workspace opens.
                is_primary: current.length === 0,
            },
        ]);
    }

    function remove(index: number) {
        setDirty(true);
        setRows((current) => current.filter((_, position) => position !== index));
    }

    /** Exactly one, so the server's rule and the screen cannot disagree. */
    function makePrimary(index: number) {
        setDirty(true);
        setRows((current) =>
            current.map((row, position) => ({ ...row, is_primary: position === index })),
        );
    }

    async function onSave() {
        setErrors({});

        try {
            await save.mutateAsync(rows);
            setDirty(false);
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(validation).map(([key, value]) => [key, value[0]]),
                    ),
                );

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    return (
        <Card
            title="Branches"
            icon="ti ti-map-pin"
            description="Where they work, and what they may do at each place."
            actions={
                canAssign && (
                    <Button
                        variant={dirty ? undefined : 'light'}
                        icon="ti ti-device-floppy"
                        loading={save.isPending}
                        disabled={!dirty}
                        onClick={onSave}
                    >
                        {dirty ? 'Save branches' : 'Saved'}
                    </Button>
                )
            }
        >
            {rows.length === 0 ? (
                <p className="db-quiet">
                    <i className="ti ti-building" />
                    Not at any branch — they work for the organization itself, and their role
                    applies across the network.
                </p>
            ) : (
                <ul className="bm-rows">
                    {rows.map((row, index) => (
                        <li key={`${row.location_id}-${index}`}>
                            <label className="bm-cell">
                                <span>Branch</span>
                                <SearchableSelect
                                    invalid={Boolean(errors[`branches.${index}.location_id`])}
                                    disabled={!canAssign}
                                    ariaLabel="Branch"
                                    value={String(row.location_id)}
                                    onChange={(next) =>
                                        patch(index, { location_id: Number(next) })
                                    }
                                    /*
                                        Branches already spoken for are left
                                        out rather than shown greyed: a row
                                        nobody can pick is noise in a list you
                                        are searching, and somebody may hold
                                        one membership per branch.
                                    */
                                    options={(branches ?? [])
                                        .filter(
                                            (branch) =>
                                                branch.id === row.location_id ||
                                                !rows.some(
                                                    (other, position) =>
                                                        position !== index &&
                                                        other.location_id === branch.id,
                                                ),
                                        )
                                        .map((branch) => ({
                                            value: String(branch.id),
                                            label: branch.name,
                                        }))}
                                />
                            </label>

                            <label className="bm-cell">
                                <span>Role here</span>
                                <SearchableSelect
                                    invalid={Boolean(errors[`branches.${index}.role_id`])}
                                    disabled={!canAssign}
                                    ariaLabel="Role at this branch"
                                    value={row.role_id == null ? '' : String(row.role_id)}
                                    placeholder="Nothing yet"
                                    clearable
                                    onChange={(next) =>
                                        patch(index, { role_id: next ? Number(next) : null })
                                    }
                                    options={branchRoles.map((role) => ({
                                        value: String(role.id),
                                        label: role.name,
                                    }))}
                                />
                            </label>

                            <button
                                type="button"
                                className={`bm-primary${row.is_primary ? ' is-on' : ''}`}
                                title="The branch their workspace opens on"
                                disabled={!canAssign}
                                onClick={() => makePrimary(index)}
                            >
                                <i
                                    className={
                                        row.is_primary ? 'ti ti-star-filled' : 'ti ti-star'
                                    }
                                />
                                {row.is_primary ? 'Opens here' : 'Set as default'}
                            </button>

                            {canAssign && (
                                <button
                                    type="button"
                                    className="bm-remove"
                                    aria-label="Remove this branch"
                                    onClick={() => remove(index)}
                                >
                                    <i className="ti ti-x" />
                                </button>
                            )}

                            {(errors[`branches.${index}.location_id`] ||
                                errors[`branches.${index}.role_id`] ||
                                errors[`branches.${index}.is_primary`]) && (
                                <em className="bm-error">
                                    {errors[`branches.${index}.location_id`] ??
                                        errors[`branches.${index}.role_id`] ??
                                        errors[`branches.${index}.is_primary`]}
                                </em>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canAssign && (
                <button
                    type="button"
                    className="bm-add"
                    disabled={unused.length === 0}
                    onClick={add}
                >
                    <i className="ti ti-plus" />
                    {unused.length === 0 ? 'They are at every branch' : 'Add a branch'}
                </button>
            )}
        </Card>
    );
}
