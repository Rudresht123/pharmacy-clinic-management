import { useRef, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { formatDisplayDate, localIso, useDismiss } from '@/core/billing/components/BillingWidgets';

export interface Range {
    from: string;
    to: string;
}

/** The spans a report is most often read over, worked out from today. */
function presets(): { key: string; label: string; range: Range }[] {
    const today = new Date();
    const y = today.getFullYear();
    const m = today.getMonth();
    const d = today.getDate();
    const quarter = Math.floor(m / 3) * 3;

    const span = (from: Date, to: Date) => ({ from: localIso(from), to: localIso(to) });

    return [
        { key: 'today', label: 'Today', range: span(today, today) },
        { key: 'last_7', label: 'Last 7 days', range: span(new Date(y, m, d - 6), today) },
        { key: 'this_month', label: 'This month', range: span(new Date(y, m, 1), new Date(y, m + 1, 0)) },
        { key: 'last_month', label: 'Last month', range: span(new Date(y, m - 1, 1), new Date(y, m, 0)) },
        { key: 'last_30', label: 'Last 30 days', range: span(new Date(y, m, d - 29), today) },
        { key: 'this_quarter', label: 'This quarter', range: span(new Date(y, quarter, 1), new Date(y, quarter + 3, 0)) },
        { key: 'this_year', label: 'This year', range: span(new Date(y, 0, 1), new Date(y, 11, 31)) },
    ];
}

/** The window a report opens on: this month, as the reference screen does. */
export function defaultRange(): Range {
    return presets().find((preset) => preset.key === 'this_month')!.range;
}

/** "vs last month" when the range is one whole month, as it usually is. */
export function rangeComparison(range: Range): string {
    const from = new Date(`${range.from}T00:00:00`);
    const to = new Date(`${range.to}T00:00:00`);
    const lastDay = new Date(from.getFullYear(), from.getMonth() + 1, 0);

    return from.getDate() === 1 && to.getTime() === lastDay.getTime() ? 'vs last month' : 'vs previous period';
}

/** "This month" when the range is a named one, otherwise its two dates. */
export function rangeLabel(range: Range): string {
    const named = presets().find((preset) => preset.range.from === range.from && preset.range.to === range.to);

    return named ? named.label : `${formatDisplayDate(range.from)} – ${formatDisplayDate(range.to)}`;
}

/**
 * A date range offered the way it is usually asked for — "this month",
 * "last month" — with exact dates one step further for the rest.
 *
 * `disabled` for the reports about the shelf right now: the control stays
 * where it always is, so the header does not jump between tabs, but says it
 * has nothing to change.
 */
export function RangeSelect({
    value,
    onChange,
    disabled = false,
}: {
    value: Range;
    onChange: (range: Range) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [from, setFrom] = useState(value.from);
    const [to, setTo] = useState(value.to);
    const box = useRef<HTMLDivElement>(null);

    useDismiss(open, () => setOpen(false), box);

    const options = presets();
    const current = options.find((preset) => preset.range.from === value.from && preset.range.to === value.to);

    function choose(range: Range) {
        onChange(range);
        setOpen(false);
    }

    return (
        <div className="phr-range" ref={box}>
            <label className="form-label mb-1" htmlFor="phr-range-button">
                Date range
            </label>

            <button
                id="phr-range-button"
                type="button"
                className="phr-range-button"
                aria-expanded={open}
                aria-haspopup="dialog"
                disabled={disabled}
                title={disabled ? 'This report is about the shelf today, not a span of dates' : undefined}
                onClick={() => {
                    setFrom(value.from);
                    setTo(value.to);
                    setOpen((was) => !was);
                }}
            >
                <i className="ti ti-calendar" aria-hidden="true" />
                <span>{disabled ? 'As of today' : rangeLabel(value)}</span>
                <i className="ti ti-chevron-down" aria-hidden="true" />
            </button>

            {open && !disabled && (
                <div className="phr-range-pop" role="dialog" aria-label="Choose a date range">
                    <ul>
                        {options.map((preset) => (
                            <li key={preset.key}>
                                <button
                                    type="button"
                                    className={current?.key === preset.key ? 'is-active' : undefined}
                                    onClick={() => choose(preset.range)}
                                >
                                    {preset.label}
                                    {current?.key === preset.key && <i className="ti ti-check" aria-hidden="true" />}
                                </button>
                            </li>
                        ))}
                    </ul>

                    <div className="phr-range-custom">
                        <span>Custom range</span>

                        <div className="phr-range-fields">
                            <DatePicker id="phr-range-from" value={from} onChange={setFrom} label="start date" />
                            <DatePicker id="phr-range-to" value={to} onChange={setTo} label="end date" />
                        </div>

                        <Button
                            size="sm"
                            type="button"
                            disabled={!from || !to || from > to}
                            onClick={() => choose({ from, to })}
                        >
                            Apply
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
