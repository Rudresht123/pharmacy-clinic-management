import { useEffect, useRef, useState } from 'react';
import { useController, type Control, type FieldValues, type Path } from 'react-hook-form';

export interface PickOption {
    value: string;
    label: string;
    /** A line under the name, where the name alone would not say who it means. */
    hint?: string;
}

/** Room a menu needs below it. With less, it opens upward. */
const MENU_ROOM = 310;

/**
 * Who is on one list: a pill for each, then a dashed Add button.
 *
 * Not a select box. The people are the point of this row, so they stand on their own
 * rather than inside a control, and the menu floats over the page so nothing moves
 * when it opens.
 *
 * The menu is short on purpose: the few jobs a clinic actually chooses between, and
 * one way through to anybody on the staff.
 */
export function RecipientPills<T extends FieldValues>({
    name,
    label,
    control,
    places,
    staff,
    names,
    takenOn = {},
    loading = false,
    error,
}: {
    name: Path<T>;
    label: string;
    control: Control<T>;
    /** The jobs offered first. */
    places: PickOption[];
    /** Anybody on the staff, behind Custom selection. */
    staff: PickOption[];
    /** What to call whoever is already saved, including anybody no longer offered. */
    names: Record<string, string>;
    /** Whoever is on another of this message's lists, and which: they can't be on two. */
    takenOn?: Record<string, string>;
    loading?: boolean;
    error?: string;
}) {
    const { field } = useController({ name, control });
    const chosen: string[] = Array.isArray(field.value) ? (field.value as string[]) : [];

    const [open, setOpen] = useState(false);
    const [upward, setUpward] = useState(false);
    const [choosingStaff, setChoosingStaff] = useState(false);
    const [search, setSearch] = useState('');
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function onOutside(event: MouseEvent) {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    function toggleMenu() {
        if (!open && box.current) {
            setUpward(window.innerHeight - box.current.getBoundingClientRect().bottom < MENU_ROOM);
            setChoosingStaff(false);
            setSearch('');
        }

        setOpen((was) => !was);
    }

    function toggle(value: string) {
        field.onChange(chosen.includes(value) ? chosen.filter((entry) => entry !== value) : [...chosen, value]);
    }

    const nameOf = (value: string) => names[value] ?? value;

    const needle = search.trim().toLowerCase();
    const staffShown = staff.filter((person) => needle === '' || person.label.toLowerCase().includes(needle));
    const staffChosen = chosen.filter((value) => value.startsWith('user:')).length;

    const item = (option: PickOption) => {
        const on = chosen.includes(option.value);
        const elsewhere = takenOn[option.value];

        return (
            <button
                type="button"
                key={option.value}
                role="option"
                aria-selected={on}
                className={`rcp-menu-item${on ? ' is-on' : ''}`}
                disabled={Boolean(elsewhere)}
                onClick={() => toggle(option.value)}
            >
                <i className={on ? 'ti ti-square-check-filled' : 'ti ti-square'} aria-hidden="true" />
                <span>
                    {option.label}
                    {option.hint && <small>{option.hint}</small>}
                </span>
                {elsewhere && <em>On {elsewhere}</em>}
            </button>
        );
    };

    return (
        <div className="rcp-list" ref={box}>
            <span className="rcp-list-label">{label}</span>

            <div className="rcp-pills">
                {chosen.map((value) => (
                    <span className="rcp-pill" key={value}>
                        {nameOf(value)}
                        <button type="button" aria-label={`Remove ${nameOf(value)} from ${label}`} onClick={() => toggle(value)}>
                            <i className="ti ti-x" aria-hidden="true" />
                        </button>
                    </span>
                ))}

                <button
                    type="button"
                    className={`rcp-add-btn${open ? ' is-open' : ''}`}
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    aria-label={`Add to ${label}`}
                    onClick={toggleMenu}
                >
                    <i className="ti ti-plus" aria-hidden="true" />
                    Add
                </button>
            </div>

            {error && <div className="invalid-feedback d-block">{error}</div>}

            {open && (
                <div className={`rcp-menu${upward ? ' is-up' : ''}`}>
                    {choosingStaff ? (
                        <>
                            <button type="button" className="rcp-menu-back" onClick={() => setChoosingStaff(false)}>
                                <i className="ti ti-arrow-left" aria-hidden="true" />
                                Custom selection
                            </button>

                            <div className="rcp-menu-search">
                                <i className="ti ti-search" aria-hidden="true" />
                                <input
                                    type="search"
                                    placeholder="Search staff…"
                                    aria-label="Search staff"
                                    value={search}
                                    autoFocus
                                    onChange={(event) => setSearch(event.target.value)}
                                    onKeyDown={(event) => {
                                        // Enter here must not save the whole form.
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                        }
                                    }}
                                />
                            </div>

                            <div className="rcp-menu-list" role="listbox" aria-multiselectable="true">
                                {loading && <p className="rcp-menu-none">Loading…</p>}

                                {!loading && staffShown.length === 0 && (
                                    <p className="rcp-menu-none">{staff.length === 0 ? 'No staff yet.' : 'Nobody matches.'}</p>
                                )}

                                {staffShown.map(item)}
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="rcp-menu-list" role="listbox" aria-multiselectable="true">
                                {places.map(item)}
                            </div>

                            <button
                                type="button"
                                className="rcp-menu-custom"
                                onClick={() => {
                                    setChoosingStaff(true);
                                    setSearch('');
                                }}
                            >
                                <i className="ti ti-user-plus" aria-hidden="true" />
                                <span>
                                    Custom selection
                                    <small>Anybody from your staff</small>
                                </span>
                                {staffChosen > 0 && <em>{staffChosen}</em>}
                                <i className="ti ti-chevron-right" aria-hidden="true" />
                            </button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
