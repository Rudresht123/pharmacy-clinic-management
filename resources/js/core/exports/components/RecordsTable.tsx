import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Avatar } from '@/shared/components/ui/Avatar';
import { cn } from '@/shared/utils/cn';
import type { ExportColumn, ExportFormat, ExportRow } from '../types';

/** Columns whose value names a record — drawn in the brand colour. */
const REFERENCES = ['number', 'receipt', 'invoice', 'bill', 'against'];

/** Columns whose value is a state — drawn as a badge. */
const STATES = ['status', 'payment'];

/** Columns of running text — kept to two lines, the rest on hover. */
const PROSE = ['medicines', 'tests', 'address', 'reason', 'failure', 'notes', 'subject', 'medicine'];

/** A state's colour, by what it means rather than what it is called. */
export function toneOf(value: string): string {
    const text = value.toLowerCase();

    if (/cancel|fail|no show|blocked|sold out|inactive|^refunded/.test(text)) return 'danger';
    if (/expired|pending|unpaid|part|booked|queued|draft|paused|awaiting|outstanding/.test(text)) return 'warning';
    if (/completed|consultation|progress|scheduled|sending|on the visit bill/.test(text)) return 'info';
    if (/paid|active|delivered|read|posted|in stock|dispensed|issued|sent|checked in/.test(text)) return 'success';

    return 'neutral';
}

/**
 * The records themselves — the rows the filters match, a page at a time, in
 * the columns chosen, exactly as they will be written to the file.
 *
 * Rows can be ticked: with any ticked, the download buttons take only those.
 * A sortable column's heading sorts; the order carries into the file too.
 */
export function RecordsTable({
    columns,
    rows,
    sortable,
    sort,
    onSort,
    selected,
    onSelect,
    refreshing,
    onDownloadRow,
}: {
    columns: ExportColumn[];
    rows: ExportRow[];
    sortable: string[];
    sort: { key: string; direction: 'asc' | 'desc' } | null;
    onSort: (key: string) => void;
    selected: number[];
    onSelect: (ids: number[], on: boolean) => void;
    refreshing: boolean;
    onDownloadRow: (id: number, format: ExportFormat) => void;
}) {
    const navigate = useNavigate();
    const [menu, setMenu] = useState<number | null>(null);

    useEffect(() => {
        if (menu === null) return;

        const away = (event: MouseEvent) => {
            if (!(event.target as HTMLElement).closest?.('.exc-row-menu')) setMenu(null);
        };

        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, [menu]);

    const ids = rows.map((row) => row.id);
    const allOn = ids.length > 0 && ids.every((id) => selected.includes(id));
    const someOn = !allOn && ids.some((id) => selected.includes(id));

    const cell = (column: ExportColumn, row: ExportRow, value: string) => {
        if (value === '') {
            return <span className="exc-dash">—</span>;
        }

        const sub = row.subs[column.key];

        if (column.key === 'patient' || (column.key === 'name' && sub)) {
            return (
                <span className="exc-person">
                    {/* Their photograph when one was taken — click to see it — else initials. */}
                    <Avatar name={value} src={row.photo} size={32} caption={sub} />
                    <span className="exc-person-text">
                        <b>{value}</b>
                        {sub && <small>{sub}</small>}
                    </span>
                </span>
            );
        }

        if (STATES.includes(column.key)) {
            return <span className={cn('exc-badge', `is-${toneOf(value)}`)}>{value}</span>;
        }

        if (PROSE.includes(column.key)) {
            return (
                <span className="exc-prose" title={value}>
                    {value}
                </span>
            );
        }

        return (
            <>
                <span className={cn(REFERENCES.includes(column.key) && 'exc-ref')}>{value}</span>
                {sub && <small className="exc-sub">{sub}</small>}
            </>
        );
    };

    return (
        <div className={cn('exc-table-frame', refreshing && 'is-refreshing')}>
            <table className="exc-table">
                <thead>
                    <tr>
                        <th className="exc-col-check">
                            <input
                                type="checkbox"
                                checked={allOn}
                                ref={(input) => {
                                    if (input) input.indeterminate = someOn;
                                }}
                                onChange={() => onSelect(ids, !allOn)}
                                aria-label="Select every row on this page"
                            />
                        </th>

                        {columns.map((column) => {
                            const numeric = column.type === 'money' || column.type === 'number';
                            const canSort = sortable.includes(column.key);
                            const on = sort?.key === column.key;

                            return (
                                <th key={column.key} className={cn(numeric && 'is-num')} aria-sort={on ? (sort?.direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                                    {canSort ? (
                                        <button type="button" className={cn('exc-sort', on && 'is-on')} onClick={() => onSort(column.key)}>
                                            {column.label}
                                            <i
                                                className={
                                                    on ? (sort?.direction === 'asc' ? 'ti ti-sort-ascending' : 'ti ti-sort-descending') : 'ti ti-selector'
                                                }
                                                aria-hidden="true"
                                            />
                                        </button>
                                    ) : (
                                        column.label
                                    )}
                                </th>
                            );
                        })}

                        <th className="exc-col-actions">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    {rows.map((row) => {
                        const on = selected.includes(row.id);

                        return (
                            <tr key={row.id} className={cn(on && 'is-selected', row.muted && 'is-muted')}>
                                <td className="exc-col-check">
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        onChange={() => onSelect([row.id], !on)}
                                        aria-label="Select this row"
                                    />
                                </td>

                                {columns.map((column, index) => {
                                    const numeric = column.type === 'money' || column.type === 'number';

                                    return (
                                        <td key={column.key} className={cn(numeric && 'is-num')}>
                                            {cell(column, row, row.cells[index] ?? '')}
                                        </td>
                                    );
                                })}

                                <td className="exc-col-actions">
                                    <div className="exc-row-menu">
                                        <button
                                            type="button"
                                            className="exc-dots"
                                            onClick={() => setMenu(menu === row.id ? null : row.id)}
                                            aria-haspopup="menu"
                                            aria-expanded={menu === row.id}
                                            aria-label="Row actions"
                                        >
                                            <i className="ti ti-dots" aria-hidden="true" />
                                        </button>

                                        {menu === row.id && (
                                            <div className="exc-pop exc-row-pop" role="menu">
                                                {row.link && (
                                                    <button type="button" role="menuitem" onClick={() => navigate(row.link as string)}>
                                                        <i className="ti ti-external-link" aria-hidden="true" /> Open record
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    role="menuitem"
                                                    onClick={() => {
                                                        setMenu(null);
                                                        onDownloadRow(row.id, 'pdf');
                                                    }}
                                                >
                                                    <i className="ti ti-file-type-pdf" aria-hidden="true" /> Download this row as PDF
                                                </button>
                                                <button
                                                    type="button"
                                                    role="menuitem"
                                                    onClick={() => {
                                                        setMenu(null);
                                                        onDownloadRow(row.id, 'xlsx');
                                                    }}
                                                >
                                                    <i className="ti ti-file-spreadsheet" aria-hidden="true" /> Download this row as Excel
                                                </button>
                                                <button
                                                    type="button"
                                                    role="menuitem"
                                                    onClick={() => {
                                                        setMenu(null);
                                                        onSelect([row.id], !on);
                                                    }}
                                                >
                                                    <i className={on ? 'ti ti-square-minus' : 'ti ti-square-check'} aria-hidden="true" />
                                                    {on ? 'Untick row' : 'Tick row for export'}
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
