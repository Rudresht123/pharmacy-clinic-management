import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/shared/utils/cn';

/** Must match .dp-pop in custom.css — used to decide whether it fits below. */
const POPOVER_WIDTH = 274;
const POPOVER_HEIGHT = 330;

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** "2026-09-02" — what the API stores, in local time rather than UTC. */
function toIsoDate(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** Parses "2026-09-02" without letting the browser shift it by a timezone. */
function fromIsoDate(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    if (!match) {
        return null;
    }

    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatForDisplay(date: Date): string {
    return `${`${date.getDate()}`.padStart(2, '0')} ${MONTHS[date.getMonth()].slice(0, 3)} ${date.getFullYear()}`;
}

function isSameDay(a: Date, b: Date): boolean {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

/**
 * The six-week grid for a month, Monday first, padded with the neighbouring
 * months so every row is full.
 */
function buildGrid(year: number, month: number): Date[] {
    const first = new Date(year, month, 1);

    // getDay() is Sunday-first; shift so Monday is column zero.
    const lead = (first.getDay() + 6) % 7;
    const start = new Date(year, month, 1 - lead);

    return Array.from({ length: 42 }, (_, index) => {
        const day = new Date(start);
        day.setDate(start.getDate() + index);

        return day;
    });
}

export interface DatePickerProps {
    /** Element id, so a label elsewhere can point at it. */
    id?: string;
    /** ISO "YYYY-MM-DD", or an empty string for no date. */
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    /** Names the dialog for screen readers. */
    label?: string;
    invalid?: boolean;
    className?: string;
}

/**
 * A date input with its own calendar, replacing `<input type="date">`.
 *
 * The native control renders differently in every browser, cannot be styled,
 * and shows dd/mm/yyyy scaffolding where a placeholder belongs. This one is
 * ours end to end: it displays a readable date, stores the ISO string the
 * API expects, and looks the same everywhere.
 *
 * Deliberately knows nothing about react-hook-form — a value and a callback,
 * so it works on a plain screen too (the module binding dates, for one).
 * DateField wraps it for forms.
 */
export function DatePicker({
    id,
    value,
    onChange,
    placeholder = 'Select a date',
    label,
    invalid,
    className,
}: DatePickerProps) {
    const wrapRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);

    /**
     * The calendar is positioned as `fixed` from the trigger's rect rather
     * than absolutely inside the field.
     *
     * A form's containers would otherwise bury it: `.form-rail` is
     * `position: sticky`, and `.form-actions` is sticky with a z-index and a
     * backdrop-filter, which paints its own stacking context over anything
     * layered inside the columns. Same escape RowActions uses to get out of
     * the table's overflow.
     */
    const [coords, setCoords] = useState<{ top: number; left: number } | null>(null);

    const selected = useMemo(() => fromIsoDate(value), [value]);
    const today = useMemo(() => new Date(), []);

    // Which month the calendar is showing, independent of the selection.
    const [view, setView] = useState(() => selected ?? today);

    // Reopening on an existing value should land on that month, not wherever
    // the user last browsed to.
    useEffect(() => {
        if (open) {
            setView(selected ?? today);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Keep the calendar pinned to its field while the page moves under it.
    useEffect(() => {
        if (!open) {
            return;
        }

        function place() {
            const trigger = triggerRef.current;

            if (!trigger) {
                return;
            }

            const rect = trigger.getBoundingClientRect();
            const gap = 6;

            // Flip above when there is not enough room below — the compliance
            // card sits near the bottom of this form, so this is the common
            // case rather than the edge case.
            const below = window.innerHeight - rect.bottom;
            const top =
                below < POPOVER_HEIGHT + gap && rect.top > below
                    ? rect.top - POPOVER_HEIGHT - gap
                    : rect.bottom + gap;

            const left = Math.min(rect.left, Math.max(8, window.innerWidth - POPOVER_WIDTH - 8));

            setCoords({ top, left });
        }

        place();

        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);

        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        function onPointerDown(event: MouseEvent) {
            if (wrapRef.current && !wrapRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    const grid = useMemo(() => buildGrid(view.getFullYear(), view.getMonth()), [view]);

    function choose(day: Date) {
        onChange(toIsoDate(day));
        setOpen(false);
    }

    function shiftMonth(by: number) {
        setView((current) => new Date(current.getFullYear(), current.getMonth() + by, 1));
    }

    return (
        <div className={cn('dp', className)} ref={wrapRef}>
            <button
                ref={triggerRef}
                type="button"
                id={id}
                className={cn('dp-input', invalid && 'is-invalid', open && 'is-open')}
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="dialog"
                aria-expanded={open}
            >
                <i className="ti ti-calendar dp-input-icon" aria-hidden="true" />

                <span className={cn('dp-value', !selected && 'is-placeholder')}>
                    {selected ? formatForDisplay(selected) : placeholder}
                </span>

                {selected && (
                    <span
                        role="button"
                        tabIndex={-1}
                        className="dp-clear"
                        title="Clear date"
                        aria-label="Clear date"
                        onClick={(event) => {
                            // Must not also toggle the calendar open.
                            event.stopPropagation();
                            onChange('');
                        }}
                    >
                        <i className="ti ti-x" />
                    </span>
                )}
            </button>

            {open && coords && (
                <div
                    className="dp-pop"
                    role="dialog"
                    aria-label={label ? `Choose ${label}` : 'Choose a date'}
                    style={{ top: coords.top, left: coords.left }}
                >
                    <div className="dp-head">
                        <button
                            type="button"
                            className="dp-nav"
                            onClick={() => shiftMonth(-1)}
                            aria-label="Previous month"
                        >
                            <i className="ti ti-chevron-left" />
                        </button>

                        <span className="dp-title">
                            {MONTHS[view.getMonth()]} {view.getFullYear()}
                        </span>

                        <button
                            type="button"
                            className="dp-nav"
                            onClick={() => shiftMonth(1)}
                            aria-label="Next month"
                        >
                            <i className="ti ti-chevron-right" />
                        </button>
                    </div>

                    <div className="dp-week">
                        {WEEKDAYS.map((day) => (
                            <span key={day}>{day}</span>
                        ))}
                    </div>

                    <div className="dp-grid">
                        {grid.map((day) => {
                            const outside = day.getMonth() !== view.getMonth();

                            return (
                                <button
                                    key={day.toISOString()}
                                    type="button"
                                    className={cn(
                                        'dp-day',
                                        outside && 'is-outside',
                                        isSameDay(day, today) && 'is-today',
                                        selected && isSameDay(day, selected) && 'is-selected',
                                    )}
                                    onClick={() => choose(day)}
                                >
                                    {day.getDate()}
                                </button>
                            );
                        })}
                    </div>

                    <div className="dp-foot">
                        <button type="button" className="dp-link" onClick={() => choose(today)}>
                            Today
                        </button>

                        <button
                            type="button"
                            className="dp-link"
                            onClick={() => {
                                onChange('');
                                setOpen(false);
                            }}
                        >
                            Clear
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
