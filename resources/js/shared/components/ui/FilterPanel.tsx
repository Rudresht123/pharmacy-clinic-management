import { useCallback, useEffect, useState } from 'react';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';

export interface FilterOption {
    value: string;
    label: string;
}

interface BaseField {
    /** Query key this filter maps to, also its element id. */
    name: string;
    label: string;
    value: string;
}

export interface SelectField extends BaseField {
    kind: 'select';
    options: FilterOption[];
    /** Shown as the "no filter" choice. */
    anyLabel?: string;
}

export interface TextField extends BaseField {
    kind: 'text';
    placeholder?: string;
}

export type FilterField = SelectField | TextField;

/** What a chip shows for a field that has a value. */
function readValue(field: FilterField) {
    if (field.kind === 'select') {
        return field.options.find((option) => option.value === field.value)?.label ?? field.value;
    }

    return field.value;
}

/**
 * A text filter that does not refetch on every keystroke.
 *
 * The input keeps its own value so typing stays instant, and only tells the
 * page once the typing stops. Without this, "Pune" is four requests and the
 * last three are the only ones that matter.
 */
function DebouncedText({
    field,
    onCommit,
}: {
    field: TextField;
    onCommit: (value: string) => void;
}) {
    const [draft, setDraft] = useState(field.value);

    // A "clear all", or a chip being removed, has to reach the input.
    useEffect(() => setDraft(field.value), [field.value]);

    useEffect(() => {
        if (draft === field.value) {
            return;
        }

        const timer = window.setTimeout(() => onCommit(draft), 350);

        return () => window.clearTimeout(timer);
    }, [draft, field.value, onCommit]);

    return (
        <input
            id={field.name}
            type="search"
            className="form-control form-control-sm"
            placeholder={field.placeholder}
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
        />
    );
}

/**
 * The filters for a list, as their own panel above it.
 *
 * Separated from the table because they are a different act: choosing what
 * to look at, and then looking at it. Folded into the table's own card they
 * read as part of its header and get lost the moment there are more than
 * two of them.
 *
 * What is currently applied is repeated as chips underneath, so a reader who
 * scrolled past the controls — or arrived on a filtered link — can still see
 * why the table is short, and can undo one thing without resetting
 * everything.
 */
export function FilterPanel({
    fields,
    onChange,
    onClear,
    title = 'Filters',
}: {
    fields: FilterField[];
    /** One handler for the panel; the page keeps the values. */
    onChange: (name: string, value: string) => void;
    /** Omit to hide the reset control entirely. */
    onClear?: () => void;
    title?: string;
}) {
    const applied = fields.filter((field) => field.value !== '');

    const commit = useCallback(
        (name: string) => (value: string) => onChange(name, value),
        [onChange],
    );

    return (
        <div className="card filter-card">
            <div className="filter-card-head">
                <span className="filter-card-title">
                    <i className="ti ti-filter" aria-hidden="true" />
                    {title}
                    {applied.length > 0 && <span className="filter-count">{applied.length}</span>}
                </span>

                {onClear && applied.length > 0 && (
                    <button type="button" className="filter-clear" onClick={onClear}>
                        <i className="ti ti-x" />
                        Clear all
                    </button>
                )}
            </div>

            <div className="filter-grid">
                {fields.map((field) => (
                    <div className="filter-field" key={field.name}>
                        <label htmlFor={field.name}>{field.label}</label>

                        {field.kind === 'select' ? (
                            <SearchableSelect
                                compact
                                id={field.name}
                                value={field.value}
                                placeholder={field.anyLabel ?? 'All'}
                                clearable
                                onChange={(next) => onChange(field.name, next)}
                                options={field.options.map((option) => ({
                                    value: String(option.value),
                                    label: option.label,
                                }))}
                            />
                        ) : (
                            <DebouncedText field={field} onCommit={commit(field.name)} />
                        )}
                    </div>
                ))}
            </div>

            {applied.length > 0 && (
                <div className="filter-chips">
                    {applied.map((field) => (
                        <button
                            type="button"
                            className="filter-chip"
                            key={field.name}
                            onClick={() => onChange(field.name, '')}
                            title={`Remove the ${field.label.toLowerCase()} filter`}
                        >
                            <span className="filter-chip-label">{field.label}</span>
                            <span className="filter-chip-value">{readValue(field)}</span>
                            <i className="ti ti-x" aria-hidden="true" />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
