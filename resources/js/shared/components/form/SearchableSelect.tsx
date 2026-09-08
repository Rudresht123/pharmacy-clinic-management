import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export interface SelectOption {
    value: string;
    label: string;
    /** A second line, for when the label alone is ambiguous. */
    hint?: string;
}

/**
 * One from a list, with a search box.
 *
 * A native `<select>` cannot be searched past its first letter, shows a
 * system-drawn list nobody can style, and on a list of forty doctors makes
 * finding one a scroll. This is the same control with the two things that
 * were missing: type to filter, and a panel that looks like the rest of the
 * product.
 *
 * The panel is rendered into `document.body` rather than beside the button.
 * Every card, table wrapper and scroll box on these screens clips its
 * overflow, and a list positioned inside one gets cut off at the card's edge
 * — which is exactly the bug this replaces. Fixed to the viewport, nothing
 * can clip it.
 */
export function SearchableSelect({
    value,
    onChange,
    options,
    placeholder = 'Select…',
    id,
    disabled = false,
    invalid = false,
    className,
    ariaLabel,
    clearable = false,
    compact = false,
}: {
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    placeholder?: string;
    id?: string;
    disabled?: boolean;
    invalid?: boolean;
    className?: string;
    ariaLabel?: string;
    /** Offers a "none" row. Off by default — most of these must have a value. */
    clearable?: boolean;
    /** Sized for a table row rather than a form. */
    compact?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);

    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);

    const [box, setBox] = useState({ top: 0, left: 0, width: 0, drop: true });

    const chosen = options.find((option) => option.value === String(value ?? ''));

    const shown = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) return options;

        return options.filter(
            (option) =>
                option.label.toLowerCase().includes(needle) ||
                option.hint?.toLowerCase().includes(needle),
        );
    }, [options, query]);

    /*
     * Measured from the trigger, in viewport coordinates.
     *
     * Before paint, so the panel never shows for a frame in the wrong place,
     * and flipped above the button when there is not room below — a dropdown
     * that opens off the bottom of the window is the thing this is replacing.
     */
    useLayoutEffect(() => {
        if (!open || !trigger.current) return;

        const rect = trigger.current.getBoundingClientRect();
        const room = window.innerHeight - rect.bottom;
        const drop = room > 260 || room > rect.top;

        setBox({
            top: drop ? rect.bottom + 4 : rect.top - 4,
            left: rect.left,
            width: rect.width,
            drop,
        });
    }, [open]);

    /* The search box is the point of opening it, so it takes the caret. */
    useEffect(() => {
        if (open) search.current?.focus();
        else {
            setQuery('');
            setActive(0);
        }
    }, [open]);

    /*
     * Closed by anything that would move it.
     *
     * Scroll is captured, because the thing that scrolls is usually an inner
     * container rather than the window, and a panel pinned to the viewport
     * would otherwise sit still while the button slid away from under it.
     */
    useEffect(() => {
        if (!open) return;

        function away(event: MouseEvent) {
            const target = event.target as Node;

            if (!trigger.current?.contains(target) && !panel.current?.contains(target)) {
                setOpen(false);
            }
        }

        function shut() {
            setOpen(false);
        }

        document.addEventListener('mousedown', away);
        window.addEventListener('scroll', shut, true);
        window.addEventListener('resize', shut);

        return () => {
            document.removeEventListener('mousedown', away);
            window.removeEventListener('scroll', shut, true);
            window.removeEventListener('resize', shut);
        };
    }, [open]);

    function pick(option: SelectOption) {
        onChange(option.value);
        setOpen(false);
        trigger.current?.focus();
    }

    function onKeyDown(event: React.KeyboardEvent) {
        if (event.key === 'Escape') {
            setOpen(false);
            trigger.current?.focus();
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            setActive((was) => {
                const next = event.key === 'ArrowDown' ? was + 1 : was - 1;

                return Math.max(0, Math.min(shown.length - 1, next));
            });

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();

            if (shown[active]) pick(shown[active]);
        }
    }

    return (
        <>
            <button
                type="button"
                id={id}
                ref={trigger}
                disabled={disabled}
                aria-label={ariaLabel}
                aria-haspopup="listbox"
                aria-expanded={open}
                className={`ss${compact ? ' ss-sm' : ''}${invalid ? ' is-invalid' : ''}${
                    open ? ' is-open' : ''
                }${className ? ` ${className}` : ''}`}
                onClick={() => setOpen((was) => !was)}
                onKeyDown={(event) => {
                    // Opening with the keyboard lands in the search box, so
                    // typing a name is the first thing that happens either way.
                    if (!open && (event.key === 'ArrowDown' || event.key === 'Enter')) {
                        event.preventDefault();
                        setOpen(true);
                    }
                }}
            >
                <span className={chosen ? undefined : 'ss-empty'}>
                    {chosen?.label ?? placeholder}
                </span>

                <i className="ti ti-chevron-down" aria-hidden="true" />
            </button>

            {open &&
                createPortal(
                    <div
                        ref={panel}
                        className={`ss-pop${box.drop ? '' : ' is-up'}`}
                        style={{
                            top: box.drop ? box.top : undefined,
                            bottom: box.drop ? undefined : window.innerHeight - box.top,
                            left: box.left,
                            /*
                                Never narrower than a search box needs.
                                Some of these triggers are 88px wide in a table
                                cell, and a panel matched to that would put a
                                magnifier, a caret and a placeholder into a
                                space that fits none of them.
                            */
                            minWidth: Math.max(box.width, 216),
                        }}
                        onKeyDown={onKeyDown}
                    >
                        <div className="ss-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                ref={search}
                                type="text"
                                value={query}
                                placeholder="Search…"
                                aria-label="Search the list"
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    setActive(0);
                                }}
                            />

                            {query && (
                                <button
                                    type="button"
                                    className="ss-wipe"
                                    aria-label="Clear the search"
                                    onClick={() => {
                                        setQuery('');
                                        search.current?.focus();
                                    }}
                                >
                                    <i className="ti ti-x" aria-hidden="true" />
                                </button>
                            )}
                        </div>

                        <ul className="ss-list" role="listbox">
                            {clearable && !query && (
                                <li>
                                    <button
                                        type="button"
                                        className={`ss-opt${value === '' ? ' is-on' : ''}`}
                                        onClick={() => {
                                            onChange('');
                                            setOpen(false);
                                        }}
                                    >
                                        <span className="ss-empty">{placeholder}</span>
                                    </button>
                                </li>
                            )}

                            {shown.length === 0 ? (
                                <li className="ss-none">Nothing matches &ldquo;{query}&rdquo;</li>
                            ) : (
                                shown.map((option, index) => (
                                    <li key={option.value}>
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected={option.value === String(value ?? '')}
                                            className={`ss-opt${
                                                option.value === String(value ?? '') ? ' is-on' : ''
                                            }${index === active ? ' is-active' : ''}`}
                                            /*
                                                Pointer moves the highlight, so the
                                                mouse and the arrow keys never
                                                disagree about which row is next.
                                            */
                                            onMouseEnter={() => setActive(index)}
                                            onClick={() => pick(option)}
                                        >
                                            <span>
                                                {option.label}
                                                {option.hint && <small>{option.hint}</small>}
                                            </span>

                                            {option.value === String(value ?? '') && (
                                                <i className="ti ti-check" aria-hidden="true" />
                                            )}
                                        </button>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>,
                    document.body,
                )}
        </>
    );
}
