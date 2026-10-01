import { useEffect, useRef, useState, type RefObject } from 'react';
import { createPortal } from 'react-dom';
import { Button } from '@/shared/components/ui/Button';
import { DatePicker } from '@/shared/components/form/DatePicker';

/**
 * Shared pieces of the billing register screens — Overview, Payments,
 * Outstanding — so each does not grow its own copy of the date-range
 * popover, the export menu and the per-row "⋮" menu.
 */

export function localIso(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Default to the current calendar month — 01 Sep 2026 - 30 Sep 2026. */
export function currentMonthWindow(): { from: string; to: string } {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    return {
        from: localIso(firstDay),
        to: localIso(lastDay),
    };
}

/**
 * Trailing 30 days, ending today.
 *
 * Calendar-month-to-date is empty on the 1st, and stays thin for the first
 * week of every month — a register that resets to all-zero on a schedule
 * reads as broken, not as "nothing happened yet". A trailing window never
 * does that: there is always a month of history behind today.
 */
export function last30DaysWindow(): { from: string; to: string } {
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 29);

    return {
        from: localIso(start),
        to: localIso(today),
    };
}

export function formatDisplayDate(iso: string): string {
    if (!iso) return '';
    const d = new Date(`${iso.slice(0, 10)}T00:00:00`);

    return d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export const money = (value: number) =>
    `₹${Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const moneyExact = (value: number) =>
    `₹${Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;

export const AVATAR_TONES = ['is-blue', 'is-orange', 'is-pink', 'is-green', 'is-purple', 'is-teal'];

export function initials(name: string): string {
    const words = (name || '').trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();

    return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}

export function avatarTone(name: string): string {
    let hash = 0;

    for (const letter of name || '') {
        hash = (hash * 31 + letter.charCodeAt(0)) >>> 0;
    }

    return AVATAR_TONES[hash % AVATAR_TONES.length];
}

/** Page numbers with a `'gap'` where the run of pages is cut, never more than one run per side. */
export function pageNumbers(current: number, total: number): (number | 'gap')[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const pages = new Set<number>([1, total, current]);

    for (const page of [current - 1, current + 1]) {
        if (page >= 1 && page <= total) {
            pages.add(page);
        }
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const out: (number | 'gap')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            out.push('gap');
        }
        out.push(page);
    });

    return out;
}

