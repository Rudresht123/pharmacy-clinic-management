import { useState } from 'react';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { cn } from '@/shared/utils/cn';
import { periodsFor, rangeOf } from '../periods';
import { usePopover } from './usePopover';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "2026-10-01" → "01 Oct 2026". */
export function readable(iso: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

    return match ? `${match[3]} ${MONTHS[Number(match[2]) - 1]} ${match[1]}` : iso;
}

export interface DateRange {
    period: string;
    from: string;
    to: string;
}

/**
 * One control for a date range — "01 Oct 2026 → 20 Oct 2026" — that opens on
 * the usual choices (this month, last month…) with a custom pair of dates
 * underneath for anything else.
 */
export function DateRangeField({
    value,
    onChange,
    defaultPeriod,
    label,
}: {
    value: DateRange;
    onChange: (next: DateRange) => void;
    defaultPeriod: string;
    label: string;
}) {
    const { open, setOpen, ref } = usePopover();
    const [custom, setCustom] = useState({ from: value.from, to: value.to });

    const periods = periodsFor(defaultPeriod).filter((period) => period.value !== 'custom');
    const shown =
        value.from || value.to
            ? `${value.from ? readable(value.from) : 'Any date'} → ${value.to ? readable(value.to) : 'any date'}`
            : (periods.find((period) => period.value === value.period)?.label ?? 'Any date');

    const pick = (period: string) => {
        onChange({ period, ...rangeOf(period, { from: '', to: '' }) });
        setOpen(false);
    };

    return (
        <div className="exc-range" ref={ref}>
            <button
                type="button"
                className={cn('exc-control exc-range-trigger', open && 'is-open')}
                onClick={() => {
                    setCustom({ from: value.from, to: value.to });
                    setOpen((was) => !was);
                }}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-label={label}
            >
                <i className="ti ti-calendar" aria-hidden="true" />
                <span>{shown}</span>
                <i className="ti ti-chevron-down exc-caret" aria-hidden="true" />
            </button>

            {open && (
                <div className="exc-pop exc-range-pop" role="dialog" aria-label={`Choose ${label.toLowerCase()}`}>
                    <div className="exc-range-presets">
                        {periods.map((period) => (
                            <button
                                key={period.value}
                                type="button"
                                className={cn('exc-range-preset', value.period === period.value && 'is-on')}
                                onClick={() => pick(period.value)}
                            >
                                {period.label}
                                {value.period === period.value && <i className="ti ti-check" aria-hidden="true" />}
                            </button>
                        ))}
                    </div>

                    <div className="exc-range-custom">
                        <p>Custom dates</p>
                        <div className="exc-range-pair">
                            <DatePicker
                                value={custom.from}
                                onChange={(from) => setCustom((current) => ({ ...current, from }))}
                                placeholder="From"
                                label="From"
                            />
                            <i className="ti ti-arrow-right" aria-hidden="true" />
                            <DatePicker
                                value={custom.to}
                                onChange={(to) => setCustom((current) => ({ ...current, to }))}
                                placeholder="To"
                                label="To"
                            />
                        </div>
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            disabled={!custom.from && !custom.to}
                            onClick={() => {
                                onChange({ period: 'custom', ...custom });
                                setOpen(false);
                            }}
                        >
                            Use these dates
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
