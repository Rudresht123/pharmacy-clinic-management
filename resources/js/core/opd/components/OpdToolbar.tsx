import type { ReactNode } from 'react';
import { DatePicker } from '@/shared/components/form/DatePicker';
import { today, type OpdContext } from '../useOpdContext';

/**
 * The day before or after, as a plain date string.
 *
 * Built from the parts rather than by adding milliseconds, so it cannot slide
 * an hour on the two days a year the clocks change.
 */
function shift(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const moved = new Date(year, month - 1, day + days);

    const mm = `${moved.getMonth() + 1}`.padStart(2, '0');
    const dd = `${moved.getDate()}`.padStart(2, '0');

    return `${moved.getFullYear()}-${mm}-${dd}`;
}

/**
 * Which branch, which day — the two questions both OPD screens ask.
 *
 * The branch control renders only when there is a choice to make. Somebody who
 * works at one place should not be shown a dropdown that can only ever say the
 * thing it already says.
 *
 * "Today" is a button rather than a date the user has to remember, because
 * getting back to now is the single most common navigation on this screen and
 * a date picker makes it three clicks.
 */
export function OpdToolbar({
    context,
    children,
    compact = false,
}: {
    context: OpdContext;
    children?: ReactNode;
    /**
     * Sits in the dashboard's own header rather than in a bar of its own.
     *
     * The labels go, because the header already establishes what the screen is
     * about and two more uppercase words above a date picker is noise there.
     * On the queue, where the bar IS the context, they stay.
     */
    compact?: boolean;
}) {
    const { branches, branchId, setBranch, date, setDate, isToday } = context;

    return (
        <div className={`opd-bar${compact ? ' is-compact' : ''}`}>
            {branches.length > 1 && (
                <label className="opd-bar-field">
                    <span>Branch</span>
                    <select
                        className="form-select"
                        value={branchId}
                        onChange={(event) => setBranch(Number(event.target.value))}
                    >
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}

            <div className="opd-bar-field">
                <span id="opd-day-label">Day</span>

                {/*
                    Arrows either side of the picker. "Yesterday" and "the day
                    before" are how anybody actually reads back through a
                    week, and a date picker makes each of those three clicks
                    and a mental date calculation.
                */}
                <div className="opd-bar-day" role="group" aria-labelledby="opd-day-label">
                    <button
                        type="button"
                        className="opd-bar-step"
                        aria-label="The day before"
                        onClick={() => setDate(shift(date, -1))}
                    >
                        <i className="ti ti-chevron-left" aria-hidden="true" />
                    </button>

                    <DatePicker id="opd-date" label="the day" value={date} onChange={setDate} />

                    <button
                        type="button"
                        className="opd-bar-step"
                        aria-label="The day after"
                        disabled={isToday}
                        onClick={() => setDate(shift(date, 1))}
                    >
                        <i className="ti ti-chevron-right" aria-hidden="true" />
                    </button>
                </div>
            </div>

            {!isToday && (
                <button type="button" className="opd-bar-today" onClick={() => setDate(today())}>
                    <i className="ti ti-arrow-back-up" aria-hidden="true" />
                    Back to today
                </button>
            )}

            {children}
        </div>
    );
}
