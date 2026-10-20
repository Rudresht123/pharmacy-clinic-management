/**
 * The date choices an export offers, and the from/to each one means.
 *
 * Most exports look back — this month's invoices, last month's visits.
 * Expiring stock looks forward, so it gets forward-looking choices instead;
 * which set applies follows from the export's own default.
 */

export interface Period {
    value: string;
    label: string;
}

const BACK: Period[] = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: 'month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'year', label: 'This year' },
    { value: 'all', label: 'All time' },
    { value: 'custom', label: 'Custom dates' },
];

const AHEAD: Period[] = [
    { value: 'next_30', label: 'Next 30 days' },
    { value: 'next_90', label: 'Next 90 days' },
    { value: 'next_180', label: 'Next 6 months' },
    { value: 'expired', label: 'Already expired' },
    { value: 'all', label: 'Any date' },
    { value: 'custom', label: 'Custom dates' },
];

export function periodsFor(defaultPeriod: string): Period[] {
    return defaultPeriod.startsWith('next_') ? AHEAD : BACK;
}

/** "2026-10-20", in local time — what the API reads. */
function iso(date: Date): string {
    return `${date.getFullYear()}-${`${date.getMonth() + 1}`.padStart(2, '0')}-${`${date.getDate()}`.padStart(2, '0')}`;
}

function shift(days: number): Date {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date;
}

/** The from/to a choice stands for; empty for no bound. */
export function rangeOf(period: string, custom: { from: string; to: string }): { from: string; to: string } {
    const today = new Date();

    switch (period) {
        case 'today':
            return { from: iso(today), to: iso(today) };
        case '7d':
            return { from: iso(shift(-6)), to: iso(today) };
        case '30d':
            return { from: iso(shift(-29)), to: iso(today) };
        case 'month':
            return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) };
        case 'last_month':
            return {
                from: iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
                to: iso(new Date(today.getFullYear(), today.getMonth(), 0)),
            };
        case 'year':
            return { from: iso(new Date(today.getFullYear(), 0, 1)), to: iso(today) };
        case 'next_30':
            return { from: iso(today), to: iso(shift(30)) };
        case 'next_90':
            return { from: iso(today), to: iso(shift(90)) };
        case 'next_180':
            return { from: iso(today), to: iso(shift(182)) };
        case 'expired':
            return { from: '', to: iso(shift(-1)) };
        case 'custom':
            return custom;
        default:
            return { from: '', to: '' };
    }
}
