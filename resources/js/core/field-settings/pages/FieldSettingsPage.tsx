import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { Tabs } from '@/shared/components/ui/Tabs';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { notify } from '@/shared/utils/notify';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import {
    useConfigurableEntities,
    useFieldSettings,
    useSaveFieldSettings,
    type ConfigurableEntity,
} from '../api';
import type { ConfigurableField, FieldDataType, FieldSettingInput } from '../types';

/** How each data type presents itself — icon in the list, shape in the preview. */
const TYPES: Record<string, { label: string; icon: string }> = {
    text: { label: 'Text', icon: 'ti ti-cursor-text' },
    textarea: { label: 'Long text', icon: 'ti ti-align-left' },
    number: { label: 'Number', icon: 'ti ti-hash' },
    date: { label: 'Date', icon: 'ti ti-calendar' },
    boolean: { label: 'Yes / No', icon: 'ti ti-toggle-left' },
    select: { label: 'Dropdown', icon: 'ti ti-chevron-down' },
    email: { label: 'Email', icon: 'ti ti-mail' },
};

/**
 * Each entity names its own groups; anything unrecognised falls back to the
 * raw key rather than being dropped, so a new group on the server shows up
 * here without a frontend change.
 */
const GROUP_TITLES: Record<string, string> = {
    identity: 'Identity',
    address: 'Address & Contact',
    compliance: 'Compliance',
    person: 'Person',
    access: 'Access',
    custom: 'Your Own Fields',
};

const GROUP_ORDER = ['identity', 'person', 'address', 'access', 'compliance', 'custom'];

interface Row extends FieldSettingInput {
    /** Carried through from the server so the row can render its own type. */
    group: string;
    locked: boolean;
    resolvedType: FieldDataType;
}

function toRow(field: ConfigurableField): Row {
    return {
        field_key: field.key,
        label: field.label,
        placeholder: field.placeholder ?? null,
        is_custom: field.is_custom,
        data_type: field.is_custom ? field.type : null,
        options: field.is_custom ? (field.options ?? null) : null,
        is_required: field.required,
        show_in_form: field.show_in_form,
        show_in_table: field.in_table,
        sort_order: field.sort_order,
        group: field.group,
        locked: field.locked,
        resolvedType: field.type,
    };
}

function newCustomRow(sortOrder: number): Row {
    return {
        field_key: '',
        label: '',
        placeholder: null,
        is_custom: true,
        data_type: 'text',
        options: null,
        is_required: false,
        show_in_form: true,
        show_in_table: false,
        sort_order: sortOrder,
        group: 'custom',
        locked: false,
        resolvedType: 'text',
    };
}

/** One of the three on/off decisions, as a chip rather than a bare checkbox. */
function Toggle({
    on,
    icon,
    label,
    disabled,
    onClick,
}: {
    on: boolean;
    icon: string;
    label: string;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={`lf-toggle${on ? ' is-on' : ''}`}
            disabled={disabled}
            onClick={onClick}
            aria-pressed={on}
            title={disabled ? `${label} is fixed by the system` : label}
        >
            <i className={icon} />
            {label}
        </button>
    );
}

