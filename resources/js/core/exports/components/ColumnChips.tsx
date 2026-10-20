import { cn } from '@/shared/utils/cn';
import type { ExportColumn } from '../types';

/**
 * A row of columns, each one a chip that is on or off.
 *
 * Used twice with two meanings: on the Export Center, which columns go into
 * this file; on Column access, which columns a role may take at all. The
 * chip only says on or off — the caller says what that means.
 */
export function ColumnChips({
    columns,
    chosen,
    onChange,
    disabled = false,
    allLabel = 'All',
    noneLabel = 'None',
}: {
    columns: ExportColumn[];
    chosen: string[];
    onChange: (next: string[]) => void;
    disabled?: boolean;
    allLabel?: string;
    noneLabel?: string;
}) {
    const toggle = (key: string) =>
        onChange(
            chosen.includes(key)
                ? chosen.filter((value) => value !== key)
                : // Kept in the export's own order, whatever order they were ticked in.
                  columns.map((column) => column.key).filter((value) => value === key || chosen.includes(value)),
        );

    return (
        <div className="exc-chips-wrap">
            <div className="exc-chips-head">
                <span className="exc-chips-count">
                    <b>{chosen.length}</b> of {columns.length} columns
                </span>

                <span className="exc-chips-actions">
                    <button
                        type="button"
                        onClick={() => onChange(columns.map((column) => column.key))}
                        disabled={disabled || chosen.length === columns.length}
                    >
                        {allLabel}
                    </button>
                    <button type="button" onClick={() => onChange([])} disabled={disabled || chosen.length === 0}>
                        {noneLabel}
                    </button>
                </span>
            </div>

            <div className="exc-chips" role="group" aria-label="Columns">
                {columns.map((column) => {
                    const on = chosen.includes(column.key);

                    return (
                        <button
                            key={column.key}
                            type="button"
                            className={cn('exc-chip', on && 'is-on')}
                            aria-pressed={on}
                            onClick={() => toggle(column.key)}
                            disabled={disabled}
                        >
                            <i className={on ? 'ti ti-check' : 'ti ti-plus'} aria-hidden="true" />
                            {column.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