export function useDismiss(open: boolean, close: () => void, ...inside: RefObject<HTMLElement | null>[]) {
    useEffect(() => {
        if (!open) return;

        const outside = (event: MouseEvent) => {
            if (!inside.some((ref) => ref.current?.contains(event.target as Node))) close();
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') close();
        };

        document.addEventListener('mousedown', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);
}

/* ── Date range dropdown popover ────────────────────────────────────────── */

export function DateRangePicker({
    value,
    onChange,
}: {
    value: { from: string; to: string };
    onChange: (val: { from: string; to: string }) => void;
}) {
    const [open, setOpen] = useState(false);
    const [from, setFrom] = useState(value.from);
    const [to, setTo] = useState(value.to);
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    const today = new Date();
    const presets = [
        {
            label: 'This Month',
            from: new Date(today.getFullYear(), today.getMonth(), 1),
            to: new Date(today.getFullYear(), today.getMonth() + 1, 0),
        },
        {
            label: 'Last Month',
            from: new Date(today.getFullYear(), today.getMonth() - 1, 1),
            to: new Date(today.getFullYear(), today.getMonth(), 0),
        },
        {
            label: 'Last 7 Days',
            from: new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6),
            to: today,
        },
        {
            label: 'Last 30 Days',
            from: new Date(today.getFullYear(), today.getMonth(), today.getDate() - 29),
            to: today,
        },
        {
            label: 'This Year',
            from: new Date(today.getFullYear(), 0, 1),
            to: today,
        },
    ];

    function toggle() {
        setFrom(value.from);
        setTo(value.to);
        setOpen((o) => !o);
    }

    function applyPreset(p: { from: Date; to: Date }) {
        onChange({ from: localIso(p.from), to: localIso(p.to) });
        setOpen(false);
    }

    const invalid = !from || !to || from > to;

    return (
        <div className="inv-menu-wrap" ref={box}>
            <button
                type="button"
                className="bd-btn-select"
                onClick={toggle}
                aria-expanded={open}
            >
                <i className="ti ti-calendar" aria-hidden="true" />
                <span>
                    {formatDisplayDate(value.from)} - {formatDisplayDate(value.to)}
                </span>
                <i className="ti ti-chevron-down" aria-hidden="true" />
            </button>

            {open && (
                <div className="inv-range-pop" role="dialog" aria-label="Choose date range">
                    <div className="inv-range-presets">
                        {presets.map((preset) => (
                            <button
                                key={preset.label}
                                type="button"
                                onClick={() => applyPreset(preset)}
                            >
                                {preset.label}
                            </button>
                        ))}
                    </div>

                    <div className="row g-2">
                        <div className="col-6">
                            <label htmlFor="pop-from">From</label>
                            <DatePicker id="pop-from" value={from} onChange={setFrom} label="start date" />
                        </div>
                        <div className="col-6">
                            <label htmlFor="pop-to">To</label>
                            <DatePicker id="pop-to" value={to} onChange={setTo} label="end date" />
                        </div>
                    </div>

                    <div className="d-flex justify-content-end gap-2 mt-3">
                        <Button variant="light" size="sm" type="button" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            disabled={invalid}
                            onClick={() => {
                                onChange({ from, to });
                                setOpen(false);
                            }}
                        >
                            Apply
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

/* ── Export menu ───────────────────────────────────────────────────────── */

export function ExportMenu({
    selected,
    onExport,
}: {
    selected: number;
    onExport: (onlySelected: boolean) => void;
}) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    return (
        <div className="inv-menu-wrap" ref={box}>
            <button
                type="button"
                className="inv-export"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
            >
                <i className="ti ti-download" aria-hidden="true" />
                Export
            </button>

            {open && (
                <div className="inv-menu is-right" role="menu">
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => {
                            setOpen(false);
                            onExport(false);
                        }}
                    >
                        <i className="ti ti-file-spreadsheet" aria-hidden="true" />
                        Export this list (CSV)
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        disabled={selected === 0}
                        onClick={() => {
                            setOpen(false);
                            onExport(true);
                        }}
                    >
                        <i className="ti ti-checkbox" aria-hidden="true" />
                        Export selected ({selected})
                    </button>
                </div>
            )}
        </div>
    );
}

/* ── Row "⋮" menu ──────────────────────────────────────────────────────── */

export function RowMenu({ items }: { items: { label: string; icon: string; onClick: () => void }[] }) {
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    useDismiss(at !== null, () => setAt(null), button, menu);

    useEffect(() => {
        if (at === null) return;
        const close = () => setAt(null);
        window.addEventListener('scroll', close, true);

        return () => window.removeEventListener('scroll', close, true);
    }, [at]);

    function toggle() {
        if (at !== null) {
            setAt(null);
            return;
        }

        const rect = button.current?.getBoundingClientRect();
        if (rect) {
            setAt({ top: rect.bottom + 6, right: window.innerWidth - rect.right });
        }
    }

    return (
        <>
            <button
                ref={button}
                type="button"
                className="inv-btn is-icon"
                onClick={toggle}
                aria-label="More actions"
                aria-expanded={at !== null}
            >
                <i className="ti ti-dots-vertical" aria-hidden="true" />
            </button>

            {at !== null &&
                createPortal(
                    <div
                        ref={menu}
                        className="inv-menu is-fixed"
                        role="menu"
                        style={{ top: at.top, right: at.right }}
                    >
                        {items.map((item) => (
                            <button
                                key={item.label}
                                type="button"
                                role="menuitem"
                                onClick={() => {
                                    setAt(null);
                                    item.onClick();
                                }}
                            >
                                <i className={item.icon} aria-hidden="true" />
                                {item.label}
                            </button>
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}