export default function FieldSettingsPage() {
    const { entity: raw } = useParams<{ entity: ConfigurableEntity }>();
    const entity = (raw ?? 'location') as ConfigurableEntity;
    const navigate = useNavigate();
    const confirm = useConfirm();

    const { data: entities } = useConfigurableEntities();
    const { data, isLoading } = useFieldSettings(entity);
    const save = useSaveFieldSettings(entity);

    const [rows, setRows] = useState<Row[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [expanded, setExpanded] = useState<number | null>(null);
    const [dirty, setDirty] = useState(false);
    const [singular, setSingular] = useState('');
    const [plural, setPlural] = useState('');

    useEffect(() => {
        if (data) {
            setRows(data.fields.map(toRow));
            setSingular(data.singular);
            setPlural(data.label);
            setDirty(false);
        }
    }, [data]);

    // Every edit path runs through these three, so one flag covers them all.

    function patch(index: number, changes: Partial<Row>) {
        setDirty(true);

        setRows((current) =>
            current.map((row, position) => (position === index ? { ...row, ...changes } : row)),
        );
    }

    function move(index: number, by: number) {
        setDirty(true);

        const target = index + by;

        if (target < 0 || target >= rows.length) {
            return;
        }

        setRows((current) => {
            const next = [...current];
            [next[index], next[target]] = [next[target], next[index]];

            return next.map((row, position) => ({ ...row, sort_order: position }));
        });
    }

    async function removeRow(index: number) {
        const row = rows[index];

        const confirmed = await confirm({
            title: 'Remove this field?',
            message: `“${row.label || row.field_key || 'This field'}” will no longer be collected. Values already saved are kept.`,
            confirmLabel: 'Remove',
            danger: true,
        });

        if (confirmed) {
            setRows((current) => current.filter((_, position) => position !== index));
            setExpanded(null);
            setDirty(true);
        }
    }

    function addCustom() {
        setRows((current) => {
            const next = [...current, newCustomRow(current.length)];
            setExpanded(next.length - 1);
            setDirty(true);

            return next;
        });
    }

    async function onSave() {
        setErrors({});

        try {
            await save.mutateAsync({
                label: { singular: singular.trim(), plural: plural.trim() },
                fields: rows.map((row, position) => ({
                    field_key: row.field_key,
                    label: row.label,
                    placeholder: row.placeholder,
                    is_custom: row.is_custom,
                    data_type: row.data_type,
                    options: row.options,
                    is_required: row.is_required,
                    show_in_form: row.show_in_form,
                    show_in_table: row.show_in_table,
                    sort_order: position,
                })),
            });
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(validation).map(([key, messages]) => [key, messages[0]]),
                    ),
                );

                notify.error('Some fields need attention.');

                return;
            }

            notify.error(resolveErrorMessage(error));
        }
    }

    async function switchTo(next: ConfigurableEntity) {
        if (next === entity) {
            return;
        }

        /*
         * Each tab holds its own unsaved edits and switching remounts the
         * form, so moving away would drop them. Say so rather than losing
         * somebody's work silently.
         */
        if (dirty) {
            const confirmed = await confirm({
                title: 'Discard unsaved changes?',
                message: 'Your changes on this tab have not been saved yet.',
                confirmLabel: 'Discard',
                danger: true,
            });

            if (!confirmed) {
                return;
            }
        }

        navigate(`/settings/fields/${next}`);
    }

    const onForm = rows.filter((row) => row.show_in_form);
    const inTable = rows.filter((row) => row.show_in_table);

    // Rows keep their saved order; the groups themselves stay in a fixed one
    // so the screen does not reshuffle while someone is reordering fields.
    const grouped = useMemo(() => {
        const buckets = new Map<string, { row: Row; index: number }[]>();

        rows.forEach((row, index) => {
            const bucket = buckets.get(row.group) ?? [];
            bucket.push({ row, index });
            buckets.set(row.group, bucket);
        });

        return GROUP_ORDER.filter((group) => buckets.has(group)).map((group) => ({
            group,
            items: buckets.get(group)!,
        }));
    }, [rows]);

    if (isLoading) {
        return <LoadingBlock label="Loading field settings…" />;
    }

    return (
        <>
            <PageHeader
                title="Field Settings"
                subtitle={`Choose what your team is asked for on ${data?.label ?? 'each'} screens, and what the list shows.`}
                icon="ti ti-adjustments"
                tone="violet"
                crumbs={[{ label: 'Settings' }, { label: data?.label ?? 'Fields' }]}
                actions={
                    <Button icon="ti ti-device-floppy" loading={save.isPending} onClick={onSave}>
                        Save Changes
                    </Button>
                }
            />

            <Tabs<ConfigurableEntity>
                label="Settings sections"
                value={entity}
                onChange={switchTo}
                tabs={(entities ?? []).map((tab) => ({
                    value: tab.entity,
                    label: tab.label,
                    icon: 'ti ti-list-details',
                }))}
            />

            <div className="row g-3">
                <div className="col-lg-8">
                    <div className="lf-naming">
                        <div className="lf-naming-head">
                            <i className="ti ti-tag" />
                            <div>
                                <b>What do you call these?</b>
                                <span>
                                    The same record is a customer at a counter and a patient in a
                                    clinic. Only the wording changes.
                                </span>
                            </div>
                        </div>

                        <div className="lf-naming-fields">
                            <label>
                                One
                                <input
                                    className="form-control form-control-sm"
                                    value={singular}
                                    placeholder="Customer"
                                    onChange={(event) => {
                                        setSingular(event.target.value);
                                        setDirty(true);
                                    }}
                                />
                            </label>

                            <label>
                                Many
                                <input
                                    className="form-control form-control-sm"
                                    value={plural}
                                    placeholder="Customers"
                                    onChange={(event) => {
                                        setPlural(event.target.value);
                                        setDirty(true);
                                    }}
                                />
                            </label>
                        </div>
                    </div>

                    {grouped.map(({ group, items }) => (
                        <section className="lf-group" key={group}>
                            <header className="lf-group-head">
                                <h6>{GROUP_TITLES[group] ?? group}</h6>
                                <span>{items.length} fields</span>
                            </header>

                            <div className="lf-list">
                                {items.map(({ row, index }) => {
                                    const type = TYPES[row.resolvedType] ?? TYPES.text;
                                    const keyError = errors[`fields.${index}.field_key`];
                                    const optionsError = errors[`fields.${index}.options`];
                                    const typeError = errors[`fields.${index}.data_type`];
                                    const isOpen = expanded === index;

                                    return (
                                        <article
                                            className={`lf-row${row.show_in_form ? '' : ' is-off'}${isOpen ? ' is-open' : ''}`}
                                            key={`${row.field_key}-${index}`}
                                        >
                                            <div className="lf-order">
                                                <button
                                                    type="button"
                                                    onClick={() => move(index, -1)}
                                                    disabled={index === 0}
                                                    aria-label="Move up"
                                                >
                                                    <i className="ti ti-chevron-up" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => move(index, 1)}
                                                    disabled={index === rows.length - 1}
                                                    aria-label="Move down"
                                                >
                                                    <i className="ti ti-chevron-down" />
                                                </button>
                                            </div>

                                            <span className="lf-type" title={type.label}>
                                                <i className={type.icon} />
                                            </span>

                                            <div className="lf-main">
                                                <input
                                                    className="lf-label"
                                                    value={row.label ?? ''}
                                                    placeholder="Field label"
                                                    onChange={(event) =>
                                                        patch(index, { label: event.target.value })
                                                    }
                                                />

                                                <div className="lf-meta">
                                                    <code>{row.field_key || 'unnamed_field'}</code>

                                                    {row.locked && (
                                                        <span className="lf-lock">
                                                            <i className="ti ti-lock" /> system
                                                        </span>
                                                    )}

                                                    {row.is_custom && (
                                                        <button
                                                            type="button"
                                                            className="lf-edit"
                                                            onClick={() =>
                                                                setExpanded(isOpen ? null : index)
                                                            }
                                                        >
                                                            <i
                                                                className={
                                                                    isOpen
                                                                        ? 'ti ti-chevron-up'
                                                                        : 'ti ti-settings'
                                                                }
                                                            />
                                                            {isOpen ? 'Done' : 'Configure'}
                                                        </button>
                                                    )}
                                                </div>

                                                {(keyError || optionsError || typeError) && (
                                                    <div className="lf-error">
                                                        {keyError ?? typeError ?? optionsError}
                                                    </div>
                                                )}
                                            </div>

                                            <div className="lf-toggles">
                                                <Toggle
                                                    on={row.is_required}
                                                    icon="ti ti-asterisk"
                                                    label="Required"
                                                    disabled={row.locked}
                                                    onClick={() =>
                                                        patch(index, {
                                                            is_required: !row.is_required,
                                                        })
                                                    }
                                                />
                                                <Toggle
                                                    on={row.show_in_form}
                                                    icon="ti ti-forms"
                                                    label="On form"
                                                    disabled={row.locked}
                                                    onClick={() =>
                                                        patch(index, {
                                                            show_in_form: !row.show_in_form,
                                                        })
                                                    }
                                                />
                                                <Toggle
                                                    on={row.show_in_table}
                                                    icon="ti ti-table"
                                                    label="In table"
                                                    onClick={() =>
                                                        patch(index, {
                                                            show_in_table: !row.show_in_table,
                                                        })
                                                    }
                                                />
                                            </div>

                                            {row.is_custom && (
                                                <button
                                                    type="button"
                                                    className="lf-remove"
                                                    onClick={() => removeRow(index)}
                                                    title="Remove field"
                                                    aria-label="Remove field"
                                                >
                                                    <i className="ti ti-trash" />
                                                </button>
                                            )}

                                            {isOpen && (
                                                <div className="lf-editor">
                                                    <div className="lf-editor-grid">
                                                        <label>
                                                            Key
                                                            <input
                                                                className="form-control form-control-sm"
                                                                value={row.field_key}
                                                                placeholder="shelf_count"
                                                                onChange={(event) =>
                                                                    patch(index, {
                                                                        field_key:
                                                                            event.target.value,
                                                                    })
                                                                }
                                                            />
                                                            <small>
                                                                Lowercase, numbers and underscores.
                                                                Cannot be changed later without
                                                                losing saved values.
                                                            </small>
                                                        </label>

                                                        <label>
                                                            Type
                                                            <select
                                                                className="form-select form-select-sm"
                                                                value={row.data_type ?? 'text'}
                                                                onChange={(event) => {
                                                                    const next = event.target
                                                                        .value as FieldDataType;

                                                                    patch(index, {
                                                                        data_type: next,
                                                                        resolvedType: next,
                                                                        options:
                                                                            next === 'select'
                                                                                ? (row.options ??
                                                                                  [])
                                                                                : null,
                                                                    });
                                                                }}
                                                            >
                                                                {(data?.custom_types ?? []).map(
                                                                    (value) => (
                                                                        <option
                                                                            key={value}
                                                                            value={value}
                                                                        >
                                                                            {TYPES[value]?.label ??
                                                                                value}
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </label>

                                                        <label>
                                                            Placeholder
                                                            <input
                                                                className="form-control form-control-sm"
                                                                value={row.placeholder ?? ''}
                                                                placeholder="Shown when empty"
                                                                onChange={(event) =>
                                                                    patch(index, {
                                                                        placeholder:
                                                                            event.target.value ||
                                                                            null,
                                                                    })
                                                                }
                                                            />
                                                        </label>
                                                    </div>

                                                    {row.data_type === 'select' && (
                                                        <div className="lf-options">
                                                            <span className="lf-options-title">
                                                                Dropdown options
                                                            </span>

                                                            {(row.options ?? []).map(
                                                                (option, position) => (
                                                                    <div
                                                                        className="lf-option"
                                                                        key={position}
                                                                    >
                                                                        <input
                                                                            className="form-control form-control-sm"
                                                                            value={option.label}
                                                                            placeholder="Shown to people"
                                                                            onChange={(event) => {
                                                                                const next = [
                                                                                    ...(row.options ??
                                                                                        []),
                                                                                ];
                                                                                next[position] = {
                                                                                    label: event
                                                                                        .target
                                                                                        .value,
                                                                                    // Stored value follows the label
                                                                                    // unless it was set by hand.
                                                                                    value: option.value,
                                                                                };
                                                                                patch(index, {
                                                                                    options: next,
                                                                                });
                                                                            }}
                                                                        />

                                                                        <input
                                                                            className="form-control form-control-sm"
                                                                            value={option.value}
                                                                            placeholder="stored_value"
                                                                            onChange={(event) => {
                                                                                const next = [
                                                                                    ...(row.options ??
                                                                                        []),
                                                                                ];
                                                                                next[position] = {
                                                                                    label: option.label,
                                                                                    value: event
                                                                                        .target
                                                                                        .value,
                                                                                };
                                                                                patch(index, {
                                                                                    options: next,
                                                                                });
                                                                            }}
                                                                        />

                                                                        <button
                                                                            type="button"
                                                                            className="lf-remove"
                                                                            aria-label="Remove option"
                                                                            onClick={() =>
                                                                                patch(index, {
                                                                                    options: (
                                                                                        row.options ??
                                                                                        []
                                                                                    ).filter(
                                                                                        (_, i) =>
                                                                                            i !==
                                                                                            position,
                                                                                    ),
                                                                                })
                                                                            }
                                                                        >
                                                                            <i className="ti ti-x" />
                                                                        </button>
                                                                    </div>
                                                                ),
                                                            )}

                                                            <button
                                                                type="button"
                                                                className="lf-add-option"
                                                                onClick={() =>
                                                                    patch(index, {
                                                                        options: [
                                                                            ...(row.options ?? []),
                                                                            {
                                                                                value: '',
                                                                                label: '',
                                                                            },
                                                                        ],
                                                                    })
                                                                }
                                                            >
                                                                <i className="ti ti-plus" /> Add
                                                                option
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </article>
                                    );
                                })}
                            </div>
                        </section>
                    ))}

                    <button type="button" className="lf-add" onClick={addCustom}>
                        <i className="ti ti-plus" />
                        Add your own field
                    </button>
                </div>

                <div className="col-lg-4 form-rail">
                    <div className="lf-preview">
                        <header>
                            <i className="ti ti-eye" />
                            <div>
                                <b>Form preview</b>
                                <span>
                                    {onForm.length} on the form · {inTable.length} table columns
                                </span>
                            </div>
                        </header>

                        <div className="lf-preview-body">
                            {onForm.length === 0 ? (
                                <p className="lf-preview-empty">
                                    Every field is switched off. Turn at least one back on.
                                </p>
                            ) : (
                                onForm.map((row, index) => (
                                    <div className="lf-mock" key={`${row.field_key}-${index}`}>
                                        <span className="lf-mock-label">
                                            {row.label || row.field_key || 'Untitled'}
                                            {row.is_required && <i>*</i>}
                                        </span>

                                        {row.resolvedType === 'boolean' ? (
                                            <span className="lf-mock-switch" />
                                        ) : (
                                            <span
                                                className={`lf-mock-input${row.resolvedType === 'textarea' ? ' is-tall' : ''}`}
                                            >
                                                {row.placeholder ?? ''}
                                            </span>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
