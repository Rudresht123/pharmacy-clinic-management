import type { ReactNode } from 'react';

export interface FilterOption {
    value: string;
    label: string;
}

export interface FilterSelect {
    /** Query key this filter maps to, also its element id. */
    name: string;
    label: string;
    value: string;
    options: FilterOption[];
    /** Shown as the "no filter" choice. */
    anyLabel?: string;
    onChange: (value: string) => void;
}

/**
 * The row of dropdowns above a table.
 *
 * Kept generic so every list screen filters the same way, and so "clear
 * everything" stays one behaviour rather than each page's own idea of it.
 */
export function FilterBar({
    filters,
    onClear,
    children,
}: {
    filters: FilterSelect[];
    /** Omit to hide the reset control entirely. */
    onClear?: () => void;
    /** Anything extra — a date range, an export button. */
    children?: ReactNode;
}) {
    const active = filters.some((filter) => filter.value !== '');

    return (
        <div className="filter-bar">
            {filters.map((filter) => (
                <label className="filter-field" key={filter.name} htmlFor={filter.name}>
                    <span>{filter.label}</span>

                    <select
                        id={filter.name}
                        className="form-select form-select-sm"
                        value={filter.value}
                        onChange={(event) => filter.onChange(event.target.value)}
                    >
                        <option value="">{filter.anyLabel ?? 'All'}</option>

                        {filter.options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </label>
            ))}

            {children}

            {onClear && active && (
                <button type="button" className="filter-clear" onClick={onClear}>
                    <i className="ti ti-x" />
                    Clear filters
                </button>
            )}
        </div>
    );
}
