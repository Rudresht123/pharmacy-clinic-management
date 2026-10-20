import { cn } from '@/shared/utils/cn';
import type { ExportColumn } from '../types';
import { usePopover } from './usePopover';

/**
 * "Columns (11/11) ▾" — one menu that adds and removes any column. Kept in
 * the export's own order whatever order they were picked in, so the file and
 * the table read the same way round.
 */
export function ColumnPicker({
    columns,
    chosen,
    onChange,
}: {
    columns: ExportColumn[];
    chosen: string[];
    onChange: (next: string[]) => void;
}) {
    const { open, setOpen, ref } = usePopover();

    const toggle = (key: string) =>
        onChange(
            chosen.includes(key)
                ? chosen.filter((value) => value !== key)
                : columns.map((column) => column.key).filter((value) => value === key || chosen.includes(value)),
        );

    return (
        <div className="exc-columns-menu" ref={ref}>
            <button
                type="button"
                className={cn('exc-control exc-columns-trigger', open && 'is-open')}
                onClick={() => setOpen((was) => !was)}
                aria-haspopup="menu"
                aria-expanded={open}
            >
                <i className="ti ti-columns-3" aria-hidden="true" />
                <span>
                    Columns ({chosen.length}/{columns.length})
                </span>
                <i className="ti ti-chevron-down exc-caret" aria-hidden="true" />
            </button>

            {open && (
                <div className="exc-pop exc-columns-pop" role="menu">
                    <div className="exc-columns-pop-head">
                        <b>Columns in the table and the file</b>
                        <span>
                            <button type="button" onClick={() => onChange(columns.map((column) => column.key))}>
                                All
                            </button>
                            <button type="button" onClick={() => onChange([])}>
                                None
                            </button>
                        </span>
                    </div>

                    <div className="exc-columns-list">
                        {columns.map((column) => {
                            const on = chosen.includes(column.key);

                            return (
                                <label key={column.key} className={cn('exc-check-row', on && 'is-on')}>
                                    <input type="checkbox" checked={on} onChange={() => toggle(column.key)} />
                                    <span>{column.label}</span>
                                </label>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}
